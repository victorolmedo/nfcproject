<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QrClaimType;
use App\Enums\QrTokenStatus;
use App\Models\QrToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QrToken>
 */
class QrTokenFactory extends Factory
{
    protected $model = QrToken::class;

    /**
     * Ventana por defecto del token. El producto pide 3 a 5 minutos; 4 es el
     * punto medio que deja margen para que el cajerolea el QR impreso y el
     * cliente lo escanee sin correr.
     */
    public const DEFAULT_TTL_MINUTES = 4;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'jti' => (string) Str::uuid(),
            'claim_type' => QrClaimType::VisitStamp,
            'points_awarded' => null,
            'amount_minor' => null,
            'currency' => 'MXN',
            'status' => QrTokenStatus::Issued,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(self::DEFAULT_TTL_MINUTES),
            'metadata' => [],
        ];
    }

    public function fixedPoints(int $points): static
    {
        return $this->state(fn (): array => [
            'claim_type' => QrClaimType::FixedPoints,
            'points_awarded' => $points,
        ]);
    }

    public function expiringIn(int $seconds): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->addSeconds($seconds),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => QrTokenStatus::Issued,
            'issued_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => [
            'status' => QrTokenStatus::Consumed,
            'consumed_at' => now(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => QrTokenStatus::Voided,
            'voided_at' => now(),
        ]);
    }
}
