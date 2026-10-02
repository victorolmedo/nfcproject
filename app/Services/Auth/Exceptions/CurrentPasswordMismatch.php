<?php

declare(strict_types=1);

namespace App\Services\Auth\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * La contrasena actual no coincide.
 *
 * Se distingue del 401 de credenciales invalidas a proposito. Aqui el usuario esta
 * autenticado y por tanto sabemos quien es; lo que falla es la prueba de que sigue
 * siendo el titular de la cuenta. Un POS debe poder diferenciar "tu sesion caduco"
 * de "esa no es tu contrasena", porque la reaccion del usuario es distinta en cada
 * caso.
 */
class CurrentPasswordMismatch extends RuntimeException implements RendersApiError
{
    public static function make(): self
    {
        return new self('La contrasena actual no es correcta.');
    }

    public function errorCode(): string
    {
        return 'auth.current_password_mismatch';
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
        return ['field' => 'current_password'];
    }
}
