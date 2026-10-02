<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\TenantContext;

if (! function_exists('tenant')) {
    /**
     * Id del tenant activo de la peticion, o null si no hay contexto.
     *
     * Es la forma mas eficiente de obtener el id en un hot path: no carga la
     * entidad y no puede fallar.
     */
    function tenant(): ?int
    {
        return TenantContext::id();
    }
}

if (! function_exists('tenantInstance')) {
    /**
     * Entidad Tenant activa. Lanza TenantNotResolved si no hay contexto.
     */
    function tenantInstance(): Tenant
    {
        return TenantContext::instanceOrFail();
    }
}
