<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Naturaleza del premio del catalogo. Determina como se materializa el canje
 * y que campos son obligatorios.
 */
enum RewardType: string
{
    /** Descuento porcentual sobre el total de la proxima compra. Ej: 20% off. */
    case PercentDiscount = 'percent_discount';

    /** Descuento de monto fijo sobre el total de la proxima compra. */
    case FixedDiscount = 'fixed_discount';

    /** Producto o servicio del catalogo del comercio sin costo. */
    case FreeItem = 'free_item';

    /** Gift card prepaga de un tercero, emitida como codigo de un solo uso. */
    case GiftCard = 'gift_card';

    /**
     * Si el premio genera un codigo de canje de un solo uso que el cajero valida
     * en el POS. Los descuentos sobre compra se aplican en linea, sin codigo.
     */
    public function requiresRedemptionCode(): bool
    {
        return $this === self::GiftCard || $this === self::FreeItem;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
