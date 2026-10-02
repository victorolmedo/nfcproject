<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\CredentialsVerifier;
use App\Services\Auth\Exceptions\InvalidCredentials;
use App\Services\Auth\Exceptions\LongLivedTokenNotAllowed;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Emision de tokens de acceso.
 *
 * Flujo completo y por que esta ordenado asi:
 *
 *  1. Buscar el usuario por email YA normalizado a minusculas, con el tenant
 *     activo. La busqueda no puede salir del tenant: el scope de Eloquent y RLS
 *     lo garantizan, de modo que un admin del comercio A no puede autenticarse
 *     con una cuenta del comercio B aunque conozca las credenciales.
 *
 *  2. Verificar la password. Se ejecuta aunque el usuario no exista (ver
 *     CredentialsVerifier) para no filtrar por tiempo de respuesta que correos
 *     estan registrados.
 *
 *  3. Si falla, contar el intento y lanzar. El bloqueo por cuenta complementa al
 *     rate limit por IP: el primero frena un ataque dirigido contra una cuenta
 *     concreta, el segundo frena un ataque disperso desde muchos IP.
 */
class LoginController extends ApiController
{
    public function __invoke(
        LoginRequest $request,
        CredentialsVerifier $verifier,
        TokenIssuer $tokens,
    ): JsonResponse {
        $email = Str::lower(trim($request->string('email')->toString()));

        $user = User::query()->where('email', $email)->first();

        $valid = $verifier->verify($user, $email, $request->string('password')->toString());

        if (! $valid || $user === null) {
            /*
             * `registerFailure` devuelve la excepcion en vez de lanzarla para que
             * el camino de error quede explicito aqui. Lancarla dentro del helper
             * haria inalcanzable todo lo que viene despues, y un `return` de
             * seguridad despues de un `throw` invisible es exactamente el tipo de
             * codigo que revisa uno a la ligera y da por bueno.
             */
            throw $this->registerFailure($verifier, $user);
        }

        $verifier->registerSuccess($user, $request->ip() ?? '0.0.0.0');

        try {
            $issued = $tokens->issue(
                $user,
                $request,
                $request->string('device_name')->toString(),
                longLived: $request->boolean('remember_device'),
            );
        } catch (LongLivedTokenNotAllowed $e) {
            return $this->fromDomainError($e);
        }

        return $this->success([
            'token' => $issued->token,
            'token_type' => 'Bearer',
            'expires_at' => $issued->expiresAt->toIso8601String(),
            'user' => new UserResource($user),
            'tenant' => new TenantResource($user->tenant),
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Suma el intento fallido y construye el error de respuesta.
     *
     * La cuenta de intentos restantes solo se expone si el usuario existe y no
     * esta bloqueado. En cualquier otro caso vale null, para que la respuesta no
     * cambie de forma segun exista o no la cuenta.
     */
    private function registerFailure(CredentialsVerifier $verifier, ?User $user): InvalidCredentials
    {
        if ($user === null) {
            // No hay a quien imputarle el intento: el rate limit por IP es la
            // unica defensa posible en este caso.
            return InvalidCredentials::generic();
        }

        if ($user->isLocked()) {
            // No se incrementa el contador: hacerlo extenderia el bloqueo de
            // forma indefinida con cada intento posterior.
            return InvalidCredentials::generic();
        }

        $verifier->registerFailure($user);

        return InvalidCredentials::generic($verifier->remainingAttempts($user));
    }
}
