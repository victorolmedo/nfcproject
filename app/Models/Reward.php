<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\RewardType;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Premio del catalogo del tenant.
 */
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use BelongsToTenant;

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'title',
        'description',
        'image_url',
        'reward_type',
        'points_required',
        'value_minor',
        'value_percent',
        'redemption_valid_days',
        'inventory',
        'max_redemptions_per_customer',
        'max_redemptions_total',
        'valid_from',
        'valid_until',
        'is_active',
        'is_featured',
        'sort_order',
        'terms',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reward_type' => RewardType::class,
            'points_required' => 'integer',
            'value_minor' => 'integer',
            'value_percent' => 'decimal:2',
            'redemption_valid_days' => 'integer',
            'inventory' => 'integer',
            'redemptions_count' => 'integer',
            'max_redemptions_per_customer' => 'integer',
            'max_redemptions_total' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RedemptionCode::class);
    }

    /**
     * Premios que el cliente puede ver en su pase: activos y dentro de vigencia.
     * El filtro de saldo se aplica aparte porque depende del cliente.
     *
     * @param  Builder<Reward>  $query
     */
    public function scopeCurrentlyAvailable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()));
    }

    /**
     * Quedan unidades: o el inventario es ilimitado, o hay stock.
     *
     * @param  Builder<Reward>  $query
     */
    public function scopeInStock(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('inventory')->orWhere('inventory', '>', 0));
    }

    public function hasStock(): bool
    {
        return $this->inventory === null || $this->inventory > 0;
    }

    public function isRedeemableBy(int $currentPoints): bool
    {
        return $this->points_required > 0
            && $currentPoints >= $this->points_required
            && $this->hasStock()
            && $this->is_active;
    }

    /**
     * Normaliza el codigo de premio para comparar de forma estable.
     *
     * El cliente lo teclea o lo escanea, asi que "cafe-10", "CAFE 10" y "cafe10"
     * deben ser el mismo premio. Se eliminan espacios y separadores y se pasa a
     * mayusculas. La forma canonica que se persiste es la de `Str::upper()` sin
     * separadores, para que el indice unico no dependa de como lo escribio el
     * admin.
     */
    public static function normalizeCode(string $code): string
    {
        return Str::upper(preg_replace('/[\s\-_]+/', '', trim($code)));
    }

    public function requiresRedemptionCode(): bool
    {
        return $this->reward_type->requiresRedemptionCode();
    }
}
