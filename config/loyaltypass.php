<?php

declare(strict_types=1);

/**
 * Configuracion de la plataforma LoyaltyPass.
 *
 * Todo lo que un despliegue pueda necesitar cambiar sin tocar codigo vive aqui.
 * Las credenciales de Apple y Google NO se leen de este archivo directamente:
 * este solo define las rutas, y las clases que las consumen resuelven el secreto
 * real desde el secret store (Vault, AWS Secrets Manager, o .env en local).
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Resolucion de tenant
    |---------------------------------------------------------------------------
    |
    | Como se determina a que comercio pertenece una peticion. El orden de
    | intento es: dominio personalizado -> host de subdominio -> header explicito.
    |
    |   subdomain: cafe-aurora.loyaltypass.app  (produccion, recomendado)
    |   header:    X-Tenant-Slug: cafe-aurora   (desarrollo, integraciones)
    |   path:      /t/{slug}/...                 (APIs sin dominio propio)
    |
    | El dominio por defecto NO debe ser resoluble como tenant: si lo es, un
    | host vacio o mal formado podria caer en un tenant por defecto.
    |
    */

    'root_domain' => env('LOYALTYPASS_ROOT_DOMAIN', 'localhost:8000'),

    'tenant_driver' => env('LOYALTYPASS_TENANT_DRIVER', 'subdomain'),

    'header_name' => 'X-Tenant-Slug',

    'reserved_subdomains' => [
        'www', 'app', 'api', 'admin', 'mail', 'cdn', 'status', 'support',
    ],

    /*
    |---------------------------------------------------------------------------
    | API publica
    |---------------------------------------------------------------------------
    |
    | URL base que consumen los pases y la landing de canje. Debe ser HTTPS en
    | produccion: el token del QR dinamico viaja en la URL de la landing.
    |
    */

    'public_api_url' => env('LOYALTYPASS_PUBLIC_API_URL', 'http://localhost:8000/api/v1'),

    /*
    |---------------------------------------------------------------------------
    | Flujo B: QR dinamico
    |---------------------------------------------------------------------------
    |
    | La ventana de validez del token. El producto pide 3 a 5 minutos: menos de 3
    | es insuficiente para que el cliente lo lea tranquilo, y mas de 5 amplifica
    | la ventana de exposicion si el QR se imprime y se deja en la mesa.
    |
    */

    'qr' => [
        'ttl_minutes' => (int) env('LOYALTYPASS_QR_TTL_MINUTES', 4),
        'issuer' => env('LOYALTYPASS_QR_ISSUER', 'loyaltypass'),
        'audience' => env('LOYALTYPASS_QR_AUDIENCE', 'loyaltypass-pos'),

        /*
         * Longitud del nonce del fingerprint. 64 hex = SHA-256. Se usa para
         * detectar que un mismo token fue escaneado desde dispositivos distintos,
         * senal clasica de comparticion de QR.
         */
        'fingerprint_bytes' => 32,
    ],

    /*
    |---------------------------------------------------------------------------
    | Idempotencia
    |---------------------------------------------------------------------------
    |
    | Retencion de las claves de idempotencia. 24 horas cubre el reintento de
    * un POS con red mala, que es el caso que la justifica. Mas alla de eso la
    | fila solo ocupa espacio.
    |
    */

    'idempotency' => [
        'header' => 'Idempotency-Key',
        'retention_hours' => 24,
    ],

    /*
    |---------------------------------------------------------------------------
    | Google Wallet
    |---------------------------------------------------------------------------
    |
    | La clase Loyalty de Google Wallet no tiene costo por pase. Se requiere un
    | proyecto de Google Cloud con la API habilitada y una cuenta de servicio
    | con acceso de editor a la clase.
    |
    | Referencia: https://developers.google.com/wallet/loyalty
    |
    */

    'google_wallet' => [
        'project_id' => env('GOOGLE_WALLET_PROJECT_ID'),
        'service_account_path' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_PATH'),
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        'class_id' => env('GOOGLE_WALLET_CLASS_ID'),
        'webhook_url' => env('GOOGLE_WALLET_WEBHOOK_URL'),
        'base_url' => 'https://walletobjects.googleapis.com',
        'timeout' => 10,
    ],

    /*
    |---------------------------------------------------------------------------
    | Apple Wallet
    |---------------------------------------------------------------------------
    |
    | El .pkpass se firma en servidor con el certificado del Pass Type ID. El
    | certificado y la clave NUNCA se versionan: viven en un secret store y
    | `pass.php` los lee desde disco en tiempo de ejecucion.
    |
    | Referencia: https://developer.apple.com/passkit/passkit-api/
    |
    */

    'apple_wallet' => [
        'team_id' => env('APPLE_TEAM_ID'),
        'pass_type_id' => env('APPLE_PASS_TYPE_ID', 'pass.com.loyaltypass.cafe'),
        'pass_cert_path' => env('APPLE_PASS_CERT_PATH'),
        'pass_key_path' => env('APPLE_PASS_KEY_PATH'),
        'wwdr_cert_path' => env('APPLE_WWDR_CERT_PATH'),
        'web_service_auth_secret' => env('APPLE_WEBSERVICE_AUTH_SECRET'),
        'timeout' => 10,

        /*
         * Si los certificados van a rotarse, el secreto de autenticacion del web
         * service tambien. Guardar el secreto actual permite emitir un token de
         * transicion sin invalidar los pases ya emitidos.
         */
        'previous_web_service_auth_secret' => env('APPLE_PREVIOUS_WEBSERVICE_AUTH_SECRET'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Storage
    |---------------------------------------------------------------------------
    |
    | Los pases de Apple se construyen en memoria y se sirven; los logos de
    * tenant y las imagenes de premio van a S3. Los .pkpass NO se persisten en
    * disco de aplicacion: son reproducibles y se regeneran bajo demanda.
    |
    */

    'storage' => [
        'pass_disk' => env('LOYALTYPASS_PASS_DISK', 'local'),
        'asset_disk' => env('LOYALTYPASS_ASSET_DISK', 's3'),
    ],

];
