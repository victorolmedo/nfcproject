<?php

declare(strict_types=1);

use App\Enums\RewardType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de premios del tenant.
 *
 * `points_required` es BIGINT y no un entero simple para permitir que un tenant
 * de larga vida acumule mas de 2^31 puntos en un solo premio sin overflow.
 *
 * Los campos de valor son polimorficos segun `reward_type` y la validacion de esa
 * combinacion se delega a un Form Request / Action, no al esquema, porque MySQL/PG
 * no expresan CHECK entre columnas de forma portable y util.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('image_url', 1024)->nullable();

            $table->string('reward_type', 24)->default(RewardType::FixedDiscount->value);
            $table->bigInteger('points_required');

            // Valor del premio. Solo aplica segun reward_type:
            //   percent_discount -> value_percent
            //   fixed_discount   -> value_minor
            //   gift_card        -> value_minor (importe de la gift card)
            //   free_item        -> ninguno
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->decimal('value_percent', 5, 2)->nullable();

            // Vigencia del codigo emitido para gift_card / free_item.
            $table->unsignedInteger('redemption_valid_days')->default(90);

            // --- Inventario y limites ---
            $table->integer('inventory')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('max_redemptions_per_customer')->nullable();
            $table->unsignedInteger('max_redemptions_total')->nullable();

            // --- Vigencia del premio en el catalogo ---
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);

            $table->text('terms')->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);

            // Consulta del cliente: "premios que puedo canjear con mi saldo".
            $table->index(['tenant_id', 'is_active', 'points_required'], 'rewards_tenant_active_points_idx');
            $table->index(['tenant_id', 'is_featured']);
        });

        /**
         * Regla de integridad: un descuento porcentual no puede superar el 100%.
         * Se incluye porque es cheaply enforceable en DB y evita datos corruptos
         * aunque el Action ya lo valide.
         */
        DB::statement(
            'ALTER TABLE rewards ADD CONSTRAINT rewards_value_percent_range CHECK (value_percent IS NULL OR (value_percent > 0 AND value_percent <= 100))'
        );

        DB::statement(
            'ALTER TABLE rewards ADD CONSTRAINT rewards_points_required_positive CHECK (points_required > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
