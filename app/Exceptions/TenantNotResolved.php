<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando se intenta operar sobre datos de tenant sin contexto resuelto.
 *
 * Es un fallo de programacion, no un error de negocio. Se traduce a HTTP 500 de
 * forma deliberada: un 4XX sugeriria que el cliente puede corregirlo, y no puede.
 * Lo que se quiere es que salte una alerta en Sentry en staging y que en
 * produccion aparezca en el log con el stack trace completo.
 */
class TenantNotResolved extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(sprintf(
            'No hay tenant resuelto al construir una consulta sobre [%s]. '
            .'El middleware SetTenant debe ejecutarse antes, o el modelo debe usar withoutTenantScope().',
            $model,
        ));
    }

    public static function forQuery(): self
    {
        return new self(
            'No hay tenant resuelto en el contexto de la peticion. '
            .'Verificar que el host o el header X-Tenant-* se resolvio correctamente.'
        );
    }
}
