<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origen del movimiento. Alimenta las metricas de adopcion por canal de venta
 * y las reglas antifraude (un token de QR solo puede consumirse una vez, y desde
 * un unico origen).
 */
enum TransactionSource: string
{
    /** Flujo A: el cajero escaneo el pase del cliente desde el navegador. */
    case PosScanClient = 'pos_scan_client';

    /** Flujo B: el cliente escaneo el QR dinamico generado por la caja. */
    case PosDynamicQr = 'pos_dynamic_qr';

    /** Evento entrante de Google Wallet o Apple Wallet. */
    case WalletCallback = 'wallet_callback';

    /** Accion manual de un usuario del panel admin. */
    case Admin = 'admin';

    /** Proceso automatico: job programado, expiracion, reconciliacion. */
    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
