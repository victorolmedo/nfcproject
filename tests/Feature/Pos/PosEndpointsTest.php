<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\CustomerStatus;
use App\Enums\LedgerEntryType;
use App\Enums\RedemptionCodeStatus;
use App\Enums\StaffRole;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\PointLedger;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\IdempotencyKey;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AuthenticatedTestCase;

/**
 * Los endpoints del punto de venta.
 *
 * Heredan de `AuthenticatedTestCase` para que el tenant se resuelva por subdominio
 * de verdad y toda la cadena de middleware se ejercite como en produccion. Un test
 * que fijara el contexto a mano probaria el controlador y dejaria sin comprobar justo
 * la frontera que protege los datos.
 *
 * Cada test verifica ademas el estado en la base de datos, no solo el codigo HTTP.
 * Un endpoint puede devolver 201 y no haber acreditado nada; la unica forma de
 * saber que el dinero y los puntos se movieron es mirando la cabecera, el asiento y
 * el saldo.
 */
final class PosEndpointsTest extends AuthenticatedTestCase
{
    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant(['slug' => 'cafe-aurora', 'currency' => 'MXN']);

        $this->makeUser($this->tenant, [
            'email' => 'caja1@cafe-aurora.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($this->tenant, 'caja1@cafe-aurora.test', 'correcta-1234');

        $this->token = (string) $result['token'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): void
    {
        $this->asTenant($this->tenant, function () use ($overrides): void {
            LoyaltyConfig::create([
                'name' => 'Programa',
                'rule_type' => 'amount',
                'points_per_minor_unit' => '0.001',
                'points_per_visit' => 0,
                'min_purchase_minor' => 0,
                'currency' => 'MXN',
                'rounding' => 'down',
                'is_active' => true,
                'effective_from' => now()->subDay(),
                'effective_to' => null,
                ...$overrides,
            ]);
        });
    }

    private function customer(int $points = 100, string $status = CustomerStatus::Active->value): Customer
    {
        return $this->asTenant(
            $this->tenant,
            fn (): Customer => Customer::factory()
                ->create(['status' => $status, 'current_points' => $points]),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function earn(array $payload, string $key = 'key-earn-1'): TestResponse
    {
        return $this->withBearer($this->token)
            ->withHeader(IdempotencyKey::HEADER, $key)
            ->postJson($this->tenantUrl($this->tenant->slug, '/api/v1/pos/earn'), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function redeem(array $payload, string $key = 'key-redeem-1'): TestResponse
    {
        return $this->withBearer($this->token)
            ->withHeader(IdempotencyKey::HEADER, $key)
            ->postJson($this->tenantUrl($this->tenant->slug, '/api/v1/pos/redeem'), $payload);
    }

    public function test_una_compra_acredita_puntos_y_devuelve_el_saldo(): void
    {
        $this->config();
        $customer = $this->customer(0);

        $response = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
            'external_ref' => 'TICKET-1',
        ]);

        $response->assertCreated()->assertJsonPath('data.points_delta', 10)
            ->assertJsonPath('data.points_balance_after', 10)
            ->assertJsonPath('data.type', TransactionType::Earn->value)
            ->assertJsonPath('data.status', TransactionStatus::Posted->value)
            ->assertJsonPath('data.source', TransactionSource::PosScanClient->value)
            ->assertJsonPath('data.external_ref', 'TICKET-1');

        $this->asTenant($this->tenant, function () use ($customer, $response): void {
            $customer->refresh();

            $this->assertSame(10, $customer->current_points);

            $transaction = Transaction::query()->findOrFail($response->json('data.id'));
            $entry = PointLedger::query()->where('transaction_id', $transaction->id)->sole();

            $this->assertSame(LedgerEntryType::Earn, $entry->entry_type);
            $this->assertSame(10, $entry->points);
            $this->assertSame(10, $entry->balance_after);
        });
    }

    public function test_la_cabecera_de_idempotencia_es_obligatoria(): void
    {
        /*
         * Sin esta cabecera el endpoint rechaza la peticion. Es deliberado: aceptar
         * una compra sin clave significa que un reintento del POS por perdida de red
         * cobra dos veces, y no hay forma de distinguirlo despues.
         */
        $this->config();
        $customer = $this->customer(0);

        $this->withBearer($this->token)
            ->postJson($this->tenantUrl($this->tenant->slug, '/api/v1/pos/earn'), [
                'customer_id' => $customer->id,
                'amount_minor' => 10_000,
                'currency' => 'MXN',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['idempotency_key']]]);

        $this->asTenant($this->tenant, function (): void {
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_reintentar_con_la_misma_clave_no_acredita_dos_veces(): void
    {
        $this->config();
        $customer = $this->customer(0);

        $first = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
        ], 'POS-1');

        $second = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
        ], 'POS-1');

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'El reintento debe devolver la transaccion original.',
        );

        $this->asTenant($this->tenant, function () use ($customer): void {
            $customer->refresh();

            $this->assertSame(10, $customer->current_points, 'Debe haberse acreditado una sola vez.');
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(1, PointLedger::query()->count());
        });
    }

    public function test_la_clave_de_idempotencia_no_distingue_mayusculas_ni_espacios(): void
    {
        /*
         * El POS y el proxy que reenvia la peticion pueden escribir la cabecera con
         * distinta capitalizacion o con un espacio al final. Si el motor las tratara
         * como claves distintas, el reintento pasaria el control de idempotencia y
         * cobraria dos veces: exactamente lo que la cabecera existe para evitar.
         */
        $this->config();
        $customer = $this->customer(0);

        $first = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
        ], 'POS-2');

