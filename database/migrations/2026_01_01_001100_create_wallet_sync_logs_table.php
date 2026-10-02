<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de las interacciones con las billeteras.
 *
 * Se escribe ANTES de llamar a la API de la plataforma y se actualiza despues con
 * el resultado. Asi una request que nunca recibio respuesta queda registrada y es
 * visible para el operador, en lugar de desaparecer.
 *
 * No es una tabla de logs: se poda de forma agresiva (retencion de 30 dias por
 * defecto) porque su unico proposito es el diagnostico de corto plazo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_pass_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 12);

            /**
             * Operacion logica:
             *   issue | update | delete | reissue | validate | push
             */
            $table->string('operation', 24);

            /**
             * Resultado: pending | success | failed | skipped
             */
            $table->string('status', 16)->default('pending');

            // Codigo HTTP y cuerpo de la respuesta de la plataforma, truncados.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('request_payload')->default('{}');
            $table->jsonb('response_payload')->default('{}');
            $table->text('error_message')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at'], 'wallet_sync_logs_tenant_date_idx');
            $table->index(['wallet_pass_id', 'created_at'], 'wallet_sync_logs_pass_date_idx');
            $table->index(['status', 'created_at'], 'wallet_sync_logs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_sync_logs');
    }
};
