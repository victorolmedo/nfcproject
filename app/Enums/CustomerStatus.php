<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del cliente enrolado en el programa de un tenant.
 */
enum CustomerStatus: string
{
    /** Cliente habilitado para acumular y canjear puntos. */
    case Active = 'active';

    /** Cliente retenido por fraude o uso indebido. El pase sigue visible pero no opera. */
    case Suspended = 'suspended';

    /** Derecho de supresion ejercido: PII anonimizada, se conservan saldos y ledger. */
    case Anonymized = 'anonymized';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
