<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cierre de sesion del dispositivo actual.
 *
 * Se revoca el token con el que vino la peticion, no todos los del usuario. Es la
 * diferencia entre "salir de esta tablet" y "expulsar a este empleado de todos
 * sus terminales", que es una accion distinta y mucho mas delicada.
 */
class LogoutController extends ApiController
{
    public function __invoke(Request $request, TokenIssuer $tokens): JsonResponse
    {
        $tokens->revokeCurrent(
            $request->user(),
            $request->user()->currentAccessToken()?->id,
            $request->user()->id,
        );

        return $this->success(['message' => 'Sesion cerrada.']);
    }
}
