<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\CustomerStatus;
use App\Enums\WalletPlatform;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente enrolado en el programa de un tenant.
 *
 * `public_id` (ULID) es el identificador que viaja dentro del QR del pase. El
 * cajero no ve jamas el id numerico: escanea, el POS resuelve el public_id y llama
 * a la API. Es opaco, no contiene PII y es legible en logs.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'pass_serial_number',
        'google_wallet_object_id',
        'apple_device_library_identifier',
        'apple_authentication_token',
        'apple_pass_saved',
        'google_pass_saved',
        'full_name',
        'email',
        'phone_e164',
        'locale',
        'timezone',
        'status',
        'metadata',
    ];

    /**
     * El saldo y los contadores acumulados NUNCA son asignables en masse.
     *
     * Si `current_points` estuviera en `$fillable`, cualquier `Customer::create()`
     * o `update()` con datos de request podria fixar un saldo arbitrario y
     * saltear el ledger. Se cambian exclusivamente a traves de PointLedgerService.
     */
    protected $guarded = [
        'current_points',
        'lifetime_points_earned',
        'lifetime_points_redeemed',
    ];

    protected function casts(): array
    {
        return [
            'current_points' => 'integer',
            'lifetime_points_earned' => 'integer',
            'lifetime_points_redeemed' => 'integer',
            'apple_pass_saved' => 'boolean',
            'google_pass_saved' => 'boolean',
            'status' => CustomerStatus::class,
            'metadata' => 'array',
            'enrolled_at' => 'immutable_datetime',
            'last_earned_at' => 'immutable_datetime',
            'last_redeemed_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'marketing_consent_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    /**
     * Dirige el ULID a `public_id` en lugar de a la clave primaria.
     *
     * El trait HasUlids, sin esto, reemplaza la PK por un ULID string. Aqui la PK
     * sigue siendo `id` autoincremental (barata de indexar y de joinear) y
     * `public_id` es el identificador opaco que viaja en el QR del pase.
     *
     * Efecto colateral util: `resolveRouteBindingQuery` del trait valida que el
     * valor de la ruta sea un ULID antes de tocar la base, lo que convierte un
     * `GET /customers/1-or-1' UNION SELECT` en un 404 inmediato.
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PointLedger::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(RedemptionCode::class);
    }

    public function walletPasses(): HasMany
    {
        return $this->hasMany(WalletPass::class);
    }

    public function applePass(): HasOne
    {
        return $this->hasOne(WalletPass::class, 'customer_id')
            ->where('platform', WalletPlatform::Apple->value);
    }

    public function googlePass(): HasOne
    {
        return $this->hasOne(WalletPass::class, 'customer_id')
            ->where('platform', WalletPlatform::Google->value);
    }

    public function canEarn(): bool
    {
        return $this->status === CustomerStatus::Active;
    }

    public function canRedeem(): bool
    {
        return $this->status === CustomerStatus::Active;
    }

    /**
     * Aplica un impacto en puntos al saldo materializado.
     *
     * `current_points` y los contadores `lifetime_*` estan en `$guarded`, asi que
     * ningun `create()` ni `update()` con datos de request puede fijarlos. Este
     * metodo es el UNICO camino para tocarlos, y conviene que lo sea: estos campos
     * son una cache del ledger, no la fuente de verdad, y escribirlos desde fuera
     * del servicio abre la puerta a que el saldo deje de cuadrar con el ledger sin aviso.
     *
     * Se descuenta del contador de canjes y se suma al de ganados SEGUN EL SIGNO
     * del delta, no segun el tipo de transaccion. Los canjes restan puntos pero no
     * son "puntos ganados", asi que un canje nunca debe inflar el historial de
     * acumulaciones del cliente.
     *
     * El saldo nuevo se devuelve para poder escribirlo en el asiento y en la
     * cabecera, que deben coincidir exactamente con el valor de aqui.
     */
    public function applyPointsDelta(int $delta): int
    {
        $this->current_points += $delta;

        if ($delta > 0) {
            $this->lifetime_points_earned += $delta;
        } elseif ($delta < 0) {
            $this->lifetime_points_redeemed += abs($delta);
        }

        return $this->current_points;
    }

    /**
     * Metadatos de facturacion para construir el pase. Se agrupa porque Apple y
     * Google piden estructuras distintas y la logica de mapeo vive en los drivers.
     */
    public function walletProfile(): array
    {
        return [
            'id' => $this->public_id,
            'first_name' => str($this->full_name ?? '')->before(' ')->toString(),
            'last_name' => str($this->full_name ?? ' ')->after(' ')->toString(),
            'email' => $this->email,
            'locale' => $this->locale,
        ];
    }
}
