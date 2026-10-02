<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Emision y revocacion de tokens de API (Sanctum).
 *
 * Decisiones:
 *
 *  - TTL corto por defecto (12 horas). Un token de POS vive una jornada de caja;
 *    uno de 30 dias es una credencial de larga duracion que no tiene sentido para
 *    alguien que abre y cierra un turno. El admin puede emitir uno de larga
 *    duracion para integraciones, pero tiene que pedirlo explicitamente.
 *
 *  - Las abilities se guardan en el token SOLO para introspeccion y para la UI de
 *    "dispositivos activos". La autorizacion real no las consulta: se derivan del
 *    rol vigente del usuario en cada peticion. Asi, degradar un cajero a
 *    suspended o rebajarlo de admin a cajero surte efecto en la siguiente
 *    peticion, sin necesidad de revocar tokens.
 */
class TokenIssuer
{
    /** Turno de caja largo. */
    public const TTL_DEFAULT_HOURS = 12;

    /** Solo para integraciones servidor-a-servidor, y requiere rol admin u owner. */
    public const TTL_LONG_HOURS = 720;

    /**
     * Emite un token para un usuario.
     *
     * @param  bool  $longLived  requiere rol admin u owner; se valida aca y no en
     *                           el controller para que ningun endpoint futuro
     *                           pueda olvidarse.
     */
    public function issue(
        User $user,
        Request $request,
        string $deviceName,
        bool $longLived = false,
    ): PersonalAccessTokenResult {
        $ttlHours = $longLived ? self::TTL_LONG_HOURS : self::TTL_DEFAULT_HOURS;

        // Admin cubre a Owner por jerarquia (rank 2 >= 2 es falso para owner, pero
        // Owner rank 3 >= 2 es cierto), asi que esta comprobacion admite a ambos
        // y rechaza al cajero, que es justo el objetivo.
        if ($longLived && ! $user->hasStaffRole(StaffRole::Admin)) {
            throw Exceptions\LongLivedTokenNotAllowed::forUser($user->email);
        }

        /*
         * `$expiresAt` se pasa como objeto, no como string. La firma de
         * `HasApiTokens::createToken()` es `?DateTimeInterface`; pasar la fecha
         * ya formateada es un TypeError, no un fallo silencioso.
         */
        $newToken = $user->createToken(
            $deviceName,
            $user->abilities(),
            Date::now()->addHours($ttlHours),
        );

        $accessToken = $newToken->accessToken;

        /*
         * `tenant_id` ya viene resuelto: el hook `creating` de
         * `App\Models\PersonalAccessToken` lo rellena desde el contexto activo, y
         * tiene que ser en el INSERT porque la politica RLS de esta tabla valida
         * WITH CHECK en esa misma sentencia.
         *
         * Aqui solo queda `device_label`, que es cosmetico y puede ir en un UPDATE
         * posterior sin riesgo de violar la politica.
         */
        $accessToken->forceFill([
            'device_label' => $this->describeDevice($request),
        ])->save();

        return new PersonalAccessTokenResult(
            token: $newToken->plainTextToken,
            expiresAt: Carbon::parse($accessToken->expires_at),
            tokenId: $accessToken->id,
        );
    }

    /**
     * Revoca el token de la peticion actual.
     *
     * Usa revocacion blanda (columna `revoked_at`) en vez de borrar la fila, para
     * conservar evidencia: "este dispositivo intento operar despues de que le
     * cortaron el acceso" es exactamente lo que se necesita investigar.
     */
    public function revokeCurrent(User $user, ?int $tokenId, ?int $revokedBy = null): void
    {
        if ($tokenId === null) {
            return;
        }

        $user->tokens()
            ->whereKey($tokenId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Date::now(),
                'revoked_by_user_id' => $revokedBy,
            ]);
    }

    /**
     * Revoca todos los tokens de un usuario.
     */
    public function revokeAllFor(User $user, ?int $revokedBy = null): int
    {
        return $user->tokens()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Date::now(),
                'revoked_by_user_id' => $revokedBy,
            ]);
    }

    /**
     * Descripcion legible del dispositivo, deducida del user agent.
     *
     * Sirve para que un admin que ve cinco tokens activos sepa cual es suyo y
     * cual hay que cortar. No es una huella forense: es un etiqueta.
     */
    private function describeDevice(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        $platform = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Desconocido',
        };

        return "{$browser} en {$platform}";
    }
}
