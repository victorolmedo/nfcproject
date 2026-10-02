<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\WalletPassStatus;
use App\Enums\WalletPlatform;
use Database\Factories\WalletPassFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emision y sincronizacion de un pase con una billetera.
 *
 * `state_version` es un contador monotonico que se incrementa en cada cambio. Sirve
 * como control de concurrencia optimista entre el job de actualizacion y el
 * callback del cliente: si llegan dos updates a la vez, el segundo descarta su
 * respuesta por tener una version vieja.
 */
class WalletPass extends Model
{
    /** @use HasFactory<WalletPassFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'customer_id',
        'platform',
        'object_id',
        'serial_number',
        'pass_type_id',
        'status',
        'last_request',
        'last_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'platform' => WalletPlatform::class,
            'status' => WalletPassStatus::class,
            'state_version' => 'integer',
            'consecutive_failures' => 'integer',
            'last_request' => 'array',
            'last_response' => 'array',
            'metadata' => 'array',
            'issued_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<WalletPass>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', WalletPassStatus::Active->value);
    }

    /**
     * Pases que pueden recibir un update push.
     *
     * En Apple, ademas del estado, el cliente tiene que haber confirmado el alta
     * del pase: sin eso PassKit no tiene un deviceLibraryIdentifier y el web
     * service devuelve 401. Por eso el filtro incluye `apple_pass_saved`.
     *
     * @param  Builder<WalletPass>  $query
     */
    public function scopePushable(Builder $query): void
    {
        $query->where('status', WalletPassStatus::Active->value)
            ->where(function (Builder $q): void {
                $q->where('platform', WalletPlatform::Google->value)
                    ->orWhere(function (Builder $apple): void {
                        $apple->where('platform', WalletPlatform::Apple->value)
                            ->whereHas('customer', fn (Builder $c) => $c->where('apple_pass_saved', true));
                    });
            });
    }

    /**
     * @param  Builder<WalletPass>  $query
     */
    public function scopeNeedsRetry(Builder $query): void
    {
        $query->where('status', WalletPassStatus::Error->value)
            ->where('consecutive_failures', '<', 5);
    }
}
