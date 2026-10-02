<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TenantNotResolved;
use App\Models\User;
use App\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticacion del personal: identidad Y cuenta en condiciones de operar.
 *
 * Es un unico middleware y no dos (`auth:sanctum` mas un chequeo de `is_active`)
 * por un motivo concreto de seguridad.
 *
 * El guard de Sanctum resuelve el usuario a partir del token y no mira nada mas:
 * no comprueba `is_active`, ni `locked_until`, ni el estado del comercio. Si los
 * dos controles se declararan por separado en cada ruta, bastaria con que alguien
 * olvidara el segundo en una ruta nueva para que una cuenta desactivada siguiera
 * operando con el token que ya tenia, y el forget seria invisible en revision
 * porque la ruta "parece" protegida.
 *
 * Aqui no hay dos piezas que sincronizar: o se usa este middleware o no se
 * autentica a nadie.
 */
class AuthenticateStaff
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Guarda de seguridad: si no hay tenant resuelto, la peticion llego aqui por
         * una ruta que no esta dentro del grupo `api` y por tanto no paso por
         * `ResolveTenant`.
         *
         * Sin este chequeo, el resultado natural seria un 401 silencioso: RLS no
         * devuelve filas sin GUC, el guard no encuentra el token y responde "no
         * autenticado". Eso es seguro, pero oculta la causa real, y un 401 en un
         * endpoint que si tiene token es un fallo de desarrollo que se pierde de
         * vista. Con este error, el problema es visible desde el primer dia.
         */
        if (TenantContext::id() === null) {
            throw TenantNotResolved::forModel(self::class);
        }

        $guard = $this->auth->guard('sanctum');

        /*
         * Se replica el criterio de `Illuminate\Auth\Middleware\Authenticate` en
         * lugar de envolverlo, porque ese middleware no ofrece un punto de
         * extension: no hay forma de ejecutar codigo "dentro" de el sin
         * reimplementar el chequeo o usar un pipeline anidado.
         *
         * La excepcion es la misma que lanza el middleware original, asi que el
         * renderer global la convierte en el mismo 401 de siempre.
         */
        if (! $guard->check()) {
            throw new AuthenticationException('Unauthenticated.', [$guard], 'sanctum');
        }

        $user = $guard->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.', [$guard], 'sanctum');
        }

        /*
         * Sin esto, `$request->user()` en los controllers devolveria null y el
         * fallo apareceria como un error de codigo en el controller mas lejano, en
         * lugar de en el punto de la peticion donde realmente esta el problema.
         */
        $request->setUserResolver(fn (): User => $user);

        if (! $user->is_active) {
            throw StaffAccountDisabled::for($user->email);
        }

        // Una cuenta bloqueada por intentos fallidos tampoco puede seguir
        // operando con un token que ya se le emitio.
        if ($user->isLocked()) {
            throw StaffAccountLocked::until($user->email, $user->locked_until);
        }

        return $next($request);
    }
}
