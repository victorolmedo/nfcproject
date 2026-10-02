<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WalletPassStatus;
use App\Enums\WalletPlatform;
use App\Models\Customer;
use App\Models\WalletPass;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletPass>
 */
class WalletPassFactory extends Factory
{
    protected $model = WalletPass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'platform' => WalletPlatform::Google,
            'object_id' => 'projects/loyaltypass/loyaltyObject/'.Str::uuid(),
            'status' => WalletPassStatus::Pending,
            'last_request' => [],
            'last_response' => [],
            'metadata' => [],
        ];
    }

    public function apple(?string $serial = null): static
    {
        $serial ??= 'APPL-'.Str::upper(Str::random(12));

        return $this->state(fn (): array => [
            'platform' => WalletPlatform::Apple,
            'object_id' => $serial,
            'serial_number' => $serial,
            'pass_type_id' => 'pass.com.loyaltypass.cafe',
        ]);
    }

    public function google(?string $objectId = null): static
    {
        $objectId ??= 'projects/loyaltypass/loyaltyObject/'.Str::uuid();

        return $this->state(fn (): array => [
            'platform' => WalletPlatform::Google,
            'object_id' => $objectId,
            'serial_number' => null,
            'pass_type_id' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletPassStatus::Active,
            'issued_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletPassStatus::Error,
            'last_error' => fake()->sentence(),
            'last_error_at' => now(),
            'consecutive_failures' => 1,
        ]);
    }
}
