<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\RedemptionCodeStatus;
use Database\Factories\RedemptionCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codigo de canje de un solo uso.
 *
 * El codigo en claro existe solo para poder mostraselo al cajero y al cliente. En
 * la base, la columna que se indexa y se compara es `code_hash`.
 */
class RedemptionCode extends Model
{
    /** @use HasFactory<RedemptionCodeFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'customer_id',
        'reward_id',
        'transaction_id',
        'code',
        'code_hash',
        'status',
        'value_minor',
        'value_percent',
        'currency',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => RedemptionCodeStatus::class,
            'value_minor' => 'integer',
            'value_percent' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @param  Builder<RedemptionCode>  $query
     */
    public function scopeRedeemable(Builder $query): void
    {
        $query->where('status', RedemptionCodeStatus::Active->value)
            ->where('expires_at', '>', now());
    }

    public function isRedeemable(): bool
    {
        return $this->status === RedemptionCodeStatus::Active && ! $this->expires_at->isPast();
    }
}
