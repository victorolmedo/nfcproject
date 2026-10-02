<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Models\LoyaltyConfig;
use App\Services\Loyalty\Exceptions\RuleNotApplicable;
use App\Services\Loyalty\Exceptions\UnsupportedCurrency;

/**
 * Calcula cuantos puntos corresponden a una operacion.
 *
 * Es una clase PURA: no toca la base de datos. Todo lo que necesita (la compra
 * minima, el tope por transaccion, los puntos ya ganados hoy) entra por
 * parametro. Eso permite testear exhaustivamente los casos limite, que en un
 * sistema que reparte dinero y puntos son donde se esconden los errores, sin
 * montar una transaccion de base de datos para cada uno.
 *
 * --- Sobre por que bcmath y no float ---
 *
 * El importe es un entero de minor units y `points_per_minor_unit` es un decimal
 * exacto (NUMERIC(18,8) en la base). El producto tiene decimales, y con `float`
 * los errores de redondeo se acumulan: 0.1 + 0.2 !== 0.3 tambien en PHP, y en un
 * ledger eso acaba en un saldo que no cuadra con la suma de asientos y obliga a
 * conciliar a mano.
 *
 * Con bcmath la aritmetica es decimal de precision arbitraria y el resultado es
 * exactamente el que describe la regla.
 *
 * --- El decimal viaja hasta el final ---
 *
 * El producto se conserva como CADENA decimal durante todo el calculo y solo se
 * convierte a entero en el ultimo paso, al redondear. Truncar antes (con un
 * `(int)`) pierde justo la informacion que el redondeo necesita: 4499 * 0.001 son
 * 4.499 puntos, y truncar a 4 antes de redondear hacia arriba daria 4 donde
 * corresponderia 5. El redondeo se aplica una sola vez y sobre el valor completo.
 *
 * --- Precision ---
 *
 * Se calculan `RATE_SCALE + DECISION_DIGITS` decimales, donde el margen extra son
 * los digitos que el redondeo necesita para decidir. `points_per_minor_unit` puede
 * traer 8 decimales, y truncar a 0 o a 4 antes de decidir produce errores en el
 * borde exacto (0.99995 debe dar 1, no 0).
 */
class PointCalculator
{
    /** Suelo del importe. 0 significa "cualquier compra". */
    public const MIN_PURCHASE_FLOOR = 0;

    /**
     * Techo defensivo del importe. Ningun comercio procesa una compra de un
     * trillon de centavos; si aparece, es un dato corrupto o un ataque, y sin este
     * techo bcmath tardaria una era geologica en terminar.
     */
    public const MAX_AMOUNT_MINOR = 100_000_000_000_000;

    /** Techo defensivo de puntos, por si la regla viene absurda. */
    public const MAX_POINTS_CEILING = 100_000_000;

    /** Decimales de `points_per_minor_unit` garantizados por la columna. */
    private const RATE_SCALE = 8;

    /**
     * Calcula el otorgamiento.
     *
     * @param  int  $amountMinor  importe de la compra en minor units (centavos).
     * @param  int  $alreadyEarnedToday  puntos ya acreditados hoy al cliente. Lo
     *                                   necesita el tope diario; se pasa como parametro
     *                                   para mantener esta clase sin dependencias de base
     *                                   de datos.
     * @param  bool  $isVisit  la operacion cuenta como visita. Solo importa en
     *                         reglas `visit` e `hybrid`.
     */
    public function calculate(
        LoyaltyConfig $config,
        int $amountMinor,
        int $alreadyEarnedToday = 0,
        bool $isVisit = false,
    ): PointsAward {
        $this->guardAmount($amountMinor);

        if (! $config->isEffectiveAt(now())) {
            return PointsAward::denied('rule_not_effective');
        }

        $ruleType = $config->rule_type;

        // Una regla que no usa importe y cuya operacion no es una visita no acredita.
        if (! $ruleType->usesAmount() && ! $isVisit) {
            return PointsAward::denied('rule_requires_visit');
        }

        $raw = $this->computeRaw($config, $amountMinor, $isVisit);

        if ($this->isZeroDecimal($raw)) {
            return PointsAward::denied($this->denialReason($config, $amountMinor, $isVisit));
        }

        $points = $this->round($raw, $config->rounding);

        return $this->applyCaps($config, $points, $alreadyEarnedToday);
    }

    /**
     * Valor teorico SIN redondear, como cadena decimal exacta.
     *
     * La compra minima se comprueba aqui y no en el redondeo porque es una
     * condicion de ELEGIBILIDAD, no un recorte: una compra de menos del minimo no
     * genera ni un punto, en vez de generar un punto y recortarlo a cero.
     */
    private function computeRaw(LoyaltyConfig $config, int $amountMinor, bool $isVisit): string
    {
        if (! $config->rule_type->usesAmount()) {
            // Regla `visit`: cada visita vale lo mismo, sin importar el importe.
            return $isVisit ? (string) $config->points_per_visit : '0';
        }

        if ($amountMinor < max(self::MIN_PURCHASE_FLOOR, $config->min_purchase_minor)) {
            return '0';
        }

        /*
         * Hybrid suma ambos criterios: puntos por importe mas los de la visita.
         *
         * Es la unica lectura coherente con el esquema, que no tiene ningun umbral
         * que permitiera elegir entre "por importe" y "por visita".
         */
        $fromAmount = bcmul(
            (string) $amountMinor,
            (string) $config->points_per_minor_unit,
            self::RATE_SCALE + 2,
        );

        if ($config->rule_type === LoyaltyRuleType::Hybrid && $isVisit) {
            $fromAmount = bcadd($fromAmount, (string) $config->points_per_visit, self::RATE_SCALE + 2);
        }

        return $fromAmount;
    }

