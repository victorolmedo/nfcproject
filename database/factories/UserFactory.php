<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => StaffRole::Cashier,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
        ]);
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => StaffRole::Owner]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => StaffRole::Admin]);
    }

    public function cashier(): static
    {
        return $this->state(fn (): array => ['role' => StaffRole::Cashier]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => [
            'locked_until' => now()->addMinutes(15),
            'failed_login_attempts' => 5,
        ]);
    }
}
