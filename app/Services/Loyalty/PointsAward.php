<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

/**
 * Resultado de calcular los puntos de una operacion.
 *
 * Se devuelve un objeto y no un entero a proposito. El POS necesita responder a
 * tres preguntas distintas y responderlas con el entero solo obligaria al
 * controller a repetir el calculo o a adivinar:
 *
 *  - cuantos puntos se otorgan (la cifra que ve el cliente),
 *  - si la cifra esta recortada por un tope, y por cual,
 *  - y por que se otorgo cero cuando la compra no calificaba.
 *
 * Que el motivo de un cero sea visible evita el ticket de soporte mas caro de un
 * programa de fidelizacion: el cliente que compra y no acumula, y el cajero que
 * no sabe explicar por que.
 */
final readonly class PointsAward
{
    private function __construct(
        public int $points,
        public ?int $rawPoints,
        public ?string $cappedBy,
        public ?string $notEarnedReason,
    ) {}

    /**
     * Otorgamiento completo, sin recortes.
     */
    public static function full(int $points): self
    {
        return new self($points, $points, null, null);
    }

    /**
     * Otorgamiento recortado por un tope. `rawPoints` conserva el valor teorico
     * para poder informar "accreditaste 100 en vez de 150 por tope diario".
     */
    public static function capped(int $points, int $rawPoints, string $cappedBy): self
    {
        return new self($points, $rawPoints, $cappedBy, null);
    }

    /**
     * No se otorgaron puntos por una regla de elegibilidad.
     */
    public static function denied(string $reason): self
    {
        return new self(0, 0, null, $reason);
    }

    public function wasCapped(): bool
    {
        return $this->cappedBy !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'points' => $this->points,
            'raw_points' => $this->wasCapped() ? $this->rawPoints : null,
            'capped_by' => $this->cappedBy,
            'not_earned_reason' => $this->notEarnedReason,
        ], fn ($value): bool => $value !== null);
    }
}
