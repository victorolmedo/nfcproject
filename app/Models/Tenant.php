<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Comercio cliente de la plataforma.
 *
 * No usa BelongsToTenant: es la unica entidad que se puede leer sin contexto de
 * tenant previo, precisamente porque es la que se resuelve para crearlo.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Genera el `uuid` propio en lugar de usar HasUuids, porque ese trait asume
     * que el uuid ES la clave primaria. Aqui la PK sigue siendo `id` y el uuid es
     * una columna unica adicional para exposicion en URLs y logs.
     */
    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            $tenant->uuid ??= (string) Str::uuid7();
        });
    }

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'tax_id',
        'logo_url',
        'primary_color',
        'secondary_color',
        'timezone',
        'currency',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /**
     * `uuid` se genera con HasUuids y es unico: se usa en URLs publicas y en los
     * logs para identificar un comercio sin exponer el id autoincremental.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function loyaltyConfig(): HasOne
    {
        return $this->hasOne(LoyaltyConfig::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /**
     * Un tenant no puede operar si no esta activo. Centraliza la regla para que
     * no dependa de que cada endpoint recuerde chequearla.
     */
    public function isOperational(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
