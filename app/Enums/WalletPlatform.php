<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Plataforma de billetera que hospeda el pase del cliente.
 */
enum WalletPlatform: string
{
    /** Apple Wallet: archivo .pkpass firmado y webServiceURL para updates push. */
    case Apple = 'apple';

    /** Google Wallet: loyaltyObject en la Google Wallet API. */
    case Google = 'google';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
