<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'legal_name' => $name.' S.A.',
            'tax_id' => Str::random(11),
            'logo_url' => 'https://placehold.co/240x240/0F172A/F59E0B?text='.Str::upper(Str::substr($name, 0, 1)),
            'primary_color' => '#0F172A',
            'secondary_color' => '#F59E0B',
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
            'status' => TenantStatus::Active,
            'settings' => [],
            'activated_at' => now(),
        ];
    }

    public function trial(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Trial,
            'activated_at' => null,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
