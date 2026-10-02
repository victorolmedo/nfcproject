<?php

declare(strict_types=1);

use App\Enums\WalletPassStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de emision y sincronizacion de cada pase con la billetera del cliente.
 *
 * Se separa de `customers` porque aqui vive lo que es especifico de la plataforma
 * (id de objeto, version, ultimo error) mientras `customers` guarda la identidad
 * y el saldo. Un cliente puede tener hasta dos pases (Apple + Google) y ambos
 * evolucionan de forma independiente.
 *
 * `state_version` es un contador monotonico que incrementa en cada cambio de
 * estado del pase. Sirve como token de version para:
 *  - Descartar respuestas fuera de orden de la API de Google Wallet.
 *  - Exponer el estado actual en la landing sin recalcular nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 12);

            /**
             * Identificador del objeto en la plataforma:
             *  - Google: "projects/{project_id}/loyaltyObject/{object_id}"
             *  - Apple:  el serialNumber del .pkpass
             */
            $table->string('object_id', 190);

            /**
             * Apple unico (passTypeIdentifier + serialNumber). Para Google Wallet
             * se deja NULL porque el object id ya es globalmente unico.
             */
            $table->string('serial_number', 64)->nullable();
            $table->string('pass_type_id', 190)->nullable();

            $table->string('status', 16)->default(WalletPassStatus::Pending->value);
            $table->unsignedBigInteger('state_version')->default(1);

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedTinyInteger('consecutive_failures')->default(0);

            // Payload exacto que se envio a la plataforma. Indispensable para
            // debugging: sin el request real, una incidencia de emision es
            // irreproducible.
            $table->jsonb('last_request')->default('{}');
            $table->jsonb('last_response')->default('{}');

            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['platform', 'object_id']);
            $table->unique(['platform', 'serial_number'], 'wallet_passes_platform_serial_unique');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'platform'], 'wallet_passes_lookup_idx');

            /**
             * Un cliente no puede tener dos pases activos en la misma plataforma.
             * Reemitir el pase tras perderlo se hace actualizando el registro
             * existente, no creando uno nuevo.
             */
            $table->unique(['customer_id', 'platform'], 'wallet_passes_customer_platform_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_passes');
    }
};
