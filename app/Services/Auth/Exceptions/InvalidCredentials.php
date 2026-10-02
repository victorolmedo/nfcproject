<?php

declare(strict_types=1);

namespace App\Services\Auth\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * Credenciales no aceptadas.
 *
 * Un unico tipo de excepcion para "usuario inexistente", "password incorrecta",
 * "cuenta inactiva" y "cuenta bloqueada". Es deliberado: si cada caso devolviera
 * un mensaje distinto, la API se convertiria en un oraculo que confirma que ese
 * correo esta registrado en la plataforma.
 *
 * `attemptsRemaining` solo se incluye cuando hay un usuario identificable Y la
 * cuenta no esta bloqueada, para que la respuesta no cambie de forma segun exista
 * o no la cuenta.
 */
class InvalidCredentials extends RuntimeException implements RendersApiError
{
    private function __construct(
        string $message,
        public readonly ?int $attemptsRemaining = null,
    ) {
        parent::__construct($message);
    }

    public static function generic(?int $attemptsRemaining = null): self
    {
        return new self('Las credenciales no son validas.', $attemptsRemaining);
    }

    public function errorCode(): string
    {
        return 'auth.invalid_credentials';
    }

    public function errorStatus(): int
    {
        // 401 y no 422: el recurso existe y la peticion esta bien formada, lo
        // que falla es la autenticacion. Un 422 haria que un cliente REST estandar
        // lo interpretase como un problema de validacion de campos.
        return 401;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return $this->attemptsRemaining === null
            ? []
            : ['attempts_remaining' => $this->attemptsRemaining];
    }
}
