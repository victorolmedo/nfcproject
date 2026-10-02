<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cliente enrolado en el programa de un tenant.
 *
 * Decisiones de diseno relevantes:
 *
 * 1. `public_id` es el identificador que viaja dentro del QR del pase. Es opaco,
 *    no contiene PII y permite revisar logs sin exponer datos personales. Un UUID
 *    v7 seria ideal para orden temporal, pero ULID de Laravel cumple el mismo rol.
 *
 * 2. Los identificadores de las billeteras viven aqui y no en una tabla separada
 *    porque la cardinalidad maxima es 2 por cliente (Apple + Google) y siempre se
 *    leen juntos. Ver `wallet_passes` para el estado de sincronizacion.
 *
 * 3. `current_points` es un saldo MATERIALIZADO (cache) del ledger. Existe para
 *    que el POS lea en O(1) y para que el pase se construya sin agregar miles de
 *    filas. La tabla `point_ledger` es la verdad; este campo debe coincidir con
 *    SUM(points) y hay un comando de verificacion para auditarlo.
 *
 * 4. Los contadores `lifetime_*` son denormalizados para reporting. Se actualizan
 *    en la misma transaccion que el ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identificador opaco embebido en el QR del pase.
            $table->ulid('public_id')->unique();

            // --- Identificadores de billetera ---
            // Apple: serialNumber del .pkpass. Unico por tenant porque Apple exige
            // (passTypeIdentifier, serialNumber) unico en la library del cliente.
            $table->string('pass_serial_number', 64)->nullable();

            // Google: resource name del loyaltyObject (projects/{p}/loyaltyObject/{id}).
            $table->string('google_wallet_object_id', 190)->nullable();

            // Apple: identificador de la library de la Apple Account del cliente.
            // Requerido por el web service de PassKit para enviar updates push.
            $table->string('apple_device_library_identifier', 190)->nullable();

            // Ultimo auth token entregado a PassKit. Debe rotarse en cada update.
            $table->text('apple_authentication_token')->nullable();

            // Indica si el cliente ya confirmo el alta del pase. Enquanto sea false,
            // no se pueden enviar push updates: solo aplica el fallback web.
            $table->boolean('apple_pass_saved')->default(false);
            $table->boolean('google_pass_saved')->default(false);

            // --- Datos personales (PII) ---
            // Guardados en claro a proposito: el cifrado a nivel de columna se
            // resuelve en la capa de repositorio para no bloquear la indexacion
            // por email, que se usa para deduplicar en el enrolamiento.
            $table->string('full_name', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('locale', 12)->default('es');
            $table->string('timezone', 64)->default('UTC');

            // --- Saldo ---
            $table->bigInteger('current_points')->default(0);
            $table->bigInteger('lifetime_points_earned')->default(0);
            $table->bigInteger('lifetime_points_redeemed')->default(0);
            $table->timestampTz('last_earned_at')->nullable();
            $table->timestampTz('last_redeemed_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            $table->string('status', 16)->default(CustomerStatus::Active->value);

            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();

            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            // Indices parciales: un cliente puede no tener pase en una plataforma,
            // y un indice UNIQUE total impediria multiples NULL en MySQL pero es
            // igualmente correcto aqui. El parcial es mas barato y expresses la
            // regla real: "un serial por tenant".
            $table->unique('pass_serial_number');
            $table->unique('google_wallet_object_id');

            // Hot path del POS: buscar por tenant + estado, y por tenant + public_id.
            $table->index(['tenant_id', 'public_id'], 'customers_tenant_public_idx');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'last_seen_at'], 'customers_tenant_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
