<?php

declare(strict_types=1);

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de acumulacion y canjes.
 *
 * Separacion de responsabilidades con `point_ledger`:
 *  - `transactions` es la cabecera legible por humanos: "el cajero Marcoupidio $4.500
 *    en el ticket 8821 y)'' el cliente gano 45 puntos".
 *  - `point_ledger` es el libro mayor contable append-only, sin datos de negocio.
 *
 * `points_balance_after` se desnormaliza en ambas tablas a proposito: permite
 * detectar drift (ledger != saldo materializado) y reconstruir el saldo sin
 * recalcular toda la historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Cajero que opero. NULL en procesos automaticos (expiracion).
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();

            // Origen del QR dinamico en Flujo B. Un token consumido genera como
            // maximo una transaccion; la FK mas el indice unico lo garantizan.
            $table->foreignId('qr_token_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('reward_id')->nullable()->constrained()->nullOnDelete();

            // Referencia a la transaccion original cuando type = reverse.
            $table->foreignId('reverses_transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();

            $table->string('type', 16);
            $table->string('status', 16)->default(TransactionStatus::Posted->value);
            $table->string('source', 24)->default(TransactionSource::PosScanClient->value);

            // --- Monto de la operacion (minor units) ---
            $table->unsignedBigInteger('amount_spent_minor')->default(0);
            $table->char('currency', 3)->default('USD');

            // --- Impacto en puntos ---
            // Con signo: negativo en canjes, positivo en acumulaciones.
            $table->bigInteger('points_delta');
            $table->bigInteger('points_balance_after');

            // Referencia del ticket en el sistema del comercio (POS externo, caja, etc).
            $table->string('external_ref', 64)->nullable();

            // Clave de idempotencia del cliente. Repetir la misma peticion con la
            // misma clave devuelve la transaccion original en lugar de duplicar.
            $table->string('idempotency_key', 128)->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();

            $table->index(['tenant_id', 'occurred_at'], 'transactions_tenant_date_idx');
            $table->index(['tenant_id', 'customer_id', 'occurred_at'], 'transactions_customer_date_idx');
            $table->index(['tenant_id', 'type', 'occurred_at'], 'transactions_tenant_type_idx');
            $table->index(['tenant_id', 'operator_id'], 'transactions_tenant_operator_idx');
            $table->index(['tenant_id', 'external_ref'], 'transactions_tenant_ref_idx');

            /**
             * Un token de QR consumido produce, como maximo, una transaccion.
             * Esto es una segunda barrera: aunque el claim atomico falle, la base
             * impide que exista un segundo EARN pensado en el mismo token.
             */
            $table->unique('qr_token_id');
            $table->unique('idempotency_key');
        });

        DB::statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_type_valid
             CHECK (type IN ('earn', 'redeem', 'adjust', 'expire', 'reverse'))"
        );

        /**
         * Invariante de impacto: solo una transaccion POSTED puede registrar saldo.
         *
         * La primera rama es obvia, pero la segunda es la que aporta: impide que
         * una transaccion en estado pending, voided o failed lleve `points_balance_after`.
         * Sin ella, un bug que marque como posted despues de haber escrito el
         * saldo puede dejar un registro que aparenta haber impactado cuando en
         * realidad el ledger nunca lo hizo, y la conciliacion no lo detecta.
         */
        DB::statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_posted_consistency
             CHECK (
                (status = 'posted' AND points_balance_after IS NOT NULL AND occurred_at IS NOT NULL)
                OR (status <> 'posted' AND points_balance_after IS NULL)
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
