<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\TenantNotResolved;
use App\TenantContext;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token de API de Sanctum con `tenant_id` obligatorio desde el INSERT.
 *
 * Por que hace falta una subclase en lugar de usar el modelo de Sanctum tal cual:
 *
 * `HasApiTokens::createToken()` inserta con un conjunto fijo de atributos
 * (name, token, abilities, expires_at, tokenable_*) y NO incluye `tenant_id`.
 * `personal_access_tokens` tiene RLS estricto (`tenant_id = app_current_tenant_id()`),
 * asi que ese INSERT nace violando la politica y PostgreSQL lo rechaza con
 * "new row violates row-level security policy".
 *
 * El arreglo obvious, insertar y despues hacer un UPDATE para llenar
 * `tenant_id`, NO funciona: el UPDATE nunca llega a ejecutarse porque la
 * politica WITH CHECK ya rechazo la fila. El `tenant_id` tiene que existir en el
 * momento del INSERT.
 *
 * Este modelo resuelve el problema con un hook `creating`, que es el mismo
 * mecanismo que usa `BelongsToTenant` para el resto del dominio. El contexto ya
 * esta establecido cuando se emite un token, porque el login solo es alcanzable
 * despues de que `ResolveTenant` corrio.
 *
 * El modelo se registra en AppServiceProvider con `Sanctum::usePersonalAccessTokenModel()`.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Busca un token por su valor en claro, ignorando los revocados.
     *
     * Este override no es cosmetico: es lo que hace que la revocacion funcione.
     *
     * Sanctum no tiene el concepto de "token revocado". Su modelo oficial borra
     * la fila cuando se invalida, y el Guard solo comprueba `expires_at`. Como
     * aqui la revocacion es BLANDA (columna `revoked_at`, para conservar evidencia
     * de auditoria), sin este override un token revocado seguiria autenticando
     * indefinidamente: `findToken()` lo encontraria, `expires_at` seguiria en
     * futuro y el Guard lo daria por valido.
     *
     * Traducir "revocado" a "no encontrado" es ademas la respuesta correcta desde
     * el punto de vista del cliente: un token revocado y un token inexistente
     * deben ser indistinguibles, y ambos producen el mismo 401.
     *
     * @param  string  $token  valor en claro, con o sin el prefijo `id|`
     * @return static|null
     */
    public static function findToken($token)
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        // Sanctum admite el formato `id|token-en-claro` para tokens de app movil.
        $value = str_contains($token, '|')
            ? explode('|', $token, 2)[1]
            : $token;

        return static::where('token', hash('sha256', $value))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * `tokenable` se redeclara para devolver el tipo concreto, que es lo que
     * permite que el IDE y el analizador estatico conozcan el tipo de retorno.
     * Sanctum lo declara como `MorphTo` generico y no puede saber el nombre de
     * esta clase.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if ($token->tenant_id !== null) {
                return;
            }

            $tenantId = TenantContext::id();

            /*
             * Fail-closed, igual que `BelongsToTenant`.
             *
             * Sin tenant en el contexto no se puede saber de que comercio es el
             * token, y un token sin comercio es una credencial valida para toda la
             * plataforma. Es preferible un 500 ruidoso en el login que una
             * credencial huerfana.
             */
            if ($tenantId === null) {
                throw TenantNotResolved::forModel(static::class);
            }

            $token->tenant_id = $tenantId;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
