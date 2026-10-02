<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como se calcula la acreditacion de puntos en una compra.
 */
enum LoyaltyRuleType: string
{
    /** Puntos proporcionales al monto de la compra. Ej: $100 = 10 puntos. */
    case Amount = 'amount';

    /** Puntos fijos por cada visita, sin importar el monto (sujeto a compra minima). */
    case Visit = 'visit';

    /** Puntos fijos por visita mas un porcentaje adicional del monto. */
    case Hybrid = 'hybrid';

    /**
     * Si la regla usa el monto de la compra para calcular puntos.
     */
    public function usesAmount(): bool
    {
        return $this === self::Amount || $this === self::Hybrid;
    }

    /**
     * Si la regla otorga una base de puntos fija por visita.
     */
    public function usesVisit(): bool
    {
        return $this === self::Visit || $this === self::Hybrid;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
