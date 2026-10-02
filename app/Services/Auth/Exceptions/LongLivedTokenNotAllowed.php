<?php

declare(strict_types=1);

namespace App\Services\Auth\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

class LongLivedTokenNotAllowed extends RuntimeException implements RendersApiError
{
    public static function forUser(string $email): self
    {
        /*
         * Solo se pasa el mensaje. El constructor de `Exception` espera
         * `(string $message, int $code, ?Throwable $previous)`: pasar aqui el codigo
         * de API en texto y el status HTTP produce un TypeError en tiempo de
         * ejecucion, no un error de compilacion. El codigo de API y el status viven
         * en `errorCode()` y `errorStatus()`.
         */
        return new self(
            "El usuario [{$email}] no puede solicitar tokens de larga duracion: "
            .'se reserva a administradores para integraciones servidor a servidor.',
        );
    }

    public function errorCode(): string
    {
        return 'auth.long_lived_token_forbidden';
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
