<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que otorga el QR dinamico al ser canjeado por el cliente.
 */
enum QrClaimType: string
{
    /** Otorga una cantidad fija de puntos configurada por la caja en el momento de emitir. */
    case FixedPoints = 'fixed_points';

    /** Registra una visita; los puntos se calculan segun la regla vigente del tenant. */
    case VisitStamp = 'visit_stamp';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
