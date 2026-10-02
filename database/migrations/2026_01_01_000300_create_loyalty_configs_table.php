<?php

declare(strict_types=1);

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas del programa de fidelizacion, una vigente por tenant.
 *
 * Decisiones de diseno relevantes:
 *  - Los montos se guardan en minor units (centavos) como BIGINT. Un `float`
 *    para dinero genera drifts de redondeo que se acumulan en el ledger.
 *  - `points_per_minor_unit` es NUMERIC exacto. La conversion se hace con
 *    aritmetica decimal, nunca con punto flotante de PHP.
 *  - Solo una fila por tenant. Si se necesita historial de cambios, el
 *    versionado se resuelve en la capa de servicio, no con multiples filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('name', 120)->default('Programa estandar');
            $table->string('rule_type', 16)->default(LoyaltyRuleType::Amount->value);

            // --- Parametros para rule_type = amount / hybrid ---
            // Puntos otorgados por unidad de moneda minima (centavo).
            // Ej: 1 punto por cada 1000 centavos => 0.10000000
            $table->decimal('points_per_minor_unit', 18, 8)->default(0);

            // --- Parametros para rule_type = visit / hybrid ---
            $table->integer('points_per_visit')->default(0);

            // --- Condiciones ---
            // Compra minima para que la operacion sea acreditable. 0 = sin minimo.
            $table->unsignedBigInteger('min_purchase_minor')->default(0);
            $table->char('currency', 3)->default('USD');

            // Tope por transaccion: techo antifraude y de contencion de costo.
            // NULL = sin tope.
            $table->unsignedBigInteger('max_points_per_transaction')->nullable();

            // Tope diario por cliente. NULL = sin tope.
            $table->unsignedBigInteger('max_points_per_day')->nullable();

            $table->string('rounding', 16)->default(PointsRounding::Down->value);

            // --- Politica de saldo ---
            $table->boolean('allow_negative_balance')->default(false);
            $table->unsignedInteger('points_expiry_months')->nullable();
            $table->unsignedInteger('welcome_points')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_configs');
    }
};