        $second = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
        ], '  pos-2  ');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->asTenant($this->tenant, function (): void {
            $this->assertSame(1, Transaction::query()->count());
        });
    }

    public function test_el_tope_diario_se_explica_en_la_respuesta(): void
    {
        $this->config(['max_points_per_day' => 100]);

        $customer = $this->customer(0);

        // Generaria 60: se acreditan 60.
        $primera = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 60_000,
            'currency' => 'MXN',
        ], 'POS-TOPE-1');

        $primera->assertCreated()->assertJsonPath('data.points_delta', 60);

        // Generaria 60 mas, pero la cuota solo deja pasar 40.
        $response = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 60_000,
            'currency' => 'MXN',
        ], 'POS-TOPE-2');

        /*
         * El POS necesita saber POR QUE el cliente recibio 40 y no 60, para no
         * explicarle que se aplico un corte injusto. Se devuelve el dato estructurado
         * y no un texto ya redactado, para que el POS lo muestre en su idioma.
         */
        $response->assertCreated()
            ->assertJsonPath('data.points_delta', 40)
            ->assertJsonPath('data.capped_by', 'max_points_per_day')
            ->assertJsonPath('data.theoretical_points', 60);

        $this->asTenant($this->tenant, function () use ($customer): void {
            $customer->refresh();

            $this->assertSame(100, $customer->current_points, 'La cuota diaria debe respetarse tambien por HTTP.');
        });
    }

    public function test_una_moneda_distinta_se_rechaza_con_422(): void
    {
        $this->config();
        $customer = $this->customer(0);

        /*
         * `EUR` es una moneda bien formada, asi que la validacion de forma la acepta.
         * El rechazo viene del motor, con su propio codigo, porque el dato no es
         * invalido: es una moneda contra la que el programa del comercio no opera.
         */
        $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'EUR',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'loyalty.currency_mismatch')
            ->assertJsonStructure(['error' => ['details' => ['received_currency', 'expected_currency']]]);

        $this->asTenant($this->tenant, function (): void {
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_un_importe_negativo_se_rechaza_por_validacion(): void
    {
        $this->config();
        $customer = $this->customer(0);

        $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => -100,
            'currency' => 'MXN',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount_minor']]]);

        $this->asTenant($this->tenant, function (): void {
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_un_canje_devuelve_codigo_y_descuenta_el_stock(): void
    {
        $this->config();
        $customer = $this->customer(100);

        $reward = $this->asTenant($this->tenant, fn (): Reward => Reward::factory()->limited(5)->create([
            'code' => 'CAFEGRATIS',
            'points_required' => 40,
        ]));

        $response = $this->redeem([
            'customer_id' => $customer->id,
            'reward_code' => 'CAFEGRATIS',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.transaction.points_delta', -40)
            ->assertJsonPath('data.transaction.points_balance_after', 60)
            ->assertJsonPath('data.reward.inventory', 4)
            ->assertJsonPath('data.reward.redemptions_count', 1)
            ->assertJsonPath('data.redemption_code.status', RedemptionCodeStatus::Active->value)
            ->assertJsonPath('data.redemption_code.is_redeemable', true);

        $code = (string) $response->json('data.redemption_code.code');

        $this->assertStringStartsWith('PREM-', $code);

        $this->asTenant($this->tenant, function () use ($customer, $reward, $response, $code): void {
            $customer->refresh();
            $reward->refresh();

            $this->assertSame(60, $customer->current_points);
            $this->assertSame(4, $reward->inventory);

            $issued = RedemptionCode::query()
                ->where('code_hash', hash('sha256', strtoupper(trim($code))))
                ->sole();

            $this->assertSame($response->json('data.transaction.id'), $issued->transaction_id);
        });
    }

    public function test_canjear_sin_saldo_se_rechaza_con_los_puntos_faltantes(): void
    {
        $this->config();
        $customer = $this->customer(10);

        $reward = $this->asTenant($this->tenant, fn (): Reward => Reward::factory()->create([
            'code' => 'CAFEGRATIS',
            'points_required' => 100,
        ]));

        $response = $this->redeem([
            'customer_id' => $customer->id,
            'reward_code' => 'CAFEGRATIS',
        ]);

        /*
         * 422 y no 409, segun decide el dominio. El saldo insuficiente es un dato
         * invalido en el sentido de que la operacion no se puede realizar tal como se
         * pide; 409 quedaria para un conflicto de estado que el cliente podria
         * resolver por si mismo, que no es el caso aqui.
         */
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'loyalty.insufficient_points')
            ->assertJsonPath('error.details.missing_points', 90)
            ->assertJsonPath('error.details.available_points', 10)
            ->assertJsonPath('error.details.required_points', 100);

        $this->asTenant($this->tenant, function () use ($customer, $reward): void {
            $customer->refresh();
            $reward->refresh();

            $this->assertSame(10, $customer->current_points);
            $this->assertSame(0, $reward->redemptions_count);
            $this->assertSame(0, RedemptionCode::query()->count());
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_un_premio_agotado_devuelve_409_y_no_cobra_puntos(): void
    {
        $this->config();
        $customer = $this->customer(100);

        $reward = $this->asTenant($this->tenant, fn (): Reward => Reward::factory()->outOfStock()->create([
            'code' => 'ULTIMA_UNIDAD',
            'points_required' => 40,
        ]));

        $response = $this->redeem([
            'customer_id' => $customer->id,
            'reward_code' => 'ULTIMA_UNIDAD',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'loyalty.reward_unavailable')
            ->assertJsonPath('error.details.reason', 'reward_out_of_stock');

        $this->asTenant($this->tenant, function () use ($customer): void {
            $customer->refresh();

            $this->assertSame(100, $customer->current_points);
            $this->assertSame(0, RedemptionCode::query()->count());
        });
    }

    public function test_reintentar_un_canje_no_descuenta_stock_dos_veces(): void
    {
        $this->config();
        $customer = $this->customer(100);

        $reward = $this->asTenant($this->tenant, fn (): Reward => Reward::factory()->limited(5)->create([
            'code' => 'CAFEGRATIS',
            'points_required' => 40,
        ]));

        $payload = ['customer_id' => $customer->id, 'reward_code' => 'CAFEGRATIS'];

        $first = $this->redeem($payload, 'POS-CANJE-1');
        $second = $this->redeem($payload, 'POS-CANJE-1');

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame(
            $first->json('data.redemption_code.code'),
            $second->json('data.redemption_code.code'),
            'El reintento no debe emitir un codigo nuevo.',
        );

        $this->asTenant($this->tenant, function () use ($customer, $reward): void {
            $customer->refresh();
            $reward->refresh();

            $this->assertSame(60, $customer->current_points);
            $this->assertSame(4, $reward->inventory, 'Solo debe consumirse una unidad.');
            $this->assertSame(1, RedemptionCode::query()->count());
        });
    }

    public function test_un_premio_de_otro_tenant_no_existe_para_este_comercio(): void
    {
        $this->config();
        $customer = $this->customer(500);

        $otro = $this->makeTenant(['slug' => 'cafe-otro']);

        $this->asTenant($otro, fn (): Reward => Reward::factory()->create([
            'code' => 'PRIVADO',
            'points_required' => 10,
        ]));

        /*
         * El isolation por RLS hace que `Rule::exists` no vea el premio ajeno, y la
         * respuesta es 422 de "no existe". No se dice "no tienes permiso": para el POS
         * un premio que no puede mostrarse y un premio que no existen son lo mismo, y
         * distinguirlo confirmaria que ese premio existe en algun sitio.
         */
        $this->redeem([
            'customer_id' => $customer->id,
            'reward_code' => 'PRIVADO',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reward_code']]]);
    }

    public function test_sin_token_no_se_puede_operar(): void
    {
        $this->config();
        $customer = $this->customer(0);

        $this->withBearer(null)
            ->withHeader(IdempotencyKey::HEADER, 'POS-SIN-TOKEN')
            ->postJson($this->tenantUrl($this->tenant->slug, '/api/v1/pos/earn'), [
                'customer_id' => $customer->id,
                'amount_minor' => 10_000,
                'currency' => 'MXN',
            ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    public function test_una_cuenta_desactivada_no_puede_operar(): void
    {
        $this->config();
        $customer = $this->customer(0);

        $this->asTenant($this->tenant, function (): void {
            $this->assertNotNull(
                User::query()->where('email', 'caja1@cafe-aurora.test')->first(),
            );
        });

        // El token ya no sirve en cuanto la cuenta se marca como inactiva.
        $this->asTenant($this->tenant, function (): void {
            User::query()
                ->where('email', 'caja1@cafe-aurora.test')
                ->update(['is_active' => false]);
        });

        $response = $this->earn([
            'customer_id' => $customer->id,
            'amount_minor' => 10_000,
            'currency' => 'MXN',
        ], 'POS-CUENTA-OFF');

        /*
         * 403 y no 401. El token es valido y el cajero se identifica bien; lo que
         * falla es que la cuenta esta desactivada. Un 401 le diria al POS que
         * re-autentique, y eso no arreglaria nada: volveria a fallar igual.
         */
        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.account_disabled');
    }

    public function test_un_cliente_suspendido_no_puede_canear_pero_el_error_es_claro(): void
    {
        $this->config();
        $customer = $this->customer(100, CustomerStatus::Suspended->value);

        $reward = $this->asTenant($this->tenant, fn (): Reward => Reward::factory()->create([
            'code' => 'CAFEGRATIS',
            'points_required' => 40,
        ]));

        $this->redeem([
            'customer_id' => $customer->id,
            'reward_code' => 'CAFEGRATIS',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'loyalty.customer_not_eligible');

        $this->asTenant($this->tenant, function () use ($customer): void {
            $customer->refresh();

            $this->assertSame(100, $customer->current_points);
        });
    }
}
