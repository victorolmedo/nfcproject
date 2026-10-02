<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Services\Loyalty\PointCalculator;
use Database\Factories\LoyaltyConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reglas del programa de fidelizacion. Una unica fila por tenant.
 */
class LoyaltyConfig extends Model
{
    /** @use HasFactory<LoyaltyConfigFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'name',
        'rule_type',
        'points_per_minor_unit',
        'points_per_visit',
        'min_purchase_minor',
        'currency',
        'max_points_per_transaction',
        'max_points_per_day',
        'rounding',
        'allow_negative_balance',
        'points_expiry_months',
        'welcome_points',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => LoyaltyRuleType::class,
            'rounding' => PointsRounding::class,
            'points_per_minor_unit' => 'decimal:8',
            'points_per_visit' => 'integer',
            'min_purchase_minor' => 'integer',
            'max_points_per_transaction' => 'integer',
            'max_points_per_day' => 'integer',
            'allow_negative_balance' => 'boolean',
            'points_expiry_months' => 'integer',
            'welcome_points' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * El calculo real de puntos NO vive en el modelo. Va en un service con
     * aritmetica decimal exacta, para que el mismo input produzca siempre el
     * mismo output y sea trivial de testear sin base de datos.
     *
     * @see PointCalculator
     */
    public function isEffectiveAt(\DateTimeInterface $moment): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from !== null && $moment < $this->effective_from) {
            return false;
        }

        return ! ($this->effective_to !== null && $moment > $this->effective_to);
    }
}
