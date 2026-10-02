<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Models\LoyaltyConfig;
use App\Services\Loyalty\Exceptions\RuleNotApplicable;
use App\Services\Loyalty\Exceptions\UnsupportedCurrency;
use App\Services\Loyalty\PointCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pruebas del motor de calculo.
 *
 * Sin base de datos a proposito: `PointCalculator` no recibe dependencias, asi que
 * se puede exhaustivamente la aritmetica, que es donde un sistema que reparte
 * puntos falla de forma silenciosa. Un redondeo mal puesto no lanza ningun error,
 * solo reparte un punto de mas o de menos a miles de clientes.
 *
 * Extiende `Tests\TestCase` y no `PHPUnit\Framework\TestCase` porque los casts de
 * fecha de `LoyaltyConfig` (`immutable_datetime`) necesitan la aplicacion
 * arrancada para resolver el formato de `effective_from` y `effective_to`. Sin
 * ella, cualquier test que toque las fechas de vigencia falla con
 * "Call to a member function connection() on null", que dice muy poco sobre la
 * causa real. No se usa `RefreshDatabase` porque aqui no hay una sola consulta.
 */
final class PointCalculatorTest extends TestCase
{
    private function calculator(): PointCalculator
    {
        return new PointCalculator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): LoyaltyConfig
    {
        return new LoyaltyConfig([
            'name' => 'Programa de test',
            'rule_type' => LoyaltyRuleType::Amount,
            'points_per_minor_unit' => '0.001',   // 1 punto por cada 1000 centavos = $10
            'points_per_visit' => 0,
            'min_purchase_minor' => 0,
            'currency' => 'MXN',
            'max_points_per_transaction' => null,
            'max_points_per_day' => null,
            'rounding' => PointsRounding::Down,
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
            ...$overrides,
        ]);
    }

    public function test_calcula_un_punto_por_cada_diez_dolares(): void
    {
        // $100.00 = 10000 centavos = 10 puntos exactos.
        $award = $this->calculator()->calculate($this->config(), 10_000);

        $this->assertSame(10, $award->points);
        $this->assertFalse($award->wasCapped());
        $this->assertNull($award->notEarnedReason);
    }

    public function test_redondeo_hacia_abajo_trunca_en_la_tabla_baja(): void
    {
        // $45.00 = 4500 centavos -> 4.5 puntos -> 4 hacia abajo.
        $award = $this->calculator()->calculate($this->config(), 4_500);

        $this->assertSame(4, $award->points);
    }

    /**
     * Casos de redondeo sobre tres importes distintos.
     *
     * Los tres importes estanchosen para caer en zonas distintas del redondeo:
     * exacto (.000), medio exacto (.5) y casi medio (.499 / .599). Con un solo
     * importe, la mayoria de las ramas del `match` nunca se ejercitarian.
     *
     * @return array<string, array{PointsRounding, int, int}>
     */
    public static function roundingProvider(): array
    {
        return [
            // 4500 centavos = $45.00 -> 4.5 puntos exactos.
            'hacia abajo 4.5 baja a 4' => [PointsRounding::Down, 4_500, 4],
            'half up 4.5 sube a 5' => [PointsRounding::HalfUp, 4_500, 5],
            'hacia arriba 4.5 sube a 5' => [PointsRounding::Up, 4_500, 5],

            // 4501 centavos = $45.01 -> 4.501 puntos. El resto es 0.501, MAYOR que
            // 0.5, asi que half up tambien sube. El corte real de half up esta en
            // 0.5 exacto, no en "casi 0.5".
            'hacia abajo 4.501 baja a 4' => [PointsRounding::Down, 4_501, 4],
            'half up 4.501 sube a 5' => [PointsRounding::HalfUp, 4_501, 5],
            'hacia arriba 4.501 sube a 5' => [PointsRounding::Up, 4_501, 5],

            // 4499 centavos = $44.99 -> 4.499 puntos.
            'hacia abajo 4.499 baja a 4' => [PointsRounding::Down, 4_499, 4],
            'half up 4.499 baja a 4' => [PointsRounding::HalfUp, 4_499, 4],
            'hacia arriba 4.499 sube a 5' => [PointsRounding::Up, 4_499, 5],

            // 4599 centavos = $45.99 -> 4.599 puntos.
            'hacia abajo 4.599 baja a 4' => [PointsRounding::Down, 4_599, 4],
            'half up 4.599 sube a 5' => [PointsRounding::HalfUp, 4_599, 5],
            'hacia arriba 4.599 sube a 5' => [PointsRounding::Up, 4_599, 5],

            // Importe exacto de multiplo de $10: sin resto decimal en ningun modo.
            'hacia abajo 10.0 exacto' => [PointsRounding::Down, 10_000, 10],
            'half up 10.0 exacto' => [PointsRounding::HalfUp, 10_000, 10],
            'hacia arriba 10.0 exacto' => [PointsRounding::Up, 10_000, 10],
        ];
    }

