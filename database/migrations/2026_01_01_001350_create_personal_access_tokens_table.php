<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de API de Sanctum para el personal del tenant.
 *
 * `tenant_id` se agrega aqui en lugar de en una migracion posterior para que la
 * restriccion RLS de la tabla (ver enable_row_level_security) se defina sobre un
 * esquema ya completo.
 *
 * `device_label` identifica el terminal ("Tablet caja 1", "iPad barista"). Es lo
 * que el administrador ve en la pantalla de "dispositivos activos" y lo primero
 * que necesita leer un admin cuando hay que revocar acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->morphs('tokenable');

            $table->text('name');
            $table->string('device_label', 120)->nullable();
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();

            // Revocacion blanda en lugar de borrado: conservar el registro permite
            // auditar "este dispositivo intento operar despues de que le cortaron
            // el acceso", que es justamente lo que se necesita investigar.
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
