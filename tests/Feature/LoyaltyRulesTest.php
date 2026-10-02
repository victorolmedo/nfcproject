<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\PointLedger;
use App\Models\Tenant;
use App\Services\Loyalty\Exceptions\UnsupportedCurrency;
use App\Services\Loyalty\LoyaltyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas del orquestador de reglas, contra PostgreSQL real.
 *
 * Aqui se prueba lo que el calculo puro no puede cubrir: la resolucion de la
 * configuracion vigente y, sobre todo, la ventana del dia local del cliente. Esa
 * ventana es donde se esconden los errores de los topes diarios, porque dependen
 * de la zona horaria, de la hora del reloj y de los cambios de horario.
 *
 * Todas las escrituras van dentro de `asTenant()` porque RLS esta forzado y sin el
 * GUC de contexto PostgreSQL rechaza los INSERT. No es un detalle del test: es la
 * garantia que en produccion impide que un comercio escriba en el ledger de otro.
 */
final class LoyaltyRulesTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
    }

    protected function tearDown(): void
    {
        /*
         * `Carbon::setTestNow()` congela el reloj global. Si un test falla antes de
         * llegar a su `setTestNow()`, el reloj se queda congelado para los tests
         * siguientes y los fallos se propagan de forma fantasma.
         */
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Ejecuta el cuerpo del test con contexto de tenant activo.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function tenanted(\Closure $callback): mixed
    {
        return $this->asTenant($this->tenant, $callback);
    }

    private function rules(): LoyaltyRules
    {
        return app(LoyaltyRules::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): LoyaltyConfig
    {
        return LoyaltyConfig::create([
            'name' => 'Programa',
            'rule_type' => LoyaltyRuleType::Amount,
            'points_per_minor_unit' => '0.001',   // 1 punto por $10
            'points_per_visit' => 0,
            'min_purchase_minor' => 0,
            'currency' => 'MXN',
            'max_points_per_transaction' => null,
            'max_points_per_day' => null,
            'rounding' => PointsRounding::Down,
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            ...$overrides,
        ]);
    }

    public function test_otorga_puntos_con_la_configuracion_vigente(): void
    {
        $this->tenanted(function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $award = $this->rules()->awardFor($customer, 10_000, 'MXN');

            $this->assertSame(10, $award->points);
        });
    }

    public function test_un_tenant_sin_programa_vende_normalmente_sin_puntos(): void
    {
        $this->tenanted(function (): void {
            $customer = Customer::factory()->create();

            $award = $this->rules()->awardFor($customer, 10_000, 'MXN');

            $this->assertSame(0, $award->points);
            $this->assertSame('no_loyalty_configured', $award->notEarnedReason);
        });
    }

    public function test_un_programa_apagado_no_acredita(): void
    {
        $this->tenanted(function (): void {
            $this->config(['is_active' => false]);
            $customer = Customer::factory()->create();

            $this->assertSame(0, $this->rules()->awardFor($customer, 10_000, 'MXN')->points);
        });
    }

    public function test_un_programa_apagado_no_bloquea_por_la_moneda(): void
    {
        /*
         * Sin puntos en juego no hay nada que proteger: el cajero debe poder cobrar
         * en la moneda que sea. Si esto lanzara, una suspension temporal del
         * programa dejaria al comercio sin poder vender en otra moneda.
         */
        $this->tenanted(function (): void {
            $this->config(['is_active' => false]);
            $customer = Customer::factory()->create();

            $award = $this->rules()->awardFor($customer, 10_000, 'USD');

            $this->assertSame(0, $award->points);
            $this->assertSame('rule_not_effective', $award->notEarnedReason);
        });
    }

    public function test_un_programa_vivo_rechaza_una_moneda_distinta(): void
    {
        $this->tenanted(function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $this->expectException(UnsupportedCurrency::class);

            $this->rules()->awardFor($customer, 10_000, 'USD');
        });
    }

    public function test_el_tope_diario_cuenta_lo_ganado_hoy(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            // El cliente ya gano 70 puntos hoy, segun su propio ledger.
            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 70,
                'recorded_at' => now(),
            ]);

            // Compra de $600.00, que generaria 60 puntos, pero solo quedan 30 de margen.
            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            $this->assertSame(30, $award->points);
            $this->assertSame('max_points_per_day', $award->cappedBy);
        });
    }

    public function test_canjear_no_devuelve_la_cuota_diaria(): void
    {
        /*
         * Es la defensa contra el ciclo de bypass: ganar 100, canjearlos, y volver
         * a ganar 100 en el mismo dia. Si el canje liberara cuota, un cliente podria
         * acumular ilimitadamente en un solo dia.
         */
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 100,
                'recorded_at' => now(),
            ]);

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Redeem,
                'points' => -100,
                'recorded_at' => now(),
            ]);

            // El saldo vuelve a cero, pero la cuota del dia sigue consumida.
            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            $this->assertSame(0, $award->points);
        });
    }

    public function test_anular_una_compra_devuelve_la_cuota_consumida(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 100,
                'recorded_at' => now(),
            ]);

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Reverse,
                'points' => -100,
                'recorded_at' => now(),
            ]);

            // Cuota liberada: vuelven a estar disponibles los 100.
            $this->assertSame(60, $this->rules()->awardFor($customer, 60_000, 'MXN')->points);
        });
    }

    public function test_lo_ganado_ayer_no_cuenta_hoy(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 100,
                'recorded_at' => now()->subDay(),
            ]);

            // Ayer consumio la cuota entera; hoy vuelve a estar disponible.
            $this->assertSame(60, $this->rules()->awardFor($customer, 60_000, 'MXN')->points);
        });
    }

    public function test_el_corte_del_dia_es_en_la_zona_del_cliente_no_en_utc(): void
    {
        /*
         * Mexico City es UTC-6 todo el ano. A las 21:00 hora local ya son las 03:00
         * del dia siguiente en UTC. Si el corte fuera en UTC, una compra de la
         * tarde noche caeria en el dia siguiente y el cliente empezaria con el
         * margen del dia anterior ya consumido.
         */
        $this->tenanted(function (): void {
            /*
             * El reloj se congela ANTES de crear la configuracion. Si se creara
             * primero, `effective_from` quedaria fechado en el "ahora" real (octubre)
             * y al congelar el reloj en junio el programa apareceria como todavia no
             * vigente, que no es lo que este test quiere comprobar.
             */
            Carbon::setTestNow(Carbon::parse('2026-06-15 21:00:00', 'America/Mexico_City'));

            $this->config(['max_points_per_day' => 100]);

            $customer = Customer::factory()->create(['timezone' => 'America/Mexico_City']);

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 80,
                'recorded_at' => Carbon::now('UTC'),
            ]);

            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            // Son las 21:00 en Mexico: mismo dia local, asi que quedan 20 de margen.
            $this->assertSame(20, $award->points);
            $this->assertSame('max_points_per_day', $award->cappedBy);
        });
    }

    public function test_la_primera_compra_del_dia_no_se_cuenta_dos_veces(): void
    {
        /*
         * El limite superior de la ventana es exclusivo. Una compra hecha
         * exactamente a medianoche pertenece a un solo dia; con un cierre inclusivo
         * entraria en los dos dias y consumiria el doble del tope.
         */
        $this->tenanted(function (): void {
            // Reloj congelado antes de crear la configuracion, por el mismo motivo
            // que en el test anterior: si no, `effective_from` queda en el futuro.
            Carbon::setTestNow(Carbon::parse('2026-06-16 00:00:00', 'America/Mexico_City'));

            $this->config(['max_points_per_day' => 100]);

            $customer = Customer::factory()->create(['timezone' => 'America/Mexico_City']);

            // Medianoche en Mexico del dia 16.
            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 50,
                'recorded_at' => Carbon::now('UTC'),
            ]);

            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            // Cuenta una sola vez: quedan 100 - 50 = 50 de margen y se piden 60.
            $this->assertSame(50, $award->points);
        });
    }

    public function test_una_zona_horaria_corrupta_no_impide_la_venta(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);

            // Datos invalidos en la base no pueden romper un cobro.
            $customer = Customer::factory()->create(['timezone' => 'NoExiste/EstaZona']);

            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            $this->assertSame(60, $award->points);
        });
    }

    public function test_el_tope_diario_no_mezcla_clientes(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);

            $a = Customer::factory()->create();
            $b = Customer::factory()->create();

            PointLedger::factory()->for($a)->create([
                'entry_type' => LedgerEntryType::Earn,
                'points' => 100,
                'recorded_at' => now(),
            ]);

            // El cliente B no hereda el consumo del cliente A.
            $this->assertSame(60, $this->rules()->awardFor($b, 60_000, 'MXN')->points);
        });
    }

    public function test_un_ajuste_manual_positivo_consume_cuota(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Adjust,
                'points' => 50,
                'recorded_at' => now(),
            ]);

            // Tope 100, ya acreditados 50 => quedan 50 de margen. La compra de
            // $600.00 pediria 60, asi que se recorta a 50.
            $award = $this->rules()->awardFor($customer, 60_000, 'MXN');

            $this->assertSame(50, $award->points);
            $this->assertSame('max_points_per_day', $award->cappedBy);
        });
    }

    public function test_la_expiracion_no_consume_cuota_diaria(): void
    {
        $this->tenanted(function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            PointLedger::factory()->for($customer)->create([
                'entry_type' => LedgerEntryType::Expire,
                'points' => -40,
                'recorded_at' => now(),
            ]);

            // Los puntos que caducan se ganaron antes; no cuentan para hoy.
            $this->assertSame(60, $this->rules()->awardFor($customer, 60_000, 'MXN')->points);
        });
    }
}
