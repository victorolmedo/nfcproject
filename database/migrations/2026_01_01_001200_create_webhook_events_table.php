<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de entrada de webhooks de Google Wallet y Apple Wallet.
 *
 * Estas plataformas reintentan aggressively cuando no reciben 2xx. Sin esta tabla
 * el mismo evento se procesa N veces, y en el caso de Apple un update duplicado
 * puede invalidar el token de autenticacion que ya emitimos.
 *
 * `external_id` es la clave natural del evento (object id + timestamp de la
 * notificacion en Google; o el identificador de la transaccion APNs en Apple).
 * La restriccion UNIQUE hace que el segundo envio del mismo evento se descarte
 * en el primer INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 24);
            $table->string('external_id', 190);

            /**
             * Google: walletObjects.notification.created | ...updated | ...deleted
             * Apple:  pass.updated | pass.registered | pass.removed | pass.provisioning
             */
            $table->string('event_type', 64);

            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_pass_id')->nullable()->constrained()->nullOnDelete();

            $table->jsonb('payload')->default('{}');

            // received | processing | processed | failed | ignored
            $table->string('status', 16)->default('received');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestampsTz();

            $table->unique(['provider', 'external_id'], 'webhook_events_provider_extid_unique');
            $table->index(['status', 'created_at'], 'webhook_events_status_idx');
            $table->index(['tenant_id', 'created_at'], 'webhook_events_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
