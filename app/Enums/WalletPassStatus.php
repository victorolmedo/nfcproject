<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de sincronizacion entre nuestro backend y la billetera del cliente.
 */
enum WalletPassStatus: string
{
    /** Solicitud de emision enviada, esperando confirmacion de la plataforma. */
    case Pending = 'pending';

    /** Pase emitido y confirmado: el cliente lo tiene en su billetera. */
    case Active = 'active';

    /** El cliente elimino el pase de su billetera o la plataforma notifico la baja. */
    case Deleted = 'deleted';

    /** La ultima operacion contra la plataforma fallo. Requiere reintento o intervencion. */
    case Error = 'error';

    /**
     * Solo un pase activo puede recibir updates push.
     */
    public function acceptsUpdates(): bool
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
