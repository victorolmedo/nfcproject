<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de asiento en el libro mayor de puntos (append-only).
 *
 * A diferencia de TransactionType, este enum no distingue el proposito comercial
 * sino la naturaleza contable del asiento. El ledger es la unica fuente de verdad
 * del saldo y nunca se modifica ni se borra.
 */
enum LedgerEntryType: string
{
    /** Asiento de acreditacion. */
    case Earn = 'earn';

    /** Asiento de canje. */
    case Redeem = 'redeem';

    /** Asiento de ajuste manual. */
    case Adjust = 'adjust';

    /** Asiento de expiracion. */
    case Expire = 'expire';

    /** Asiento de anulacion. */
    case Reverse = 'reverse';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
