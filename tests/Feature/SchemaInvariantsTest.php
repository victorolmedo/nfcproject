<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\PointLedger;
use App\Models\QrToken;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\WalletPass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas de las garantias del esquema.
 *
 * Estas restricciones no son defensa de profundidad: son la PRIMERA linea. El
 * motor las aplica aunque el codigo de aplicacion este mal, porque el codigo de
 * aplicacion va a fallar en produccion en algun momento.
 *
 * Un bug de redondeo que acredite el doble, o un job reintentado tres veces, no
 * pueden producir dos asientos para el mismo hecho de negocio. Eso se decide
 * aca, con un indice unico, no con un `if` en un service.
 */
class SchemaInvariantsTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    #[Test]
    public function el_ledger_rechaza_dos_asientos_para_la_misma_referencia(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            $asiento = [
                'customer_id' => $cliente->id,
                'entry_type' => LedgerEntryType::Earn->value,
                'points' => 50,
                'balance_after' => 50,
                'ref_type' => 'qr_token',
                'ref_id' => 'un-token-especifico',
                'recorded_at' => now(),
            ];

            PointLedger::query()->create($asiento);

            // Mismo hecho de negocio, segundo intento (job reintentado, doble
            // toque del cajero, replay de un webhook).
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/point_ledger_ref_unique/');

            PointLedger::query()->create($asiento);
        });
    }

    #[Test]
    public function el_ledger_rechaza_un_asiento_de_puntos_cero(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            $this->expectException(QueryException::class);

            PointLedger::query()->create([
                'customer_id' => $cliente->id,
                'entry_type' => LedgerEntryType::Earn->value,
                'points' => 0,
                'balance_after' => 0,
                'ref_type' => 'manual',
                'ref_id' => 'ajuste-vacio',
                'recorded_at' => now(),
            ]);
        });
    }

    #[Test]
    public function una_transaccion_no_referencia_mas_de_un_token_de_qr(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();
            $token = QrToken::factory()->create();

            $primera = $this->makePostedTransaction($cliente, $token);

            // Barrera fisica: un token consumido no puede generar dos EARN, aunque
            // el claim atomico falle. `points_delta` se asigna por propiedad
            // porque esta en $guarded: el mass-assignment no puede tocarlo.
            $primera->points_delta = 10;
            $primera->points_balance_after = 10;
            $primera->save();

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/transactions_qr_token_id_unique/');

            $segunda = $this->makePostedTransaction($cliente, $token);
            $segunda->points_delta = 10;
            $segunda->points_balance_after = 10;
            $segunda->save();
        });
    }

    #[Test]
    public function una_transaccion_no_posteada_no_puede_llevar_saldo(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            // Una transaccion pendiente que ya trae saldo es la forma de que un
            // registro aparente haber impactado cuando el ledger nunca lo hizo.
            $transaccion = $this->makePostedTransaction($cliente);
            $transaccion->status = 'pending';
            $transaccion->points_delta = 10;
            $transaccion->points_balance_after = 10;

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/transactions_posted_consistency/');

            $transaccion->save();
        });
    }

    #[Test]
    public function una_transaccion_posted_exige_saldo_registrado(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            $transaccion = $this->makePostedTransaction($cliente);
            $transaccion->points_delta = 10;
            $transaccion->points_balance_after = null;

            $this->expectException(QueryException::class);

            $transaccion->save();
        });
    }

    #[Test]
    public function un_premio_no_acepta_un_descuento_porcentual_sobre_el_ciento(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/rewards_value_percent_range/');

            Reward::query()->create([
                'code' => 'DESC200',
                'title' => 'Descuento imposible',
                'reward_type' => 'percent_discount',
                'points_required' => 100,
                'value_minor' => null,
                'value_percent' => 200,
            ]);
        });
    }

    #[Test]
    public function un_token_consumido_exige_cliente_y_fecha_de_consumo(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/qr_tokens_consumed_consistency/');

            DB::table('qr_tokens')->insert([
                'tenant_id' => tenant(),
                'jti' => (string) Str::uuid(),
                'claim_type' => 'visit_stamp',
                'status' => 'consumed',
                'issued_at' => now(),
                'expires_at' => now()->addMinutes(4),
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    #[Test]
    public function un_cliente_no_puede_tener_dos_pases_en_la_misma_plataforma(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            WalletPass::factory()->google()->create(['customer_id' => $cliente->id]);

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/wallet_passes_customer_platform_unique/');

            WalletPass::factory()->google()->create(['customer_id' => $cliente->id]);
        });
    }

    #[Test]
    public function un_cliente_si_puede_tener_un_pase_en_cada_plataforma(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $cliente = Customer::factory()->create();

            WalletPass::factory()->google()->create(['customer_id' => $cliente->id]);
            WalletPass::factory()->apple()->create(['customer_id' => $cliente->id]);

            $this->assertSame(2, WalletPass::query()->where('customer_id', $cliente->id)->count());
        });
    }

    #[Test]
    public function el_serial_de_pase_es_unico_por_plataforma(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, function (): void {
            $primero = Customer::factory()->create();
            $segundo = Customer::factory()->create();

            WalletPass::factory()->apple('APPL-UNICO123')->create(['customer_id' => $primero->id]);

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/wallet_passes_platform_(serial|object_id)_unique/');

            WalletPass::factory()->apple('APPL-UNICO123')->create(['customer_id' => $segundo->id]);
        });
    }

    /**
     * Construye una transaccion posteada sin guardar, con los campos de impacto en
     * puntos pendientes de asignacion. Permite que cada test exprese la unica
     * violacion que quiere provocar.
     */
    private function makePostedTransaction(Customer $customer, ?QrToken $token = null): Transaction
    {
        $transaccion = new Transaction([
            'customer_id' => $customer->id,
            'type' => 'earn',
            'status' => 'posted',
            'source' => $token !== null ? 'pos_dynamic_qr' : 'pos_scan_client',
            'amount_spent_minor' => 0,
            'currency' => 'MXN',
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        if ($token !== null) {
            $transaccion->qr_token_id = $token->id;
        }

        return $transaccion;
    }
}
