<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida comercial del tenant (comercio).
 */
enum TenantStatus: string
{
    /** El tenant fue creado pero aun no completo el onboarding ni tiene pases emitidos. */
    case Trial = 'trial';

    /** Tenant operativo: puede emitir pases, recibir compras y canjear premios. */
    case Active = 'active';

    /**
     * Operacion pausada por impago o incumplimiento. Los pases siguen en el telefono
     * del cliente, pero el backend rechaza la acreditacion de puntos.
     */
    case Suspended = 'suspended';

    /** Tenant dado de baja. Se conservan los datos por auditoria, pero no es accesible. */
    case Archived = 'archived';

    /**
     * Un tenant suspended o archived no puede mover saldos. El personal solo puede
     * consultar y exportar.
     */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
