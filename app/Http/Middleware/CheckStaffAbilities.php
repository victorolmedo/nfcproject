<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprueba permisos contra el ROL VIGENTE, no contra los que tenia el token.
 *
 * Por que no sirve el middleware de Sanctum (`CheckAbilities`): lee el array
 * `abilities` congelado en la fila del token, en el momento de emitirlo. Con esa
 * informacion, degradar a un administrador a cajero no surte efecto hasta que se
 * vuelva a autenticar, y el token sigue concedes `staff.write` y `billing.read`
 * durante las 12 horas de su vida. Es justo el escenario que mas dano causa: un
 *empleado al que se le quiere quitar acceso antes de que se vaya de la empresa.
 *
 * La autorizacion se deriva aqui de `User::abilities()`, que lee el rol de la
 * fila del usuario. Como el guard ya cargo esa fila, el coste es cero consultas
 * extra y el cambio de rol aplica en la siguiente peticion, sin revoked tokens.
 *
 * Lo que se guarda en el token pasa a ser informativo: sirve para auditar con que
 * permisos entro un dispositivo, no para decidir.
 *
 * Se lanza `AuthorizationException`, igual que el middleware original, para que
 * el renderer global lo convierta en el 403 de siempre y el POS pueda distinguir
 * "no tienes permiso" de "sesion caducada".
 */
class CheckStaffAbilities
{
    /**
     * El usuario debe tener TODAS las abilities indicadas.
     *
     * Uso: `ability:pos.redeem`, `ability:rewards.read,rewards.write`
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $this->ensureAuthenticated($request);

        $granted = $this->abilitiesFor($request->user());

        foreach ($abilities as $ability) {
            if (! in_array($ability, $granted, true)) {
                throw new AuthorizationException(
                    "This action is unauthorized. Falta la ability [{$ability}].",
                );
            }
        }

        return $next($request);
    }

    /**
     * El usuario debe tener AL MENOS UNA de las abilities indicadas.
     *
     * Existe para el caso "lectura o escritura": exigir las dos a la vez seria
     * exigir escritura, que es lo contrario de lo que se quiere en un listado.
     */
    public function handleAny(Request $request, Closure $next, string ...$abilities): Response
    {
        $this->ensureAuthenticated($request);

        $granted = $this->abilitiesFor($request->user());

        foreach ($abilities as $ability) {
            if (in_array($ability, $granted, true)) {
                return $next($request);
            }
        }

        throw new AuthorizationException(
            'This action is unauthorized. Se requiere al menos una de: '.implode(', ', $abilities).'.',
        );
    }

    /**
     * @return array<int, string>
     */
    private function abilitiesFor(mixed $user): array
    {
        if ($user instanceof User) {
            return $user->abilities();
        }

        /*
         * Un `tokenable` que no sea un User (por ejemplo un cliente con pase que
         * tiene token) cae aqui. Se recurre a las abilities congeladas en su token:
         * es el unico dato disponible para ese caso, y para el personal (que es lo
         * que cubre este middleware) nunca se llega a esta rama.
         */
        $token = $user?->currentAccessToken();

        return is_array($token?->abilities) ? $token->abilities : [];
    }

    /**
     * Si no hay usuario, responde 401 y no 403.
     *
     * Un 403 para unAnonimo induce al cliente a pensar que "se identifico y no le
     * dejan", y a reintentar con otras credenciales en bucle.
     */
    private function ensureAuthenticated(Request $request): void
    {
        abort_if($request->user() === null, 401, 'Se requiere autenticacion.');
    }
}
