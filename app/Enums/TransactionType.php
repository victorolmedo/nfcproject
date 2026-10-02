<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Naturaleza del movimiento de puntos. Define el signo aplicado al saldo.
 */
enum TransactionType: string
{
    /** Acreditacion por compra. Suma puntos. */
    case Earn = 'earn';

    /** Debito por canje de un premio del catalogo. Resta puntos. */
    case Redeem = 'redeem';

    /** Ajuste manual del administrador con motivo obligatorio. */
    case Adjust = 'adjust';

    /** Baja por expiracion de puntos segun politica del tenant. */
    case Expire = 'expire';

    /** Anulacion de un movimiento previo por devolucion o error de caja. */
    case Reverse = 'reverse';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
