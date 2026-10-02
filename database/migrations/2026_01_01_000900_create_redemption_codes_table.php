<?php

declare(strict_types=1);

use App\Enums\RedemptionCodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codigos de canje de un solo uso.
 *
 * Cuando el cliente canjea un premio tipo `gift_card` o `free_item`, se le emite
 * un codigo alfanumerico corto que el cajero valida en el POS. El descuento en
 * linea (percent_discount / fixed_discount) NO genera codigo: se aplica en el
 * momento del canje y queda registrado en la transaccion.
 *
 * El codigo es un valor secreto: se genera con CSPRNG, nunca con `uniqid()` ni
 * `Str::random()` de forma predecible, y se indexa con hash para que un dump de la
 * base no sirva directamente para canjear premios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemption_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            // Codigo legible por el cajero, ej: "K7P-4M2X-9QT8".
            $table->string('code', 32);

            // SHA-256 del codigo normalizado. Es lo que se compara al validar, de
            // modo que el indice no expone los codigos utilizables.
            $table->string('code_hash', 64);

            $table->string('status', 16)->default(RedemptionCodeStatus::Active->value);

            // Valor del premio, congelado al momento del canje. Si el admin edita
            // el premio despues, el codigo ya emitido conserva su valor original.
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->decimal('value_percent', 5, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignId('used_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('used_external_ref', 64)->nullable();

            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->unique('code_hash');
            $table->index(['tenant_id', 'status', 'expires_at'], 'redemption_active_idx');
            $table->index(['tenant_id', 'customer_id', 'created_at'], 'redemption_customer_idx');
        });

        /**
         * Un codigo usado tiene que tener fecha de uso. Impide que un bug marque
         * `used` sin dejar rastro de cuando ocurrio.
         */
        DB::statement(
            "ALTER TABLE redemption_codes ADD CONSTRAINT redemption_used_consistency
             CHECK (status <> 'used' OR used_at IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('redemption_codes');
    }
};
