<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\PointLedger;
use App\Models\Reward;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Loyalty\Exceptions\CustomerNotEligible;
use App\Services\Loyalty\Exceptions\InsufficientPoints;
use App\Services\Loyalty\Exceptions\UnsupportedCurrency;
use App\Services\Loyalty\PointsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas del earn/redeem sobre el ledger.
 *
 * Cada test comprueba tres cosas a la vez, porque en contabilidad las tres son la
 * misma: la cabecera dice lo que paso, el asiento lo refleja, y el saldo
 * materializado cuadra con el ledger. Un test que solo mirara el saldo del cliente
 * pasaria con un ledger vacio, que es exactamente el fallo que nadie detecta
 * hasta que hay una conciliacion que no cuadra.
 */
final class PointsServiceTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
    }

    private function points(): PointsService
    {
        return app(PointsService::class);
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

    public function test_una_compra_acredita_puntos_en_cabecera_asiento_y_saldo(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            // $100.00 => 10 puntos.
            $transaction = $this->points()->earn(
                $customer->id,
                10_000,
                'MXN',
                'key-earn-1',
                externalRef: 'TICKET-1',
            );

            $this->assertSame(TransactionType::Earn, $transaction->type);
            $this->assertSame(TransactionStatus::Posted, $transaction->status);
            $this->assertSame(10, $transaction->points_delta);
            $this->assertSame(10, $transaction->points_balance_after);

            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertSame(LedgerEntryType::Earn, $entry->entry_type);
            $this->assertSame(10, $entry->points);
            $this->assertSame(10, $entry->balance_after);
            $this->assertSame('external_ref', $entry->ref_type);
            $this->assertSame('TICKET-1', $entry->ref_id);

            $customer->refresh();

            $this->assertSame(10, $customer->current_points);
            $this->assertSame(10, $customer->lifetime_points_earned);
        });
    }

    public function test_una_compra_sin_puntos_deja_cabecera_pero_no_asiento(): void
    {
        /*
         * `point_ledger` tiene `CHECK (points <> 0)`, asi que un delta cero no puede
         * generar asiento. La cabecera si se escribe: es la evidencia de que el
         * cajero escaneo y de que no hubo puntos, con el motivo guardado.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config(['min_purchase_minor' => 50_000]);   // minimo $500
            $customer = Customer::factory()->create();

            $transaction = $this->points()->earn($customer->id, 4_500, 'MXN', 'key-earn-bajo');

            $this->assertSame(0, $transaction->points_delta);
            $this->assertSame(TransactionStatus::Posted, $transaction->status);

            $this->assertSame(0, PointLedger::query()->count());

            $this->assertSame('below_minimum_purchase', $transaction->metadata['not_earned_reason']);

            $customer->refresh();

            $this->assertSame(0, $customer->current_points);
        });
    }

    public function test_reintentar_con_la_misma_clave_no_acredita_dos_veces(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $first = $this->points()->earn($customer->id, 10_000, 'MXN', 'key-idem');
            $second = $this->points()->earn($customer->id, 10_000, 'MXN', 'key-idem');

            $this->assertSame($first->id, $second->id, 'Debe devolver la transaccion original.');
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(1, PointLedger::query()->count());

            $customer->refresh();

            $this->assertSame(10, $customer->current_points, 'Un reintento no puede duplicar el saldo.');
        });
    }

    public function test_dos_tickets_distintos_crean_dos_asientos(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $this->points()->earn($customer->id, 10_000, 'MXN', 'key-a', externalRef: 'TICKET-A');
            $this->points()->earn($customer->id, 20_000, 'MXN', 'key-b', externalRef: 'TICKET-B');

            $this->assertSame(2, PointLedger::query()->count());

            $customer->refresh();

            $this->assertSame(30, $customer->current_points);
        });
    }

    public function test_el_indice_unico_impide_que_dos_cabeceras_crediten_el_mismo_ticket(): void
    {
        /*
         * El indice `point_ledger_ref_unique` sobre (tenant_id, ref_type, ref_id) es
         * la garantia final contra doble acreditacion del mismo hecho de negocio.
         * Se comprueba a nivel de base, saltandose el servicio, porque lo que se
         * protege es precisamente el caso en que el servicio se Equivoca o se
         * ejecuta dos veces.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $transaction = $this->points()->earn($customer->id, 10_000, 'MXN', 'key-t', externalRef: 'TICKET-X');

            $this->expectException(QueryException::class);

            // Segundo asiento con la MISMA referencia: la base lo debe impedir.
            PointLedger::query()->create([
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'entry_type' => LedgerEntryType::Earn,
                'points' => 10,
                'balance_after' => 20,
                'ref_type' => 'external_ref',
                'ref_id' => 'TICKET-X',
                'recorded_at' => now(),
            ]);
        });
    }

    public function test_un_canje_resta_puntos_y_deja_el_asiento_en_negativo(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(100)->create();
            $reward = Reward::factory()->create(['points_required' => 40]);

            $transaction = $this->points()->redeem($customer->id, $reward, 'key-redeem-1');

            $this->assertSame(TransactionType::Redeem, $transaction->type);
            $this->assertSame(-40, $transaction->points_delta);
            $this->assertSame(60, $transaction->points_balance_after);

            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertSame(LedgerEntryType::Redeem, $entry->entry_type);
            $this->assertSame(-40, $entry->points);

            $customer->refresh();

            $this->assertSame(60, $customer->current_points);

            /*
             * El canje NO puede contar como puntos ganados. `withPoints` siembra un
             * saldo inicial sin pasar por el ledger, asi que `lifetime_points_earned`
             * arranca en 0: lo relevante es que el canje lo deja intacto y suma solo
             * en el contador de canjes.
             */
            $this->assertSame(0, $customer->lifetime_points_earned);
            $this->assertSame(40, $customer->lifetime_points_redeemed);
        });
    }

    public function test_canjear_sin_saldo_se_rechaza_y_no_deja_rastro(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(10)->create();
            $reward = Reward::factory()->create(['points_required' => 100]);

            try {
                $this->points()->redeem($customer->id, $reward, 'key-redeem-fallido');

                $this->fail('El canje deberia haberse rechazado por saldo insuficiente.');
            } catch (InsufficientPoints $exception) {
                $this->assertSame(10, $exception->errorDetails()['available_points']);
                $this->assertSame(100, $exception->errorDetails()['required_points']);
                $this->assertSame(90, $exception->errorDetails()['missing_points']);
            }

            // Nada se escribio: ni cabecera, ni asiento, ni cambio de saldo.
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, PointLedger::query()->count());

            $customer->refresh();

            $this->assertSame(10, $customer->current_points);
        });
    }

    public function test_el_saldo_negativo_solo_se_permite_si_el_programa_lo_autoriza(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config(['allow_negative_balance' => true]);
            $customer = Customer::factory()->withPoints(10)->create();
            $reward = Reward::factory()->create(['points_required' => 100]);

            $transaction = $this->points()->redeem($customer->id, $reward, 'key-negativo');

            $this->assertSame(-90, $transaction->points_balance_after);

            $customer->refresh();

            $this->assertSame(-90, $customer->current_points);
        });
    }

    public function test_un_canje_con_saldo_negativo_no_se_permite_por_defecto(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();   // allow_negative_balance = false
            $customer = Customer::factory()->withPoints(10)->create();
            $reward = Reward::factory()->create(['points_required' => 100]);

            $this->expectException(InsufficientPoints::class);

            $this->points()->redeem($customer->id, $reward, 'key-negativo-prohibido');
        });
    }

    public function test_un_cliente_suspendido_no_puede_acumular_ni_canjear(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->suspended()->withPoints(500)->create();

            try {
                $this->points()->earn($customer->id, 10_000, 'MXN', 'key-susp-earn');

                $this->fail('Un cliente suspendido no deberia acumular.');
            } catch (CustomerNotEligible) {
                $this->assertTrue(true);
            }

            $reward = Reward::factory()->create(['points_required' => 100]);

            $this->expectException(CustomerNotEligible::class);

            $this->points()->redeem($customer->id, $reward, 'key-susp-redeem');
        });
    }

    public function test_una_moneda_distinta_se_rechaza_sin_escribir_nada(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            try {
                $this->points()->earn($customer->id, 10_000, 'USD', 'key-moneda');

                $this->fail('Deberia haberse rechazado la moneda.');
            } catch (UnsupportedCurrency) {
                $this->assertTrue(true);
            }

            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, PointLedger::query()->count());
        });
    }

    public function test_el_tope_diario_se_refleja_en_el_asiento_y_lo_razona(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            // Generaria 60, se acreditan 60.
            $this->points()->earn($customer->id, 60_000, 'MXN', 'key-dia-1');

            // Generaria 60 mas, pero solo quedan 40.
            $transaction = $this->points()->earn($customer->id, 60_000, 'MXN', 'key-dia-2');

            $this->assertSame(40, $transaction->points_delta);
            $this->assertSame('max_points_per_day', $transaction->metadata['capped_by']);
            $this->assertSame(60, $transaction->metadata['theoretical_points']);

            $customer->refresh();

            $this->assertSame(100, $customer->current_points);
        });
    }

    public function test_las_marcas_de_tiempo_se_guardan_en_el_instante_real(): void
    {
        /*
         * Guardia del desfase de zona horaria.
         *
         * Cuando `database.connections.pgsql.timezone` vino en `NULL`, la sesion
         * heredaba la zona -03 del servidor de PostgreSQL. Como Laravel escribe las
         * columnas `timestampTz` sin offset, cada instante se guardaba TRES HORAS por
         * delante: aqui `recorded_at` habria sido `2026-06-15 16:30` en vez de
         * `2026-06-15 13:30`.
         *
         * El reloj esta congelado para que la comparacion sea exacta y no dependa del
         * instante en que corra el test.
         */
        $instante = CarbonImmutable::parse('2026-06-15T13:30:00Z');

        CarbonImmutable::setTestNow($instante);

        $this->asTenant($this->tenant, function () use ($instante): void {
            $this->config();
            $customer = Customer::factory()->create();

            $transaction = $this->points()->earn($customer->id, 10_000, 'MXN', 'key-tz');

            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertSame(
                $instante->toIso8601String(),
                $entry->recorded_at->toIso8601String(),
                'El asiento debe llevar el instante real de la operacion.',
            );

            $this->assertSame(
                $instante->toIso8601String(),
                $transaction->occurred_at->toIso8601String(),
            );

            /*
             * Y el servidor debe estar efectivamente en UTC, no solo por
             * configuracion. `show timezone` devuelve la cadena ('+00:00' o '-03'),
             * poco comoda de comparar, asi que se consulta el desplazamiento real:
             * 0 significa UTC y -3 el estado defectuoso que hubo.
             */
            $offset = DB::selectOne('select extract(timezone from now()) as offset')->offset;

            $this->assertSame(0.0, (float) $offset, 'La sesion de PostgreSQL debe trabajar en UTC.');
        });
    }

    public function test_el_tope_diario_cruza_la_medianoche_segun_la_hora_del_cliente(): void
    {
        /*
         * Este es el dano real del desfase: la cuota diaria se define por el dia
         * local del cliente, asi que un error de tres horas cambia de dia a un
         * cliente que compra cerca de medianoche.
         *
         * `America/Mexico_City` es UTC-6. A las 23:30 local son las 05:30 UTC del dia
         * siguiente: si las marcas de tiempo se guardaran corridas, esta compra caeria
         * en la ventana del dia que viene y consumiria cuota que todavia no toca.
         */
        $this->asTenant($this->tenant, function (): void {
            /*
             * El reloj se congela ANTES de crear la configuracion. Al reves, la
             * vigencia se calcularia con el reloj real y al retroceder a junio la
             * regla ya no estaria en vigor (`rule_not_effective`).
             */
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15T23:30:00', 'America/Mexico_City'));

            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create(['timezone' => 'America/Mexico_City']);

            // 23:30 hora de Mexico City => 05:30 UTC del dia siguiente.
            $primera = $this->points()->earn($customer->id, 60_000, 'MXN', 'key-medianoche-1');

            $this->assertSame(60, $primera->points_delta);

            // Misma noche local, misma ventana: la cuota se agota aqui.
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15T23:55:00', 'America/Mexico_City'));

            $segunda = $this->points()->earn($customer->id, 60_000, 'MXN', 'key-medianoche-2');

            $this->assertSame(40, $segunda->points_delta, 'Ambas compras comparten el dia local.');
            $this->assertSame('max_points_per_day', $segunda->metadata['capped_by']);

            // 00:10 del dia local siguiente: ventana nueva, cuota nueva.
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-16T00:10:00', 'America/Mexico_City'));

            $tercera = $this->points()->earn($customer->id, 60_000, 'MXN', 'key-medianoche-3');

            $this->assertSame(60, $tercera->points_delta, 'Un nuevo dia local reinicia la cuota.');
            $this->assertNull($tercera->metadata['capped_by'] ?? null);

            $customer->refresh();

            $this->assertSame(160, $customer->current_points);
        });
    }

    public function test_la_transaccion_guarda_por_que_se_acredito_esos_puntos(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config(['max_points_per_transaction' => 5]);
            $customer = Customer::factory()->create();

            $transaction = $this->points()->earn($customer->id, 100_000, 'MXN', 'key-meta');

            $this->assertSame(5, $transaction->points_delta);
            $this->assertSame(100, $transaction->metadata['theoretical_points']);
            $this->assertSame('max_points_per_transaction', $transaction->metadata['capped_by']);

            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertStringContainsString('max_points_per_transaction', (string) $entry->reason);
        });
    }

    public function test_una_transaccion_no_contabilizada_se_puede_registrar_y_anular(): void
    {
        /*
         * El CHECK `transactions_posted_consistency` exige `points_balance_after`
         * NULL cuando el estado no es posted. Antes de la migracion que hizo la
         * columna nullable, esta operacion era imposible: el CHECK y el NOT NULL
         * eran contradictorios.
         */
        $this->asTenant($this->tenant, function (): void {
            $transaction = new Transaction([
                'customer_id' => Customer::factory()->create()->id,
                'type' => TransactionType::Earn,
                'source' => TransactionSource::PosScanClient,
                'currency' => 'MXN',
                'idempotency_key' => 'key-pendiente',
                'occurred_at' => now(),
                'metadata' => [],
            ]);

            $transaction->markUnposted(TransactionStatus::Pending, 'Pendiente de confirmar con el cajero');
            $transaction->save();

            $this->assertNull($transaction->points_balance_after);

            $transaction->markUnposted(TransactionStatus::Voided, 'Cobro anulado');
            $transaction->save();

            $this->assertSame(TransactionStatus::Voided, $transaction->fresh()->status);
        });
    }

    public function test_posted_no_se_puede_alcanzar_sin_fijar_el_saldo(): void
    {
        $transaction = new Transaction([
            'type' => TransactionType::Earn,
            'currency' => 'MXN',
            'occurred_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        $transaction->markUnposted(TransactionStatus::Posted);
    }

    public function test_el_saldo_materializado_siempre_coincide_con_la_suma_del_ledger(): void
    {
        /*
         * Invariante central del paso 5. Se mezcla todo a proposito: varias
         * acumulaciones, un canje y un tope diario, y al final el saldo del cliente
         * tiene que ser exactamente la suma de los asientos.
         *
         * Si esto se rompe, la contabilidad esta descuadrada y no hay forma de saber
         * cuanto de dinero le pertenece al cliente.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config(['max_points_per_day' => 100]);
            $customer = Customer::factory()->create();

            $this->points()->earn($customer->id, 60_000, 'MXN', 'inv-1');
            $this->points()->earn($customer->id, 60_000, 'MXN', 'inv-2');   // recortado a 40
            $this->points()->earn($customer->id, 30_000, 'MXN', 'inv-3');   // recortado a 0

            $reward = Reward::factory()->create(['points_required' => 30]);
            $this->points()->redeem($customer->id, $reward, 'inv-4');

            $ledgerSum = (int) PointLedger::query()
                ->where('customer_id', $customer->id)
                ->sum('points');

            $customer->refresh();

            // 60 del primer cobro, 40 del segundo (recortado por el tope diario) y
            // -30 del canje. El tercer cobro se accredito en 0 y no dejo asiento.
            $this->assertSame(70, $ledgerSum);
            $this->assertSame($ledgerSum, $customer->current_points);

            // El ultimo saldo del ledger debe coincidir con el saldo actual.
            $lastEntry = PointLedger::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->firstOrFail();

            $this->assertSame($customer->current_points, $lastEntry->balance_after);

            // Y el saldo de la cabecera mas reciente tambien.
            $lastTransaction = Transaction::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->firstOrFail();

            $this->assertSame($customer->current_points, $lastTransaction->points_balance_after);
        });
    }

    public function test_cada_transaccion_posted_tiene_exactamente_un_asiento(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();
            $reward = Reward::factory()->create(['points_required' => 10]);

            $this->points()->earn($customer->id, 10_000, 'MXN', 'par-1');
            $this->points()->redeem($customer->id, $reward, 'par-2');

            foreach (Transaction::query()->where('customer_id', $customer->id)->get() as $transaction) {
                $this->assertSame(
                    1,
                    PointLedger::query()->where('transaction_id', $transaction->id)->count(),
                    "La transaccion {$transaction->id} deberia tener exactamente un asiento.",
                );
            }
        });
    }

    public function test_el_delta_de_la_cabecera_coincide_con_el_del_asiento(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();
            $reward = Reward::factory()->create(['points_required' => 25]);

            $this->points()->earn($customer->id, 77_000, 'MXN', 'delta-1');
            $this->points()->redeem($customer->id, $reward, 'delta-2');

            $headers = Transaction::query()->where('customer_id', $customer->id)->get();

            foreach ($headers as $header) {
                $entry = PointLedger::query()->where('transaction_id', $header->id)->sole();

                $this->assertSame(
                    $header->points_delta,
                    $entry->points,
                    'Cabecera y asiento deben contar lo mismo.',
                );
                $this->assertSame($header->points_balance_after, $entry->balance_after);
            }
        });
    }

    public function test_el_asiento_apunta_a_su_transaccion(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->create();

            $transaction = $this->points()->earn($customer->id, 10_000, 'MXN', 'enlace');

            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertTrue($entry->transaction->is($transaction));
            $this->assertTrue($transaction->ledgerEntry->is($entry));
        });
    }
}
