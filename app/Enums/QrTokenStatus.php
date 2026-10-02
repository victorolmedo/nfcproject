<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida del token efimero del QR dinamico (Flujo B).
 *
 * La transicionIrreversibleConsumed es la garantia antifraude: el paso a
 * Consumed se hace con un UPDATE condicional sobre el status, de modo que dos
 * escaneos simultaneos del mismo QR solo pueden tener exito una vez.
 */
enum QrTokenStatus: string
{
    /** Emitido por la caja, pendiente de escaneo por el cliente. */
    case Issued = 'issued';

    /** Canjeado una unica vez. Estado terminal: impide doble acreditacion. */
    case Consumed = 'consumed';

    /** Vencio su ventana de validez sin ser canjeado. */
    case Expired = 'expired';

    /** Anulado manualmente por el cajero (error de operacion, ticket cerrado). */
    case Voided = 'voided';

    /**
     * Estados a partir de los cuales el token ya no puede consumirse.
     *
     * @return array<int, string>
     */
    public function isTerminal(): bool
    {
        return $this !== self::Issued;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
