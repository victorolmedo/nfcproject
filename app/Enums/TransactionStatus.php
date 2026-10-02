<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del ciclo de vida de la transaccion. Solo Posted impacta el saldo del cliente.
 */
enum TransactionStatus: string
{
    /** Creada pero todavia no suma al saldo (reserva mientras se confirma el pago). */
    case Pending = 'pending';

    /** Confirmada. El saldo del cliente ya fue actualizado. */
    case Posted = 'posted';

    /** Anulada antes de postear. No impacta el saldo. */
    case Voided = 'voided';

    /** Fallo tecnico irrecuperable. No impacta el saldo y requiere intervencion. */
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
