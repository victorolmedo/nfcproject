<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Error de dominio que sabe como presentarse en la API.
 *
 * Implementar esta interfaz es lo que permite que el renderer global convierta la
 * excepcion en una respuesta JSON coherente, sin que cada controller tenga que
 * envolver sus llamadas en try/catch.
 *
 * `code` es estable y parte del contrato publico: el POS lo usa para decidir si
 * reintentar (`tenant.not_operational`) o mostrar un mensaje al usuario
 * (`auth.invalid_credentials`). `message` es para humanos y puede cambiar.
 */
interface RendersApiError
{
    /**
     * Codigo estable, en notacion de puntos. Ej: `auth.invalid_credentials`.
     */
    public function errorCode(): string;

    /**
     * Codigo HTTP con el que debe responderse.
     */
    public function errorStatus(): int;

    /**
     * Detalles estructurados opcionales, para que el cliente pueda reaccionar sin
     * parsear el mensaje.
     *
     * @return array<string, mixed>
     */
    public function errorDetails(): array;
}