    /**
     * Redondea una cadena decimal a entero segun la politica del tenant.
     *
     * Se opera sobre el VALOR ABSOLUTO y se devuelve con el signo, porque en
     * contabilidad el redondeo se aplica a la magnitud: -0.4 redondeado hacia arriba
     * es -1, no 0. Aplicar el redondeo al numero con signo daria 0, que es un punto
     * de diferencia a favor de quien.canjea puntos.
     */
    private function round(string $value, PointsRounding $mode): int
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');

        [$integerPart, $fractionPart] = array_pad(explode('.', $absolute, 2), 2, '');

        $integer = $integerPart === '' ? 0 : (int) $integerPart;

        // Solo hay resto si algun digito de la parte decimal es distinto de cero.
        $hasRemainder = $fractionPart !== '' && trim($fractionPart, '0') !== '';

        if ($hasRemainder) {
            $integer = match ($mode) {
                PointsRounding::Down => $integer,
                PointsRounding::Up => $integer + 1,
                // Se mira UN digito porque la decision half-up solo depende del
                // primero: 0.5 sube, 0.49 no. El resto de digitos son irrelevantes
                // para este modo, que es lo que significa "half up".
                PointsRounding::HalfUp => ((int) $fractionPart[0] >= 5) ? $integer + 1 : $integer,
            };
        }

        $integer = $this->clamp($integer);

        return $negative ? -$integer : $integer;
    }

    /**
     * Limita el resultado a un rango sano y descarta valores no representables.
     */
    private function clamp(int $points): int
    {
        return max(-self::MAX_POINTS_CEILING, min(self::MAX_POINTS_CEILING, $points));
    }

    /**
     * Aplica los topes por operacion y por dia.
     *
     * El orden importa y es deliberado: primero el tope por operacion, luego el
     * diario. Si se invirtieran, una compra que ya supero el tope diario acreditaria
     * el tope por operacion completo, que es justo lo que el tope diario existe para
     * impedir.
     */
    private function applyCaps(LoyaltyConfig $config, int $points, int $alreadyEarnedToday): PointsAward
    {
        $raw = $points;

        // El tope que se reporta es el ULTIMO que redujo el valor, no el primero.
        // Si ambos topes aplican, el diario manda sobre el total final: es el que
        // dejo menos margen disponible. Reportar el de operacion cuando el diario
        // fue el que realmente recortaria daria una explicacion falsa al cliente
        // ("te limitamos a 60 por operacion" cuando el limite real eran los 20 que
        // quedaban hoy), y llevaria al cliente a competir por un margen que en
        // realidad ya no existe.
        $cappedBy = null;

        $perTransaction = $config->max_points_per_transaction;

        if ($perTransaction !== null && $points > $perTransaction) {
            $points = $perTransaction;
            $cappedBy = 'max_points_per_transaction';
        }

        $perDay = $config->max_points_per_day;

        if ($perDay !== null) {
            $headroom = $perDay - $alreadyEarnedToday;

            if ($points > $headroom) {
                // Un `headroom` negativo significa que ya se supero el tope; en ese
                // caso no se acredita nada en vez de acreditar un numero negativo.
                $points = max(0, $headroom);
                $cappedBy = 'max_points_per_day';
            }
        }

        if ($cappedBy === null) {
            return PointsAward::full($points);
        }

        return PointsAward::capped($points, $raw, $cappedBy);
    }

    /**
     * Motivo de un cero, para que el POS pueda explicarle al cliente por que no
     * acumula en lugar de mostrar un "0" sin contexto.
     */
    private function denialReason(LoyaltyConfig $config, int $amountMinor, bool $isVisit): string
    {
        if (! $config->rule_type->usesAmount()) {
            return 'rule_requires_visit';
        }

        if ($amountMinor < max(self::MIN_PURCHASE_FLOOR, $config->min_purchase_minor)) {
            return 'below_minimum_purchase';
        }

        // La regla es elegible pero la tasa es cero: configuracion invalida.
        return 'zero_rate_configured';
    }

    /**
     * Compara contra cero de forma decimal exacta.
     *
     * Se usa `bccomp` y no `trim($valor, '0.')`: el trim quita caracteres de los
     * dos extremos, asi que "10.000" se convertia en "1" y "0.000" en "", y ambos
     * casos hay que distinguirlos bien.
     */
    private function isZeroDecimal(string $value): bool
    {
        return bccomp($value, '0', 10) === 0;
    }

    /**
     * Valida el importe antes de hacer aritmetica con el.
     */
    private function guardAmount(int $amountMinor): void
    {
        if ($amountMinor < 0) {
            throw RuleNotApplicable::negativeAmount($amountMinor);
        }

        if ($amountMinor > self::MAX_AMOUNT_MINOR) {
            throw RuleNotApplicable::amountOutOfRange($amountMinor);
        }
    }

    /**
     * Verifica que la moneda de la operacion sea la que espera la regla.
     *
     * Sin este chequeo, un ticket en dolares aplicado a una regla en pesos
     * acreditaria puntos a la tasa equivocada sin que nada lo delate. El importe
     * se RECHAZA en lugar de ignorarse: acreditar en silencio es peor que no
     * acreditar, porque el cliente cree que ha ganado puntos que no existen y el
     * comercio los tiene que pagar.
     */
    public function assertCurrencyMatches(LoyaltyConfig $config, string $currency): void
    {
        if (strcasecmp($currency, $config->currency) !== 0) {
            throw UnsupportedCurrency::make($currency, $config->currency);
        }
    }
}
