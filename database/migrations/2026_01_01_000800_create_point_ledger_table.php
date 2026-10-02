<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libro mayor de puntos. Append-only: nunca se actualiza ni se borra.
 *
 * Por que un ledger y no solo `customers.current_points`:
 *
 *  - Reconstruccion: el saldo materializado se puede recalcular desde cero.
 *  - Auditoria: se puede probar que un saldo no fue alterado arbitrariamente.
 *  - Analitica: actividad por periodo sin depender del estado actual.
 *
 * La garantia de no duplicacion vive aqui, en la restriccion UNIQUE de la triple
 * (tenant_id, ref_type, ref_id). Ese es el patron "outbox idempotente": todo
 * movimiento del sistema tiene una referencia externa estable (el id del token de
 * QR, el external_ref del ticket, el id de la transaccion) y el ledger acepta
 * como maximo un asiento por referencia. Aunque el job se reintente diez veces,
 * el decimo intento choca contra el indice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->string('entry_type', 16);

            // Con signo. BIGINT porque el saldo de un cliente lifetime puede superar
            // con holgura los 32 bits con un programa aggressive.
            $table->bigInteger('points');

            // Saldo del cliente inmediatamente despues de aplicar este asiento.
            $table->bigInteger('balance_after');

            // --- Referencia idempotente ---
            // ref_type: qr_token | transaction | external_ref | manual | expiry
            // ref_id:   identificador estable del hecho de negocio que lo origino.
            $table->string('ref_type', 24);
            $table->string('ref_id', 128);

            $table->string('reason', 255)->nullable();
            $table->timestampTz('recorded_at');

            $table->timestampsTz();

            /**
             * CORAZON DEL MODELO. Un asiento por referencia y tenant. Esta es la
             * garantia final contra doble acreditacion, valida incluso si el
             * proceso que llama al ledger se ejecuta de forma concurrente o
             * multiple veces.
             */
            $table->unique(['tenant_id', 'ref_type', 'ref_id'], 'point_ledger_ref_unique');

            $table->index(['tenant_id', 'customer_id', 'recorded_at'], 'ledger_customer_date_idx');
            $table->index(['tenant_id', 'entry_type', 'recorded_at'], 'ledger_tenant_type_date_idx');
        });

        /**
         * Invariantes contables.
         */
        DB::statement(
            'ALTER TABLE point_ledger ADD CONSTRAINT point_ledger_points_not_zero CHECK (points <> 0)'
        );

        DB::statement(
            "ALTER TABLE point_ledger ADD CONSTRAINT point_ledger_entry_type_valid
             CHECK (entry_type IN ('earn', 'redeem', 'adjust', 'expire', 'reverse'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('point_ledger');
    }
};
