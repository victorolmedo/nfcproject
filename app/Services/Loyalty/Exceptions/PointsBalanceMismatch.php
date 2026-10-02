<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * Un dato de la peticion no puede cuadrar con el saldo del cliente.
 *
 * Son fallos de programacion o de peticion manipulada, no de negocio: un coste de
 * canje en cero o negativo haria que la operacion "restara" saldo en vez de
 * consumirlo. Se rechazan en la frontera en lugar de aceptarse y normalizar,
 * porque un canje con coste negativo es un ingreso ilimitado de puntos.
 */
class PointsBalanceMismatch extends RuntimeException implements RendersApiError
{
    private function __construct(string $message, private readonly int $cost)
    {
        parent::__construct($message);
    }

    public static function invalidCost(int $cost): self
    {
        return new self(
            "El coste del premio debe ser un entero positivo (se recibio {$cost}).",
            $cost,
        );
    }

    public function errorCode(): string
    {
        return 'loyalty.invalid_cost';
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
        return ['cost_points' => $this->cost];
    }
}
