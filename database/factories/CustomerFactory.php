<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_e164' => '+521551234567',
            'locale' => 'es-MX',
            'timezone' => 'America/Mexico_City',
            'status' => CustomerStatus::Active,

            // El saldo se deja en 0 a proposito: los factories NUNCA inventan saldos.
            // Se llegara a el via PointLedgerService, que es el unico camino valido.
            // Fabricar un current_points aqui produciria estados imposibles y
            // tests que pasan sin ejercitar el ledger.
            'current_points' => 0,
            'lifetime_points_earned' => 0,
            'lifetime_points_redeemed' => 0,
            'enrolled_at' => now(),
            'metadata' => [],
        ];
    }

    public function withPoints(int $points): static
    {
        return $this->state(fn (): array => [
            'current_points' => $points,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Suspended]);
    }

    public function anonymized(): static
    {
        return $this->state(fn (): array => [
            'status' => CustomerStatus::Anonymized,
            'full_name' => null,
            'email' => null,
            'phone_e164' => null,
            'anonymized_at' => now(),
        ]);
    }

    public function withApplePass(): static
    {
        return $this->state(fn (): array => [
            'pass_serial_number' => 'APPL-'.fake()->unique()->bothify('??????????'),
            'apple_pass_saved' => true,
            'apple_device_library_identifier' => (string) fake()->unique()->uuid(),
        ]);
    }

    public function withGooglePass(): static
    {
        return $this->state(fn (): array => [
            'google_wallet_object_id' => 'projects/loyaltypass/loyaltyObject/'.fake()->unique()->uuid(),
            'google_pass_saved' => true,
        ]);
    }
}
