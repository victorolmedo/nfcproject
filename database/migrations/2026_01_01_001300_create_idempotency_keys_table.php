<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de claves de idempotencia del cliente.
 *
 * A diferencia de `transactions.idempotency_key`, que protege una operacion
 * concreta, esta tabla es la defensa general del API: el middleware intercepta
 * `Idempotency-Key`, y un replay de la misma peticion devuelve la respuesta
 * original sin volver a ejecutar el handler.
 *
 * Es la proteccion que importa en un POS, donde la red del comercio es mala y
 * los reintentos automaticos del fetch son la norma, no la excepcion.
 *
 * `request_hash` detecta el uso abusing de la misma clave con un payload distinto
 * (un bug del cliente, o un intento de colar una operacion diferente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Ambito de la clave. Evita que un `abc123` del endpoint A colisione
            // con uno del endpoint B.
            $table->string('scope', 64);

            $table->string('idempotency_key', 128);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->char('request_hash', 64);

            // --- Estados del ciclo ---
            // in_progress: la primera peticion esta ejecutandose. Un replay concurrente
            //              recibe 409 y debe reintentar.
            // completed:   respuesta cacheada disponible.
            $table->string('status', 16)->default('in_progress');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'scope', 'idempotency_key'], 'idempotency_keys_unique');
            $table->index(['expires_at'], 'idempotency_keys_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
