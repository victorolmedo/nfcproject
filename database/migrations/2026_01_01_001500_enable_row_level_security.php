<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aislamiento real entre tenants a nivel de motor de base de datos.
 *
 * Por que RLS y no solo un scope de Eloquent:
 *
 *  El scope `where('tenant_id', $x)` protege contra el error de desarrollo, que es
 *  el caso comun. No protege contra:
 *    - Un `->withoutGlobalScope()` accidental en un job.
 *    - Una consulta raw en un reporte escrito a las apuradas.
 *    - Una relacion mal formada en un join (`DB::table('rewards')->join('customers')`
 *      donde el scope solo se aplica a la tabla principal).
 *  Con RLS, ninguna de esas consultas puede devolver filas de otro tenant. El
 *  motor las filtra aunque la aplicacion se olvide por completo.
 *
 * Por que FORCE ROW LEVEL SECURITY:
 *
 *  PostgreSQL excluye al PROPIETARIO de la tabla de las politicas. Como la
 *  aplicacion se conecta con el mismo rol que creo las tablas, sin FORCE todo
 *  esto seria decorativo. FORCE aplica las politicas tambien al owner.
 *  Exencion: superusuarios y roles con BYPASSRLS, que usariamos unicamente para
 *  tareas administrativas fuera de la aplicacion.
 *
 * Contrato con la aplicacion:
 *
 *  La capa de peticion ejecuta `set_config('app.current_tenant', <id>, true)`
 *  (is_local = true) dentro de la transaccion de trabajo. Si la transaccion hace
 *  rollback, el valor tambien se revierte: no puede quedar un tenant filtrado
 *  colgado para la siguiente consulta de otra peticion.
 *  Si la variable NO esta seteada, `app_current_tenant_id()` devuelve NULL y las
 *  politicas no dejan pasar ninguna fila. Es fail-closed a proposito: un forgot
 *  de isolation debe producir cero filas, no datos de todos los tenants.
 */
return new class extends Migration
{
    /**
     * Tablas de dominio con `tenant_id` NOT NULL. Aislamiento estricto: sin
     * tenant en el contexto, cero filas visibles.
     *
     * @var array<int, string>
     */
    private const STRICT_TENANT_TABLES = [
        'tenant_domains',
        'users',
        'loyalty_configs',
        'customers',
        'rewards',
        'qr_tokens',
        'transactions',
        'point_ledger',
        'redemption_codes',
        'wallet_passes',
        'idempotency_keys',
        'personal_access_tokens',
    ];

    /**
     * Tablas de infraestructura con `tenant_id` NULLABLE.
     *
     * Estas registran eventos que pueden llegar ANTES de que sepamos a que
     * comercio pertenecen: un webhook de Google Wallet que referencia un objeto
     * que todavia no mapeamos, o un log de sincronizacion de una emision en vuelo.
     * La politica las deja visibles mientras `tenant_id` es NULL.
     *
     * Riesgo aceptado: un tenant puede ver filas aun no asignadas de otro. Se
     * limitan a envoltorios de evento sin PII de cliente, y se asignan en
     * segundos. La alternativa (un rol de servicio separado para ingesta) es
     * correcta para escala mayor, pero no justifica el costo aqui.
     *
     * @var array<int, string>
     */
    private const NULLABLE_TENANT_TABLES = [
        'webhook_events',
        'wallet_sync_logs',
    ];

    public function up(): void
    {
        /**
         * Funcion unica de resolucion del tenant en contexto.
         *
         * Se encapsula en una funcion para que la expresion de las politicas sea
         * legible y para que exista un solo lugar donde cambiar la mecanica
         * (por ejemplo, si manana el isolation key pasa a ser el `uuid` del
         * tenant en lugar de su id numerico).
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_tenant_id() RETURNS bigint
            LANGUAGE sql STABLE
            AS $$
                SELECT NULLIF(current_setting('app.current_tenant', true), '')::bigint
            $$;

            COMMENT ON FUNCTION app_current_tenant_id() IS
                'Tenant activo de la peticion. NULL = contexto no establecido => las politicas RLS niegan todo acceso.';
        SQL);

        foreach (self::STRICT_TENANT_TABLES as $table) {
            $this->applyPolicy(
                table: $table,
                using: 'tenant_id = app_current_tenant_id()',
            );
        }

        foreach (self::NULLABLE_TENANT_TABLES as $table) {
            $this->applyPolicy(
                table: $table,
                using: 'tenant_id = app_current_tenant_id() OR tenant_id IS NULL',
            );
        }

        /**
         * `tenants` requiere un tratamiento distinto porque es la tabla que
         * se consulta ANTES de tener un tenant: la resolucion de subdominio
         * a slug solo puede leerse si el SELECT esta permitido.
         *
         *  SELECT: libre. Necesario para resolver el subdominio y para la pagina
         *          de login, que solo expone nombre, logo y estado.
         *  INSERT: libre. El alta de un comercio no tiene tenant todavia.
         *  UPDATE/DELETE: solo sobre la fila propia. Nadie edita el tenant de otro.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tenants FORCE ROW LEVEL SECURITY;

            CREATE POLICY tenants_select ON tenants FOR SELECT USING (true);
            CREATE POLICY tenants_insert ON tenants FOR INSERT WITH CHECK (true);
            CREATE POLICY tenants_update ON tenants FOR UPDATE
                USING (id = app_current_tenant_id())
                WITH CHECK (id = app_current_tenant_id());
            CREATE POLICY tenants_delete ON tenants FOR DELETE
                USING (id = app_current_tenant_id() AND status = 'archived');
        SQL);

        /**
         * Tablas de infraestructura de Laravel (jobs, cache, sessions, failed_jobs).
         * No llevan `tenant_id` y son de alcance global, asi que quedan fuera de RLS
         * de forma deliberada. Cualquier dato de negocio que se guarde en ellas
         * debe pasar por una tabla de dominio.
         */
    }

    public function down(): void
    {
        foreach ([...self::STRICT_TENANT_TABLES, ...self::NULLABLE_TENANT_TABLES] as $table) {
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }

        DB::unprepared('ALTER TABLE tenants DISABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE tenants NO FORCE ROW LEVEL SECURITY');

        DB::unprepared('DROP FUNCTION IF EXISTS app_current_tenant_id()');
    }

    private function applyPolicy(string $table, string $using): void
    {
        DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        // USING cubre SELECT/UPDATE/DELETE. WITH CHECK cubre INSERT/UPDATE y evita
        // que alguien inserte una fila belonging a otro tenant. Ambas expresiones
        // son identicas porque el criterio de aislamiento no depende de la operacion.
        DB::unprepared(
            "CREATE POLICY {$table}_tenant_isolation ON {$table} "
            ."USING ({$using}) WITH CHECK ({$using})"
        );
    }
};
