<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class StaffAccountDisabled extends StaffAccountProblem
{
    public static function for(string $email): self
    {
        return new self(
            "La cuenta [{$email}] esta desactivada. Contacta con el administrador del comercio.",
        );
    }

    public function errorCode(): string
    {
        return 'auth.account_disabled';
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
