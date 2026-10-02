<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RewardType;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(8)),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'reward_type' => RewardType::FixedDiscount,
            'points_required' => 100,
            'value_minor' => 5000,
            'redemption_valid_days' => 90,
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
            'metadata' => [],
        ];
    }

    public function percentOff(int $percent = 20, int $points = 200): static
    {
        return $this->state(fn (): array => [
            'reward_type' => RewardType::PercentDiscount,
            'points_required' => $points,
            'value_minor' => null,
            'value_percent' => $percent,
        ]);
    }

    public function fixedOff(int $valueMinor = 5000, int $points = 100): static
    {
        return $this->state(fn (): array => [
            'reward_type' => RewardType::FixedDiscount,
            'points_required' => $points,
            'value_minor' => $valueMinor,
            'value_percent' => null,
        ]);
    }

    public function giftCard(int $valueMinor = 20000, int $points = 400): static
    {
        return $this->state(fn (): array => [
            'reward_type' => RewardType::GiftCard,
            'points_required' => $points,
            'value_minor' => $valueMinor,
            'value_percent' => null,
        ]);
    }

    public function freeItem(int $points = 80): static
    {
        return $this->state(fn (): array => [
            'reward_type' => RewardType::FreeItem,
            'points_required' => $points,
            'value_minor' => null,
            'value_percent' => null,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['inventory' => 0]);
    }

    public function limited(int $units): static
    {
        return $this->state(fn (): array => ['inventory' => $units]);
    }
}
