<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TenantNotResolved;
use App\Models\Customer;
use App\Models\Tenant;
use App\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas de las garantias de aislamiento entre tenants.
 *
 * Este es el test mas importante de la etapa. El resto de la funcionalidad se
 * puede reconstruir; una fuga entre comercios no, porque el cliente final es una
 * cafeteria que compite con otra del mismo tenant group y el daño es legal.
 *
 * Las garantias se prueban en las DOS capas, porque cada una falla de forma
 * distinta:
 *   - El scope de Eloquent atrapa el error de aplicacion.
 *   - RLS atrapa el error que llega a la base (raw queries, joins mal hechos,
 *     un `withoutGlobalScope()` accidental, o el futuro servicio de reportes).
 */
class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    #[Test]
    public function sin_contexto_de_tenant_el_scope_de_eloquent_falla_de_forma_explicita(): void
    {
        Tenant::factory()->create();
        TenantContext::forget();

        // No se espera cero filas: se espera un fallo ruidoso. Un forgot de
        // isolation debe detectarse en staging, no descubrirse en produccion.
        $this->expectException(TenantNotResolved::class);

        Customer::query()->get();
    }

    #[Test]
    public function rls_bloquea_la_lectura_cuando_no_hay_contexto_de_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->asTenant($tenant, fn () => Customer::factory()->create());

        TenantContext::forget();
        DB::statement('SELECT set_config(?, ?, false)', ['app.current_tenant', '']);

        // Se consulta por la conexion cruda para saltarse el scope de Eloquent y
        // comprobar que la segunda capa tambien cierra la puerta.
        $rows = DB::table('customers')->get();

        $this->assertCount(0, $rows, 'RLS debe devolver cero filas sin tenant en contexto.');
    }

    #[Test]
    public function un_tenant_no_ve_los_clientes_de_otro_tenant(): void
    {
        $aurora = $this->makeTenant(['name' => 'Cafe Aurora']);
        $barista = $this->makeTenant(['name' => 'Cafe Barista']);

        $this->asTenant($aurora, fn () => Customer::factory()->count(5)->create());
        $this->asTenant($barista, fn () => Customer::factory()->count(3)->create());

        $vistosPorAurora = $this->asTenant($aurora, fn () => Customer::query()->count());
        $vistosPorBarista = $this->asTenant($barista, fn () => Customer::query()->count());

        $this->assertSame(5, $vistosPorAurora);
        $this->assertSame(3, $vistosPorBarista);
    }

    #[Test]
    public function tenant_id_no_es_asignable_en_masa(): void
    {
        $tenant = $this->makeTenant();

        $cliente = $this->asTenant($tenant, fn () => Customer::query()->create([
            'tenant_id' => 999,
            'full_name' => 'Intento de mass-assignment',
        ]));

        // `tenant_id` no esta en $fillable y $guarded es aditivo: queda fuera.
        // Esto importa porque los endpoints construyen modelos con create($request->all())
        // y sin esta proteccion, un POST con tenant_id ajena moveria saldo entre
        // comercios.
        $this->assertNotSame(999, $cliente->tenant_id);
        $this->assertSame($tenant->id, $cliente->tenant_id);
    }

    #[Test]
    public function el_saldo_no_es_asignable_en_masa(): void
    {
        $tenant = $this->makeTenant();

        $cliente = $this->asTenant($tenant, fn () => Customer::query()->create([
            'full_name' => 'Cliente legitimo',
            'current_points' => 999_999,
            'lifetime_points_earned' => 999_999,
        ]));

        // El saldo solo cambia por el ledger. Si esto fallara, cualquier endpoint
        // que haga mass-assignment podria fabricar saldo desde cero.
        //
        // Se relee desde la base y no del objeto devuelto: el atributo fue
        // descartado, asi que el valor observado viene del DEFAULT de la columna.
        $persistido = $this->asTenant($tenant, fn () => Customer::query()->findOrFail($cliente->id));

        $this->assertSame(0, $persistido->current_points);
        $this->assertSame(0, $persistido->lifetime_points_earned);
    }

    #[Test]
    public function rls_bloquea_la_insercion_de_una_fila_de_otro_tenant(): void
    {
        $aurora = $this->makeTenant(['name' => 'Cafe Aurora']);
        $barista = $this->makeTenant(['name' => 'Cafe Barista']);

        $this->expectException(QueryException::class);

        $this->asTenant($aurora, function () use ($barista): void {
            // Vector de ataque realista: no es mass-assignment (bloqueado por
            // $fillable), sino codigo interno que fija la propiedad y llama a save().
            // El scope de Eloquent lo permitiria, porque no conoce la intencion.
            // Solo la politica WITH CHECK de RLS puede detenerlo.
            $intruso = new Customer(['full_name' => 'Intruso']);
            $intruso->tenant_id = $barista->id;
            $intruso->save();
        });
    }

    #[Test]
    public function el_contexto_se_limpia_al_terminar_run_incluso_si_el_callback_falla(): void
    {
        $tenant = $this->makeTenant();

        try {
            $this->asTenant($tenant, function (): void {
                throw new \RuntimeException('fallo controlado');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertNull(TenantContext::id(), 'El contexto debe quedar limpio tras una excepcion.');
        $this->assertNull($this->currentDatabaseTenant(), 'El GUC tambien debe quedar limpio.');
    }

    #[Test]
    public function el_contexto_anterior_se_restaura_tras_un_run_anidado(): void
    {
        $aurora = $this->makeTenant(['name' => 'Cafe Aurora']);
        $barista = $this->makeTenant(['name' => 'Cafe Barista']);

        $this->asTenant($aurora, function () use ($aurora, $barista): void {
            $this->assertSame($aurora->id, TenantContext::id());

            $this->asTenant($barista, function () use ($barista): void {
                $this->assertSame($barista->id, TenantContext::id());
                $this->assertSame($barista->id, $this->currentDatabaseTenant());
            });

            $this->assertSame($aurora->id, TenantContext::id(), 'Debe volver al tenant exterior.');
            $this->assertSame($aurora->id, $this->currentDatabaseTenant());
        });
    }

    #[Test]
    public function el_tenant_se_asigna_automaticamente_al_crear_un_modelo_de_dominio(): void
    {
        $tenant = $this->makeTenant();

        $cliente = $this->asTenant($tenant, fn () => Customer::factory()->create([
            'full_name' => 'Cliente sin tenant explicito',
        ]));

        $this->assertSame($tenant->id, $cliente->tenant_id);
    }

    #[Test]
    public function las_tablas_de_tenant_tienen_rls_habilitado_y_forzado(): void
    {
        $tablas = [
            'users', 'loyalty_configs', 'customers', 'rewards', 'qr_tokens',
            'transactions', 'point_ledger', 'redemption_codes', 'wallet_passes',
            'idempotency_keys', 'tenant_domains', 'webhook_events', 'wallet_sync_logs',
        ];

        $filas = DB::select(
            'SELECT relname, relrowsecurity AS enabled, relforcerowsecurity AS forced
             FROM pg_class WHERE relname = ANY(?)',
            ['{'.implode(',', $tablas).'}'],
        );

        $this->assertCount(count($tablas), $filas, 'Todas las tablas listadas deben existir.');

        foreach ($filas as $fila) {
            $this->assertTrue((bool) $fila->enabled, "RLS debe estar habilitado en {$fila->relname}.");
            $this->assertTrue(
                (bool) $fila->forced,
                "RLS debe estar FORZADO en {$fila->relname}; sin FORCE el propietario de la tabla lo ignora.",
            );
        }
    }
}