    #[DataProvider('roundingProvider')]
    public function test_cada_politica_de_redondeo_decide_distinto(
        PointsRounding $mode,
        int $amountMinor,
        int $expected,
    ): void {
        $config = $this->config(['rounding' => $mode]);

        $award = $this->calculator()->calculate($config, $amountMinor);

        $this->assertSame(
            $expected,
            $award->points,
            "Redondeo {$mode->value} sobre {$amountMinor} centavos.",
        );
    }

    public function test_half_up_usa_el_primer_digito_decimal_y_no_una_redondeo_bancario(): void
    {
        // 4.5 exacto sube; 4.49999 no. Se comprueba el corte en 0.5, que es donde
        // un redondeo mal implementado se desvia.
        $this->assertSame(5, $this->calculator()->calculate($this->config(['rounding' => PointsRounding::HalfUp]), 4_500)->points);

        // 4.49 no sube aunque se acerque.
        $this->assertSame(
            4,
            $this->calculator()->calculate($this->config(['rounding' => PointsRounding::HalfUp]), 4_499)->points,
        );
    }

    public function test_no_acumula_errores_de_punto_flotante(): void
    {
        /*
         * El motivo de usar bcmath: en `float`, sumar repetidamente 0.1 no converge
         * a un numero redondo y el resultado depende del numero de iteraciones. Con
         * aritmetica decimal el total es siempre el exacto, que es lo que espera un
         * ledger.
         */
        $config = $this->config(['points_per_minor_unit' => '0.001']);

        $total = 0;

        for ($i = 0; $i < 1000; $i++) {
            $total += $this->calculator()->calculate($config, 1_000)->points;
        }

        $this->assertSame(1000, $total);
    }

    public function test_una_compra_por_debajo_del_minimo_no_acredita_nada(): void
    {
        $config = $this->config(['min_purchase_minor' => 5_000]);

        // $45.00 no alcanza el minimo de $50.00.
        $award = $this->calculator()->calculate($config, 4_500);

        $this->assertSame(0, $award->points);
        $this->assertSame('below_minimum_purchase', $award->notEarnedReason);

        // Justo en el minimo, si.
        $this->assertSame(5, $this->calculator()->calculate($config, 5_000)->points);
    }

    public function test_una_compra_justo_en_el_minimo_acredita(): void
    {
        $config = $this->config(['min_purchase_minor' => 5_000]);

        $this->assertSame(5, $this->calculator()->calculate($config, 5_000)->points);
    }

    public function test_el_tope_por_transaccion_recorta_y_conserva_el_valor_teorico(): void
    {
        $config = $this->config(['max_points_per_transaction' => 10]);

        // $500.00 generaria 50 puntos, pero el tope es 10.
        $award = $this->calculator()->calculate($config, 50_000);

        $this->assertSame(10, $award->points);
        $this->assertSame(50, $award->rawPoints, 'El valor teorico debe conservarse para poder explicarlo.');
        $this->assertSame('max_points_per_transaction', $award->cappedBy);
    }

    public function test_el_tope_diario_usa_lo_ya_ganado_hoy(): void
    {
        $config = $this->config(['max_points_per_day' => 100]);

        // 40 puntos mas, y ya lleva 70 hoy => solo 30 de margen.
        $award = $this->calculator()->calculate($config, 40_000, alreadyEarnedToday: 70);

        $this->assertSame(30, $award->points);
        $this->assertSame('max_points_per_day', $award->cappedBy);
    }

    public function test_el_tope_diario_se_aplica_despues_del_tope_por_transaccion(): void
    {
        $config = $this->config([
            'max_points_per_transaction' => 60,
            'max_points_per_day' => 100,
        ]);

        // Genera 200 puntos brutos. Tope por operacion: 60. Tope diario con 80 ya
        // ganados: margen 20. El resultado debe ser 20, no 60.
        $award = $this->calculator()->calculate($config, 200_000, alreadyEarnedToday: 80);

        $this->assertSame(20, $award->points);
        $this->assertSame('max_points_per_day', $award->cappedBy);
    }

    public function test_un_tope_diario_ya_agotado_no_acredita_puntos_negativos(): void
    {
        $config = $this->config(['max_points_per_day' => 100]);

        // Ya se ganaron 150 hoy, por encima del tope. El margen es -50, y acreditar
        // -50 puntos seria un bug muy visible: restaria puntos al cliente.
        $award = $this->calculator()->calculate($config, 5_000, alreadyEarnedToday: 150);

        $this->assertSame(0, $award->points);
        $this->assertGreaterThanOrEqual(0, $award->points);
    }

