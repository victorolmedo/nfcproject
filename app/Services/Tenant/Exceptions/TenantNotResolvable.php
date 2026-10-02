<?php

declare(strict_types=1);

namespace App\Services\Tenant\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * El host de la peticion no permite identificar un tenant.
 *
 * Se distingue de `App\Exceptions\TenantNotResolved` (que es un bug de
 * programacion: se toco un modelo de dominio sin pasar por el middleware) porque
 * este es un error de peticion legitimo: el cliente llego a una URL que no
 * pertenece a ningun comercio, y merece un 400 que lo oriente, no un 500.
 */
class TenantNotResolvable extends RuntimeException implements RendersApiError
{
    public function __construct(
        string $message,
        private readonly string $apiCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function unknownHost(string $host): self
    {
        return new self(
            "No se pudo identificar el comercio a partir del host [{$host}]. "
            .'Verifica la URL o el header de tenant.',
            'tenant.not_resolvable',
            400,
        );
    }

    public static function inactiveTenant(string $slug, string $status): self
    {
        return new self(
            "El comercio [{$slug}] no esta operativo (estado: {$status}).",
            'tenant.not_operational',
            403,
        );
    }

    public function errorCode(): string
    {
        /*
         * El nombre de la propiedad es `$apiCode` y no `$code` por una reason
         * concreta: `Exception` ya declara un atributo publico llamado `$code`, y
         * redeclararlo como `readonly` es un error fatal de PHP que impide
         * cargar la clase. El nombre avoids la colision sin tocar la clase base.
         */
        return $this->apiCode;
    }

    public function errorStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return [];
    }
}
