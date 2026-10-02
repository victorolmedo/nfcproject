<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use App\Models\Customer;
use RuntimeException;

/**
 * El cliente no puede operar con puntos en su estado actual.
 *
 * Un cliente suspendido o archivado conserva su saldo y su historial, pero no
 * acumula ni canjea. La distincion importa: no se borra nada ni se toca el
 * ledger, solo se rechaza la operacion nueva. Si el cliente vuelve a estar
 * activo, su saldo sigue ahi.
 */
class CustomerNotEligible extends RuntimeException implements RendersApiError
{
    private function __construct(string $message, private readonly int $customerId)
    {
        parent::__construct($message);
    }

    public static function cannotEarn(Customer $customer): self
    {
        return new self(
            "El cliente {$customer->public_id} esta en estado "
            .'"'.$customer->status->value.'" y no puede acumular puntos.',
            (int) $customer->getKey(),
        );
    }

    public static function cannotRedeem(Customer $customer): self
    {
        return new self(
            "El cliente {$customer->public_id} esta en estado "
            .'"'.$customer->status->value.'" y no puede canjear puntos.',
            (int) $customer->getKey(),
        );
    }

    public function errorCode(): string
    {
        return 'loyalty.customer_not_eligible';
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
        return ['customer_id' => $this->customerId];
    }
}
