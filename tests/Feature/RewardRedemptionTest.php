<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Enums\RedemptionCodeStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Loyalty\Exceptions\RewardUnavailable;
use App\Services\Loyalty\PointsService;
use App\Services\Loyalty\RedemptionCodeIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas del canje de premios: disponibilidad y emision del codigo.
 *
 * Los tests de `PointsServiceTest` cubren la contabilidad del canje (puntos, saldo
 * y asiento). Aqui se cubre lo que ocurre alrededor: que un premio agotado no se
 * pueda vender, que los limites del catalogo se respeten, y que cada canje deje un
 * codigo que el cliente pueda gastar.
 *
 * El foco del archivo es el inventario. Un canje que no descuenta stock no parece
 * fallar nunca: cada operacion individual cuadra y los puntos se descuentan bien.
 * Lo que produce es un silencio. Se venden cinco cupcakes y quedan tres, y nadie se
 * entera hasta que un cliente viene a canjear el sexto y el cajero improvisa. Por eso
 * los tests comprueban el estado del premio despues de cada canje y no solo que la
 * llamada lanzara.
 */
final class RewardRedemptionTest extends TestCase
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

    private function config(): LoyaltyConfig
    {
        return LoyaltyConfig::create([
            'name' => 'Programa',
            'rule_type' => LoyaltyRuleType::Amount,
            'points_per_minor_unit' => '0.001',
            'points_per_visit' => 0,
            'min_purchase_minor' => 0,
            'currency' => 'MXN',
            'rounding' => PointsRounding::Down,
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);
    }

    public function test_un_canje_descuenta_el_inventario_y_cuenta_el_canje(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(100)->create();
            $reward = Reward::factory()->limited(5)->create(['points_required' => 40]);

            $this->points()->redeem($customer->id, $reward, 'inv-1');

            $reward->refresh();

            $this->assertSame(4, $reward->inventory);
            $this->assertSame(1, $reward->redemptions_count);
        });
    }

    public function test_el_inventario_ilimitado_no_se_toca(): void
    {
        /*
         * `inventory = NULL` significa ilimitado, no cero. Es el caso mas comun: un
         * descuento o un producto que el comercio no lleva inventario real. El
         * contador de canjes si debe subir, porque alimenta las metricas y el
         * limite global aunque no haya stock que descontar.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->create(['points_required' => 40, 'inventory' => null]);

            $this->points()->redeem($customer->id, $reward, 'inv-ilimitado-1');
            $this->points()->redeem($customer->id, $reward, 'inv-ilimitado-2');

            $reward->refresh();

            $this->assertNull($reward->inventory);
            $this->assertSame(2, $reward->redemptions_count);
        });
    }

    public function test_un_premio_sin_stock_se_rechaza_sin_cobrar_puntos(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(100)->create();
            $reward = Reward::factory()->outOfStock()->create(['points_required' => 40]);

            try {
                $this->points()->redeem($customer->id, $reward, 'inv-agotado');

                $this->fail('Un premio sin inventario deberia rechazarse.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_out_of_stock', $exception->reason());
                $this->assertSame($reward->id, $exception->errorDetails()['reward_id']);
            }

            // El punto critico: el rechazo del inventario no toca el saldo.
            $customer->refresh();

            $this->assertSame(100, $customer->current_points);
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, RedemptionCode::query()->count());
        });
    }

    public function test_solo_se_pueden_canjear_las_unidades_disponibles(): void
    {
        /*
         * Se agotan las dos unidades y el tercer intento cae, aunque el cliente
         * tenga saldo de sobra. Esto es lo que un `decrement` sin `where inventory >
         * 0` permitiria: vender tres y dejar el contador en -1.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(2)->create(['points_required' => 40]);

            $this->points()->redeem($customer->id, $reward, 'inv-2u-1');
            $this->points()->redeem($customer->id, $reward, 'inv-2u-2');

            $reward->refresh();

            $this->assertSame(0, $reward->inventory);

            $this->expectException(RewardUnavailable::class);

            try {
                $this->points()->redeem($customer->id, $reward, 'inv-2u-3');
            } finally {
                $reward->refresh();

                $this->assertSame(0, $reward->inventory, 'El inventario no puede quedar negativo.');
                $this->assertSame(2, $reward->redemptions_count);
                $this->assertSame(2, RedemptionCode::query()->count());
            }
        });
    }

    public function test_el_limite_por_cliente_se_cumple_y_es_individual(): void
    {
        /*
         * El limite es por cliente, no global. El primer cliente agota su cupo y
         * el segundo, con el mismo premio, puede canjear igual: si el limite se
         * contara sobre todo el tenant, un solo cliente volveria a dejar el premio
         * sin stock para el resto.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $primero = Customer::factory()->withPoints(500)->create();
            $segundo = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(10)->create([
                'points_required' => 40,
                'max_redemptions_per_customer' => 2,
            ]);

            $this->points()->redeem($primero->id, $reward, 'lim-1');
            $this->points()->redeem($primero->id, $reward, 'lim-2');

            try {
                $this->points()->redeem($primero->id, $reward, 'lim-3');

                $this->fail('El tercer canje del cliente deberia violar su limite.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_customer_limit_reached', $exception->reason());
            }

            // El segundo cliente no hereda el cupo del primero.
            $this->points()->redeem($segundo->id, $reward, 'lim-4');

            $primero->refresh();
            $reward->refresh();

            $this->assertSame(420, $primero->current_points);
            $this->assertSame(3, $reward->redemptions_count);
            $this->assertSame(7, $reward->inventory);
        });
    }

    public function test_el_limite_por_cliente_cuenta_los_canjes_ya_registrados(): void
    {
        /*
         * El limite se recalcula contra lo ya contabilizado, no contra lo que el
         * proceso recuerda. Aqui se fija el limite en 1 despues de haber canjeado
         * una vez: si el control mirara solo la llamada en curso, el segundo canje
         * pasaria.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->create(['points_required' => 40]);

            $this->points()->redeem($customer->id, $reward, 'rec-1');

            $reward->update(['max_redemptions_per_customer' => 1]);

            $this->expectException(RewardUnavailable::class);

            $this->points()->redeem($customer->id, $reward, 'rec-2');
        });
    }

    public function test_el_tope_global_de_canjes_se_cumple_aunque_hay_stock(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $primero = Customer::factory()->withPoints(500)->create();
            $segundo = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(99)->create([
                'points_required' => 40,
                'max_redemptions_total' => 1,
            ]);

            $this->points()->redeem($primero->id, $reward, 'global-1');

            try {
                $this->points()->redeem($segundo->id, $reward, 'global-2');

                $this->fail('El tope global deberia impedir el segundo canje.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_total_limit_reached', $exception->reason());
            }

            // El stock sigue intacto: el tope global salta antes de consumirlo.
            $reward->refresh();

            $this->assertSame(98, $reward->inventory);
        });
    }

    public function test_la_ventana_de_vigencia_del_catalogo_se_respeta(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();

            $caducado = Customer::factory()->withPoints(500)->create();
            $futuro = Customer::factory()->withPoints(500)->create();
            $vigente = Customer::factory()->withPoints(500)->create();

            /*
             * Estos premios quedan `is_active = true` con la ventana cerrada. Sin
             * la comprobacion de fechas, una promocion de diciembre seguiria
             * vendiendose en enero sin que nadie tuviera que apagarla.
             */
            $terminado = Reward::factory()->create([
                'points_required' => 40,
                'valid_from' => now()->subMonths(2),
                'valid_until' => now()->subDay(),
            ]);

            $porEmpezar = Reward::factory()->create([
                'points_required' => 40,
                'valid_from' => now()->addDay(),
                'valid_until' => now()->addMonth(),
            ]);

            $enVigencia = Reward::factory()->create([
                'points_required' => 40,
                'valid_from' => now()->subDay(),
                'valid_until' => now()->addMonth(),
            ]);

            try {
                $this->points()->redeem($caducado->id, $terminado, 'vig-1');

                $this->fail('Un premio fuera de vigencia deberia rechazarse.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_expired', $exception->reason());
            }

            try {
                $this->points()->redeem($futuro->id, $porEmpezar, 'vig-2');

                $this->fail('Un premio que aun no empieza deberia rechazarse.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_not_yet_valid', $exception->reason());
            }

            $this->points()->redeem($vigente->id, $enVigencia, 'vig-3');
        });
    }

    public function test_un_premio_despublicado_no_se_puede_canjear(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->create([
                'points_required' => 40,
                'is_active' => false,
            ]);

            try {
                $this->points()->redeem($customer->id, $reward, 'despub-1');

                $this->fail('Un premio inactivo deberia rechazarse.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_inactive', $exception->reason());
            }
        });
    }

    public function test_el_canje_emite_un_codigo_canjeable(): void
    {
        $this->asTenant($this->tenant, function (): void {
            /*
             * Reloj congelado para que la caducidad sea comparable al instante del
             * canje de forma exacta.
             *
             * Sin congelarlo, `now()` en el test corre unas microsegundos despues del
             * `now()` del servicio y el plazo medido da 29.99999 dias. Con el reloj
             * parado la asercion es exacta y sigue detectando el desfase de tres horas
             * que se produjo cuando la sesion de PostgreSQL vivia en -03.
             */
            $instante = CarbonImmutable::parse('2026-06-15T10:30:00Z');

            CarbonImmutable::setTestNow($instante);

            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(5)->create([
                'points_required' => 40,
                'redemption_valid_days' => 30,
                'value_minor' => 8_000,
            ]);

            $transaction = $this->points()->redeem($customer->id, $reward, 'codigo-1');

            $code = RedemptionCode::query()->sole();

            $this->assertSame($customer->id, $code->customer_id);
            $this->assertSame($reward->id, $code->reward_id);
            $this->assertSame($transaction->id, $code->transaction_id);

            $this->assertSame(RedemptionCodeStatus::Active, $code->status);
            $this->assertSame(8_000, $code->value_minor);
            $this->assertSame('MXN', $code->currency);

            // El hash es el indice de busqueda y no coincide con el texto plano.
            $this->assertSame(RedemptionCodeIssuer::hash($code->code), $code->code_hash);
            $this->assertNotSame($code->code, $code->code_hash);
            $this->assertSame(64, strlen($code->code_hash));

            // Caduca segun el catalogo, ni antes ni mucho despues.
            $this->assertTrue($code->isRedeemable());
            $this->assertTrue($code->expires_at->isFuture());

            /*
             * Exactamente 30 dias desde el canje. La asercion es estricta a proposito:
             * cuando la sesion de PostgreSQL vivia en -03 en lugar de UTC, este
             * numero salia 30.12 dias y el codigo de canje quedaba tres horas
             * corrido. Un `assertGreaterThan(29, ...)` habria dejado pasar ese bug.
             */
            $this->assertSame($instante->addDays(30)->toIso8601String(), $code->expires_at->toIso8601String());

            $this->assertSame($reward->code, $code->metadata['reward_code']);
            $this->assertSame(40, $code->metadata['cost_points']);
        });
    }

    public function test_los_codigos_se_generan_distintos_y_sin_caracteres_ambiguos(): void
    {
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(10_000)->create();
            $reward = Reward::factory()->limited(20)->create(['points_required' => 10]);

            for ($i = 0; $i < 5; $i++) {
                $this->points()->redeem($customer->id, $reward, "codigo-uniq-{$i}");
            }

            $codes = RedemptionCode::query()->pluck('code');
            $hashes = RedemptionCode::query()->pluck('code_hash');

            $this->assertCount(5, $codes->unique(), 'Cada canje debe emitir un codigo distinto.');

            // `code_hash` es UNIQUE: si dos canjes colisionaran, la segunda
            // transaccion habria fallado ya.
            $this->assertCount(5, $hashes->unique());

            /*
             * Se teclean de cara al cliente, a veces por telefono. `0`, `1`, `I`, `L`,
             * `U` y `B` se confunden y son la causa tipica de que un premio valido
             * se rechace por un error de transcripcion.
             */
            foreach ($codes as $code) {
                $this->assertDoesNotMatchRegularExpression('/[01ILUB]/', $code);
                $this->assertStringStartsWith('PREM-', $code);
            }
        });
    }

    public function test_reintentar_no_vuelve_a_descontar_inventario_ni_emite_otro_codigo(): void
    {
        /*
         * Esta es la prueba que evita el doble cobro de un incidente tipico: el POS
         * pierde la respuesta, el cajero pulsa dos veces o la red reintenta sola. La
         * idempotencia ya existia para los puntos; ahora tambien tiene que cubrir el
         * inventario y el codigo, porque un reintento por la misma clave que
         * consumiera una segunda unidad y emitiera un segundo codigo seria
         * exactamente la perdida que se queria evitar.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(5)->create(['points_required' => 40]);

            $first = $this->points()->redeem($customer->id, $reward, 'idem-inv');
            $second = $this->points()->redeem($customer->id, $reward, 'idem-inv');

            $this->assertSame($first->id, $second->id, 'Debe devolver la transaccion original.');

            $reward->refresh();
            $customer->refresh();

            $this->assertSame(4, $reward->inventory, 'El reintento no debe consumir otra unidad.');
            $this->assertSame(1, $reward->redemptions_count);
            $this->assertSame(1, RedemptionCode::query()->count(), 'No debe emitirse un segundo codigo.');
            $this->assertSame(460, $customer->current_points);
        });
    }

    public function test_si_el_codigo_no_se_emite_se_revierte_el_canje_completo(): void
    {
        /*
         * El codigo y el descuento son el mismo hecho. Si la emision falla, el
         * cliente no puede gastar lo que pago y el comercio entrego un premio sin
         * cobrar: todo debe volver atras, incluido el inventario.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(500)->create();
            $reward = Reward::factory()->limited(5)->create(['points_required' => 40]);

            RedemptionCode::creating(static function (): never {
                throw new RuntimeException('Fallo la emision del codigo.');
            });

            try {
                $this->points()->redeem($customer->id, $reward, 'atomicidad-1');

                $this->fail('La emision fallida deberia abortar el canje.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Fallo la emision del codigo.', $exception->getMessage());
            }

            $customer->refresh();
            $reward->refresh();

            $this->assertSame(500, $customer->current_points, 'Los puntos deben volver al cliente.');
            $this->assertSame(0, $customer->lifetime_points_redeemed);
            $this->assertSame(5, $reward->inventory, 'El inventario debe liberarse.');
            $this->assertSame(0, $reward->redemptions_count);
            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(0, RedemptionCode::query()->count());
        });
    }

    public function test_un_canje_con_puntos_suficientes_sigue_respetando_el_stock(): void
    {
        /*
         * Orden de las comprobaciones. Con saldo de sobra y premio agotado, el
         * error debe ser de inventario y no de saldo: decir "te faltan puntos" de un
         * premio que ya no existe hace que el cliente los junte para nada.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(1_000)->create();
            $agotado = Reward::factory()->outOfStock()->create(['points_required' => 40]);
            $sinSaldo = Reward::factory()->limited(5)->create(['points_required' => 5_000]);

            try {
                $this->points()->redeem($customer->id, $agotado, 'orden-1');

                $this->fail('Deberia fallar por inventario.');
            } catch (RewardUnavailable $exception) {
                $this->assertSame('reward_out_of_stock', $exception->reason());
            }

            $customer->refresh();

            $this->assertSame(1_000, $customer->current_points);
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    public function test_un_canje_aceptado_cuadra_con_el_codigo_y_el_stock(): void
    {
        /*
         * Cierre de contabilidad del canje: cabecera, asiento, saldo, contador de
         * canjes e inventario tienen que contar lo mismo. Es el invariante que delata
         * un canje a medias si alguna vez se rompe algo entre la cabecera y el codigo.
         */
        $this->asTenant($this->tenant, function (): void {
            $this->config();
            $customer = Customer::factory()->withPoints(1_000)->create();
            $reward = Reward::factory()->limited(3)->create(['points_required' => 250]);

            $transaction = $this->points()->redeem($customer->id, $reward, 'cierre-1');

            $reward->refresh();
            $customer->refresh();
            $code = RedemptionCode::query()->sole();
            $ledger = $transaction->ledgerEntry()->sole();

            $this->assertSame(TransactionType::Redeem, $transaction->type);
            $this->assertSame(TransactionStatus::Posted, $transaction->status);
            $this->assertSame(-250, $transaction->points_delta);
            $this->assertSame(750, $transaction->points_balance_after);

            $this->assertSame(-250, $ledger->points);
            $this->assertSame(750, $customer->current_points);
            $this->assertSame(250, $customer->lifetime_points_redeemed);

            $this->assertSame(2, $reward->inventory);
            $this->assertSame(1, $reward->redemptions_count);

            $this->assertSame($transaction->id, $code->transaction_id);
            $this->assertSame(250, $code->metadata['cost_points']);
        });
    }
}
