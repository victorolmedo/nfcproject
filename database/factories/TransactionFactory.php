<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $delta = fake()->numberBetween(10, 200);

        return [
            'customer_id' => Customer::factory(),
            'operator_id' => null,
            'type' => TransactionType::Earn,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::PosScanClient,
            'amount_spent_minor' => fake()->numberBetween(5000, 100000),
            'currency' => 'MXN',
            'points_delta' => $delta,
            'points_balance_after' => $delta,
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }

    public function by(User $operator): static
    {
        return $this->state(fn (): array => ['operator_id' => $operator->id]);
    }

    public function fromDynamicQr(): static
    {
        return $this->state(fn (): array => [
            'source' => TransactionSource::PosDynamicQr,
        ]);
    }

    public function redeem(int $points, int $balanceAfter): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Redeem,
            'source' => TransactionSource::PosScanClient,
            'points_delta' => -$points,
            'points_balance_after' => $balanceAfter,
            'amount_spent_minor' => 0,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => TransactionStatus::Pending]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => ['status' => TransactionStatus::Voided]);
    }
}
