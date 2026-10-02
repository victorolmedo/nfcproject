<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use App\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Utilidades de test para trabajar con contexto de tenant.
 *
 * Nota sobre `RefreshDatabase`: Laravel envuelve cada test en una transaccion.
 * `TenantContext::run()` abre una transaccion anidada (savepoint), dentro de la
 * cual `set_config(..., true)` tiene exactamente el alcance que queremos: se
 * revierte al hacer rollback del savepoint y no contamina el resto del test.
 *
 * Por eso estos helpers pueden usarse dentro de un test ya transaccionado sin
 * interfere con el estado global.
 */
trait InteractsWithTenants
{
    /**
     * Ejecuta un callback con un tenant activo.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(Tenant $tenant, \Closure $callback): mixed
    {
        return TenantContext::run($tenant, $callback);
    }

    /**
     * Crea un tenant dentro del contexto correcto y lo devuelve.
     *
     * El alta del tenant no requiere contexto (politica `tenants_insert`), asi que
     * se hace fuera. Lo que si necesita contexto es cualquier escritura posterior.
     */
    protected function makeTenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->create($attributes);
    }

    /**
     * Lee el valor del GUC que evaluan las politicas RLS.
     *
     * Permite asertar sobre el estado real de la sesion, no sobre lo que la
     * aplicacion cree haber seteado.
     */
    protected function currentDatabaseTenant(): ?int
    {
        $row = DB::selectOne("SELECT current_setting('app.current_tenant', true) AS v");

        $value = $row?->v;

        return ($value === null || $value === '') ? null : (int) $value;
    }
}
