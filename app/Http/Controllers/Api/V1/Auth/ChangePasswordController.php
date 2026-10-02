<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\Auth\Exceptions\CurrentPasswordMismatch;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Cambio de contrasena del usuario autenticado.
 *
 * Detalle de seguridad relevante: si el usuario pide cerrar el resto de sesiones,
 * se revocan TODOS sus tokens, incluido el de esta peticion. No es arbitrario,
 * es lo que se espera: quien cambia la contrasena porque cree que se la
 * comprometieron necesita que el intruso quede fuera, y dejar vivo el token del
 * propio dispositivo obligaria al usuario a hacer logout/login a mano justo
 * cuando cree que esta en peligro.
 */
class ChangePasswordController extends ApiController
{
    public function __invoke(ChangePasswordRequest $request, TokenIssuer $tokens): JsonResponse
    {
        $user = $request->user();

        /*
         * Verificacion explicita contra el usuario autenticado, en vez de la regla
         * `current_password` de Laravel. Ver el comentario en ChangePasswordRequest:
         * esa regla consulta el guard por DEFECTO (`web`), no `sanctum`, y produce
         * un 422 sin explicacion cuyo comportamiento depende del estado del guard.
         */
        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            return $this->fromDomainError(CurrentPasswordMismatch::make());
        }

        $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();

        $revoked = 0;

        if ($request->boolean('logout_others')) {
            $revoked = $tokens->revokeAllFor($user, $user->id);
        }

        return $this->success([
            'message' => 'Contrasena actualizada.',
            'sessions_revoked' => $revoked,
        ]);
    }
}
