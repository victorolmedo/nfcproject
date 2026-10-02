<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\PointLedger;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PointLedger>
 */
class PointLedgerFactory extends Factory
{
    protected $model = PointLedger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $points = fake()->numberBetween(10, 200);

        return [
            'customer_id' => Customer::factory(),
            'entry_type' => LedgerEntryType::Earn,
            'points' => $points,
            'balance_after' => $points,
            'ref_type' => 'external_ref',
            'ref_id' => (string) Str::uuid(),
            'recorded_at' => now(),
        ];
    }

    public function debit(int $points, int $balanceAfter): static
    {
        return $this->state(fn (): array => [
            'entry_type' => LedgerEntryType::Redeem,
            'points' => -$points,
            'balance_after' => $balanceAfter,
        ]);
    }
}
