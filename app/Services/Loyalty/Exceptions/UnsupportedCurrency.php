<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * La moneda de la operacion no coincide con la de la regla del tenant.
 *
 * Se RECHAZA en vez de ignorarse. Acreditar en silencio es peor que no acreditar:
 * el cliente ve unos puntos que nunca llegan, y el comercio los asume como deuda
 * cuando se detecte. Un rechazo visible hace que el cajero selecione la moneda
 * correcta.
 */
class UnsupportedCurrency extends RuntimeException implements RendersApiError
{
    private function __construct(
        string $message,
        private readonly string $received,
        private readonly string $expected,
    ) {
        parent::__construct($message);
    }

    public static function make(string $received, string $expected): self
    {
        return new self(
            "La operacion esta en {$received} y el programa opera en {$expected}. "
            .'No se acreditan puntos con una moneda distinta a la de la regla.',
            $received,
            $expected,
        );
    }

    public function errorCode(): string
    {
        return 'loyalty.currency_mismatch';
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
        return [
            'received_currency' => $this->received,
            'expected_currency' => $this->expected,
        ];
    }
}
