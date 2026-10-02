<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dominio adicional de un tenant.
 *
 * No usa BelongsToTenant: se consulta durante la resolucion del tenant, antes de
 * que exista un contexto.
 */
class TenantDomain extends Model
{
    protected $fillable = [
        'host',
        'is_primary',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Resolucion de tenant: por dominio exacto primero, luego por slug, luego
     * por dominio por defecto. Solo se consideran tenants operativos.
     */
    public function scopeResolvable(Builder $query): void
    {
        $query->where('status', TenantStatus::Active->value);
    }
}
