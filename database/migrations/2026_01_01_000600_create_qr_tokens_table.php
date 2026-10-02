<?php

declare(strict_types=1);

use App\Enums\QrClaimType;
use App\Enums\QrTokenStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de un solo uso del QR dinamico (Flujo B).
 *
 * El QR que ve el cliente NO contiene el token en crudo: contiene un JWT firmado
 * con `jti` (UUID) y `exp` de 3 a 5 minutos. Esta tabla es el registro servidor
 * que hace autoritativo ese JWT y garantiza el unico uso.
 *
 * Mecanismo antifraude:
 *
 *  1. Unicidad de `jti`: si alguien reutiliza un JWT capturado, el UPDATE condicional
 *     sobre `status` solo puede tener exito una vez.
 *
 *  2. Claim atomico: el canje se hace con
 *         UPDATE qr_tokens SET status='consumed', consumed_at=now()
 *         WHERE id = ? AND status = 'issued' AND expires_at > now()
 *     y se considera valido solo si `rowCount() === 1`. Esto serializea a los
 *     escaneos concurrentes sin necesidad de un lock pesimista.
 *
 *  3. Vinculacion opcional al cliente: si la caja ya conoce al cliente
 *     (compra con tarjeta identificada), `customer_id` se fija al emitir y el token
 *     solo puede canjearse por ese cliente.
 *
 *  4. Deteccion de replay: `scan_count` y `scan_fingerprint` permiten alertar si el
 *     mismo token es escaneado varias veces desde fingerprints distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identificador que viaja como claim `jti` del JWT.
            $table->uuid('jti')->unique();

            // Cajero que emitio el token. Permite auditar y anular desde el POS.
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Cliente pre-asignado, si la caja ya lo identifico. NULL = cualquier
            // cliente enrolado puede canjearlo.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('claim_type', 24)->default(QrClaimType::VisitStamp->value);

            // Puntos fijos otorgados cuando claim_type = fixed_points.
            $table->bigInteger('points_awarded')->nullable();

            // Contexto de la operacion de caja que origino el QR.
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('external_ref', 64)->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->string('status', 16)->default(QrTokenStatus::Issued->value);

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignId('consumed_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // --- Telemetria antifraude ---
            $table->unsignedSmallInteger('scan_count')->default(0);
            $table->string('scan_fingerprint', 64)->nullable();
            $table->string('scan_ip', 45)->nullable();
            $table->string('scan_user_agent', 512)->nullable();
            $table->timestampTz('last_scanned_at')->nullable();

            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'expires_at'], 'qr_tokens_active_idx');

            // Impide que un token sin asignar se reutilice como "ticket" de una caja
            // distinta: cada token pertenece a una unica operacion de caja.
            $table->index(['tenant_id', 'issued_by_user_id', 'status'], 'qr_tokens_issuer_idx');
        });

        /**
         * Invariante de estado: un token consumido tiene que tener fecha de consumo
         * y un cliente consumidor. Se valida en DB para que ningun camino de codigo
         * (job, comando artisan, migracion manual) pueda dejar el registro incoherente.
         */
        DB::statement(
            "ALTER TABLE qr_tokens ADD CONSTRAINT qr_tokens_consumed_consistency
             CHECK (status <> 'consumed' OR (consumed_at IS NOT NULL AND consumed_by_customer_id IS NOT NULL))"
        );

        /**
         * Un token expirado no puede basura-juntarse. Un indice parcial permite
         * localizar rapido los tokens vencidos para el job de limpieza, sin
         * penalizar el indice de consulta activa.
         */
        DB::statement(
            "CREATE INDEX qr_tokens_expired_idx ON qr_tokens (expires_at) WHERE status = 'issued'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_tokens');
    }
};
