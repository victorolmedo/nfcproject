<?php

declare(strict_types=1);

use App\Enums\StaffRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal del tenant (cajeros y administradores del comercio).
 *
 * El cliente final del programa de fidelizacion NO tiene cuenta: se identifica
 * unicamente por su pase digital. Por eso `email` es unico dentro del tenant y
 * no globalmente, para permitir que dos comercios distintos tengan un
 * "admin@cafe.com" sin colision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('password');
            $table->timestampTz('email_verified_at')->nullable();

            $table->string('role', 16)->default(StaffRole::Cashier->value);
            $table->boolean('is_active')->default(true);

            // Identidad de contacto para el multi-tenant: puede haber homonimos entre
            // comercios, por eso no se usa como clave de sesion.
            $table->string('phone_e164', 20)->nullable();

            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();

            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'is_active']);
        });

        /**
         * Sesiones web. El POS es una SPA que usa Sanctum, pero se conserva esta
         * tabla para el panel Blade del administrador.
         */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
