<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Support\Carbon;

final class StaffAccountLocked extends StaffAccountProblem
{
    public static function until(string $email, ?Carbon $until): self
    {
        return new self(
            $until === null
                ? "La cuenta [{$email}] esta bloqueada temporalmente."
                // El instante exacto se devuelve al operador: sin el, un usuario
                // bloqueado no sabe cuando reintentar y acaba llamando al comercio
                // para preguntar, que es lo que el bloqueo queria evitar.
                : "La cuenta [{$email}] esta bloqueada hasta las {$until->toIso8601String()}.",
        );
    }

    public function errorCode(): string
    {
        return 'auth.account_locked';
    }

    public function errorStatus(): int
    {
        return 403;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return [];
    }
}
