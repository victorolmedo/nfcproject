<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * Base de los errores de estado de una cuenta YA autenticada.
 *
 * Se separan del 401 de "no se quien eres" a proposito. Un 401 invita al cliente
 * a reautenticarse, y reautenticarse no va a funcionar: la cuenta esta
 * desactivada o bloqueada. Un POS que reintenta entra en un bucle, y un operador
 * que ve ese bucle no sabe si el problema es su sesion o el servidor.
 *
 * Una clase por archivo, sin excepcion: PSR-4 resuelve cada clase a su propia
 * ruta, asi que meter tres clases en un archivo deja dos de ellas sin
 * autoload.
 */
abstract class StaffAccountProblem extends RuntimeException implements RendersApiError
{
    protected function __construct(string $message)
    {
        parent::__construct($message);
    }
}
