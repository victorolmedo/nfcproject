<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Models\LoyaltyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyConfig>
 */
class LoyaltyConfigFactory extends Factory
{
    protected $model = LoyaltyConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Programa estandar',
            'rule_type' => LoyaltyRuleType::Amount,

            // 1 punto por cada 10 pesos (1000 centavos).
            'points_per_minor_unit' => 0.001,
            'points_per_visit' => 0,

            'min_purchase_minor' => 0,
            'currency' => 'MXN',
            'max_points_per_transaction' => 1000,
            'max_points_per_day' => 5000,
            'rounding' => PointsRounding::Down,
            'allow_negative_balance' => false,
            'points_expiry_months' => null,
            'welcome_points' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Regla por visita: 10 puntos por cada compra, con minimo de $50.
     */
    public function perVisit(int $points = 10, int $minPurchaseMinor = 5000): static
    {
        return $this->state(fn (): array => [
            'rule_type' => LoyaltyRuleType::Visit,
            'points_per_minor_unit' => 0,
            'points_per_visit' => $points,
            'min_purchase_minor' => $minPurchaseMinor,
        ]);
    }

    /**
     * Regla hibrida: 5 puntos por visita + 1 punto por cada 10 pesos.
     */
    public function hybrid(int $visitPoints = 5): static
    {
        return $this->state(fn (): array => [
            'rule_type' => LoyaltyRuleType::Hybrid,
            'points_per_minor_unit' => 0.001,
            'points_per_visit' => $visitPoints,
        ]);
    }
}
