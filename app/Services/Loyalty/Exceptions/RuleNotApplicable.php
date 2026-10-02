<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * La operacion no se puede evaluar contra la regla vigente.
 *
 * Se distingue de "la regla no acredito puntos" (que es un resultado valido y
 * frecuente: compra por debajo del minimo) porque aqui hay un dato incoherente que
 * hay que corregir en origen. Un ticket de $-50 no es una compra pequena, es un
 * error de captura del cajero, y tratarlo como compra de cero puntos lo esconderia.
 */
class RuleNotApplicable extends RuntimeException implements RendersApiError
{
    private function __construct(string $message, private readonly string $apiCode)
    {
        parent::__construct($message);
    }

    public static function negativeAmount(int $amountMinor): self
    {
        return new self(
            "El importe no puede ser negativo (se recibio {$amountMinor}). "
            .'Revisa el cobro antes de acreditar puntos.',
            'loyalty.negative_amount',
        );
    }

    public static function amountOutOfRange(int $amountMinor): self
    {
        return new self(
            "El importe {$amountMinor} esta fuera del rango admitido. "
            .'Revisa que no venga en centavos de mas o con un error de captura.',
            'loyalty.amount_out_of_range',
        );
    }

    public function errorCode(): string
    {
        return $this->apiCode;
    }

    public function errorStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return [];
    }
}