    public function test_regla_por_visita_ignora_el_importe(): void
    {
        $config = $this->config([
            'rule_type' => LoyaltyRuleType::Visit,
            'points_per_visit' => 5,
        ]);

        $this->assertSame(5, $this->calculator()->calculate($config, 100, isVisit: true)->points);
        $this->assertSame(5, $this->calculator()->calculate($config, 500_000, isVisit: true)->points);
    }

    public function test_una_regla_por_visita_no_acredita_sin_visita(): void
    {
        $config = $this->config([
            'rule_type' => LoyaltyRuleType::Visit,
            'points_per_visit' => 5,
        ]);

        $award = $this->calculator()->calculate($config, 100, isVisit: false);

        $this->assertSame(0, $award->points);
        $this->assertSame('rule_requires_visit', $award->notEarnedReason);
    }

    public function test_la_regla_hybrid_suma_importe_y_visita(): void
    {
        $config = $this->config([
            'rule_type' => LoyaltyRuleType::Hybrid,
            'points_per_minor_unit' => '0.001',  // $45.00 -> 4.5 -> 4
            'points_per_visit' => 3,
        ]);

        // 4 (por importe) + 3 (visita) = 7.
        $this->assertSame(7, $this->calculator()->calculate($config, 4_500, isVisit: true)->points);

        // Sin visita, solo el importe.
        $this->assertSame(4, $this->calculator()->calculate($config, 4_500, isVisit: false)->points);
    }

    public function test_una_regla_inactiva_no_acredita(): void
    {
        $award = $this->calculator()->calculate($this->config(['is_active' => false]), 50_000);

        $this->assertSame(0, $award->points);
        $this->assertSame('rule_not_effective', $award->notEarnedReason);
    }

    public function test_una_regla_fuera_del_periodo_de_vigencia_no_acredita(): void
    {
        $config = $this->config([
            'effective_from' => now()->addDay(),
            'effective_to' => now()->addMonth(),
        ]);

        $this->assertSame(0, $this->calculator()->calculate($config, 50_000)->points);
    }

    public function test_un_ticket_negativo_se_rechaza_en_lugar_de_acreditar_cero(): void
    {
        $this->expectException(RuleNotApplicable::class);

        $this->calculator()->calculate($this->config(), -4_500);
    }

    public function test_un_importe_absurdo_se_rechaza_antes_de_hacer_aritmetica(): void
    {
        $this->expectException(RuleNotApplicable::class);

        $this->calculator()->calculate($this->config(), PointCalculator::MAX_AMOUNT_MINOR + 1);
    }

    public function test_una_tasa_con_ocho_decimales_no_se_trunca_antes_de_redondear(): void
    {
        /*
         * 0.00000025 puntos por centavo. Sobre 1 centavo son 2.5e-8, que redondeado
         * hacia arriba es 1 punto y hacia abajo 0. Si la tasa se truncase a menos
         * decimales antes de decidir, las dos respuestas serian 0.
         */
        $config = $this->config([
            'points_per_minor_unit' => '0.00000025',
            'rounding' => PointsRounding::Up,
        ]);

        $this->assertSame(1, $this->calculator()->calculate($config, 1)->points);

        $abajo = $this->config([
            'points_per_minor_unit' => '0.00000025',
            'rounding' => PointsRounding::Down,
        ]);

        $this->assertSame(0, $this->calculator()->calculate($abajo, 1)->points);
    }

    public function test_una_tasa_de_cero_no_acredita_y_lo_senala(): void
    {
        $config = $this->config(['points_per_minor_unit' => '0']);

        $award = $this->calculator()->calculate($config, 50_000);

        $this->assertSame(0, $award->points);
        $this->assertSame('zero_rate_configured', $award->notEarnedReason);
    }

    public function test_una_operacion_en_otra_moneda_se_rechaza(): void
    {
        $config = $this->config();

        $this->expectException(UnsupportedCurrency::class);

        $this->calculator()->assertCurrencyMatches($config, 'USD');
    }

    public function test_la_comparacion_de_moneda_ignora_mayusculas(): void
    {
        $this->calculator()->assertCurrencyMatches($this->config(), 'mxn');
        $this->calculator()->assertCurrencyMatches($this->config(), 'MXN');

        $this->assertTrue(true, 'Sin excepcion: las dos comparaciones pasaron.');
    }

    public function test_una_compra_de_cero_importe_no_acredita(): void
    {
        $award = $this->calculator()->calculate($this->config(), 0);

        $this->assertSame(0, $award->points);
    }
}
