<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Redondeo aplicado al convertir un monto en puntos. Define si una diferencia de
 * un punto entre el calculo del POS y el del backend es un bug o no.
 */
enum PointsRounding: string
{
    /** Trunca hacia abajo. El cliente nunca percibe puntos que el negocio no contabilizo. */
    case Down = 'down';

    /** Redondeo matematico estandar: .5 sube. */
    case HalfUp = 'half_up';

    /** Redondeo hacia arriba a favor del cliente. Solo con margen unitario. */
    case Up = 'up';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
