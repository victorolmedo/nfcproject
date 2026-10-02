<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol del personal del tenant. Define permisos en el POS y en el panel admin.
 */
enum StaffRole: string
{
    /** Dueno del comercio. Puede eliminar el tenant, gestionar facturacion y todos los permisos. */
    case Owner = 'owner';

    /** Administrador. Gestiona reglas, premios, personal y reportes. No puede eliminar el tenant. */
    case Admin = 'admin';

    /** Operador de caja. Solo acumula y canjea puntos en el POS. */
    case Cashier = 'cashier';

    /**
     * Orden de jerarquia. Un rol incluye todos los permisos de los que le son inferiores.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Cashier => 1,
            self::Admin => 2,
            self::Owner => 3,
        };
    }

    /**
     * Indica si este rol puede ejecutar una accion reservada al rol indicado.
     */
    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
