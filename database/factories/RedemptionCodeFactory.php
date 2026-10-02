<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RedemptionCodeStatus;
use App\Models\Customer;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RedemptionCode>
 */
class RedemptionCodeFactory extends Factory
{
    protected $model = RedemptionCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(Str::random(4).'-'.Str::random(4));

        return [
            'customer_id' => Customer::factory(),
            'reward_id' => Reward::factory(),
            'transaction_id' => Transaction::factory(),
            'code' => $code,
            'code_hash' => hash('sha256', $code),
            'status' => RedemptionCodeStatus::Active,
            'value_minor' => 20000,
            'currency' => 'MXN',
            'expires_at' => now()->addDays(90),
            'metadata' => [],
        ];
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'status' => RedemptionCodeStatus::Used,
            'used_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => RedemptionCodeStatus::Active,
            'expires_at' => now()->subDay(),
        ]);
    }
}
