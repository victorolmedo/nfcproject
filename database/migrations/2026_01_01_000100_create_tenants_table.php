<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nucleo del modelo multitenant.
 *
 * Estrategia de aislamiento: esquema unico con `tenant_id` en cada tabla de dominio,
 * reforzado con Row Level Security de PostgreSQL (ver migracion enable_row_level_security).
 * Se evita el esquema-por-tenant porque el numero de comercios crece de forma
 * impredecible y cada migracion deberia ejecutarse N veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // Identificador opaco y usado en URLs publicas. Evita exponer
            // el id autoincremental y permite migrar de dominio sin cambiar enlaces.
            $table->uuid('uuid')->unique();

            $table->string('name', 160);
            $table->string('slug', 63)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_id', 40)->nullable();

            // Marca del tenant: se usa en el encabezado del pase y en el POS.
            $table->string('logo_url', 1024)->nullable();

            // Paleta de la tarjeta. Google Wallet los expone como
            // backgroundColor/foregroundColor y Apple como labelColor/backgroundColor.
            $table->string('primary_color', 7)->default('#0F172A');
            $table->string('secondary_color', 7)->default('#F59E0B');

            $table->string('timezone', 64)->default('UTC');
            $table->char('currency', 3)->default('USD');

            $table->string('status', 24)->default(TenantStatus::Trial->value);

            // Configuracion no relacional: feature flags, limites del plan, textos legales.
            $table->jsonb('settings')->default('{}');

            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
        });

        /**
         * Dominios alternativos por tenant. Permite que un comercio use su propio
         * dominio en la landing de canje sin cambiar la arquitectura.
         * `host` es unico a nivel global y se normaliza en minusculas.
         */
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }
};
