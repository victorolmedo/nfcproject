<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * El cliente no tiene puntos suficientes para el canje.
 *
 * El mensaje dice exactamente cuanto le falta. "Saldo insuficiente" a secas
 * obliga al cajero a hacer cuentas a mano en un mostrador, con el cliente
 * esperando; la diferencia se calcula en el servidor, donde ya se conoce.
 */
class InsufficientPoints extends RuntimeException implements RendersApiError
{
    private function __construct(
        string $message,
        private readonly int $available,
        private readonly int $required,
    ) {
        parent::__construct($message);
    }

    public static function make(int $available, int $required): self
    {
        $missing = $required - $available;

        return new self(
            "El cliente tiene {$available} puntos y el premio cuesta {$required}. "
            .'Le faltan '.$missing.' puntos.',
            $available,
            $required,
        );
    }

    public function errorCode(): string
    {
        return 'loyalty.insufficient_points';
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
            'available_points' => $this->available,
            'required_points' => $this->required,
            'missing_points' => $this->required - $this->available,
        ];
    }
}
