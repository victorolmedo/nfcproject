<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del codigo de canje de un solo uso entregado al cliente por un premio.
 */
enum RedemptionCodeStatus: string
{
    /** Emitido, pendiente de uso en el comercio. */
    case Active = 'active';

    /** Consumido por el cajero. Estado terminal. */
    case Used = 'used';

    /** Vencio segun la vigencia configurada en el premio. */
    case Expired = 'expired';

    /** Anulado por el administrador (premio no entregado, fraude). */
    case Voided = 'voided';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
