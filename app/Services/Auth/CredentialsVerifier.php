<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Verificacion de credenciales con proteccion contra ataques de tiempo.
 *
 * El problema que resuelve: si el login responde en 40 ms para un email
 * inexistente y en 320 ms para uno existente, un atacante puede enumerar que
 * correos estan dados de alta en la plataforma con una diferencia de 300 ms por
 * intento. No necesita la password, solo la medicion.
 *
 * La correccion es hacer siempre el mismo trabajo: cuando el usuario no existe
 * se verifica igualmente una password falsa con un hash real. bcrypt cuesta lo
 * mismo exista o no el usuario, asi que el tiempo de respuesta se empareja.
 *
 * Importante: el hash dummy se genera UNA vez por proceso y se cachea. Generarlo
 * en cada intento anadiria ~100 ms al camino negativo y crearia la diferencia que
 * se intenta evitar.
 */
class CredentialsVerifier
{
    /**
     * Hash descartable de una password que nadie tiene.
     *
     * Se genera con el mismo factor de coste que el hash de application's, para
     * que el trabajo computacional sea comparable.
     */
    private static ?string $dummyHash = null;

    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCKOUT_MINUTES = 15;

    /**
     * Intenta autenticar. Devuelve el usuario solo si la password es correcta.
     *
     * @param  string  $email  correo normalizado a minusculas
     */
    public function verify(?User $user, string $email, string $password): bool
    {
        $hash = $user?->password ?? $this->dummyHash();

        // `Hash::check` se ejecuta SIEMPRE, incluso si el usuario no existe.
        $valid = Hash::check($password, $hash);

        if (! $valid || $user === null) {
            return false;
        }

        return $this->passesBusinessRules($user);
    }

    /**
     * Reglas que no dependen de la password pero que igual deben pasar el mismo
     * camino de trabajo para no filtrar informacion.
     */
    private function passesBusinessRules(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return ! $user->isLocked();
    }

    /**
     * Registra un fallo y bloquea la cuenta si se supero el limite.
     */
    public function registerFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_FAILED_ATTEMPTS
                ? now()->addMinutes(self::LOCKOUT_MINUTES)
                : null,
        ])->saveQuietly();
    }

    public function registerSuccess(User $user, string $ip): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();
    }

    /**
     * Intentos restantes antes del bloqueo. Se usa en el mensaje de respuesta
     * para que un usuario legitimo que se equivoco sepa quantos intentos le
     * quedan, sin revelar si la cuenta existe cuando ya esta bloqueada.
     */
    public function remainingAttempts(User $user): int
    {
        return max(0, self::MAX_FAILED_ATTEMPTS - $user->failed_login_attempts);
    }

    private function dummyHash(): string
    {
        if (self::$dummyHash !== null) {
            return self::$dummyHash;
        }

        return self::$dummyHash = Hash::make(Str::random(40));
    }
}
