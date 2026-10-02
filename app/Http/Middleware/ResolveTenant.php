<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenant\TenantResolver;
use App\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant de la peticion y lo establece como contexto activo.
 *
 * Es la PRIMERA pieza de middleware de la API, antes de cualquier otra cosa,
 * porque sin tenant resuelto no se puede ni autenticar ni tocar datos.
 *
 * El orden importa y hay una razon concreta: `auth:sanctum` carga el User desde
 * la base, y la base tiene RLS. Si el contexto no esta puesto cuando corre el
 * guard, la consulta del usuario devuelve cero filas y el login falla con un 401
 * desconcertante en lugar de un 400 claro.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve();

        TenantContext::set($tenant->id);

        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            /*
             * Imprescindible, no es limieza cosmetica.
             *
             * Con una conexion clasica de PHP-FPM cada peticion abre y cierra su
             * propia conexion, y el GUC muere con ella. Con Octane o
             * pdo_persistent la conexion sobrevive: si el GUC no se limpia, el
             * request #2 hereda el tenant del #1 y las politicas RLS filtran por
             * el comercio equivocado sin dejar rastro.
             */
            TenantContext::forget();
        }
    }
}
