<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Exceptions\TenantNotResolved;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca un modelo como perteneciente a un tenant.
 *
 * Este trait aporta la primera de las dos capas de aislamiento. La segunda es
 * Row Level Security de PostgreSQL, que opera a nivel de motor y no puede ser
 * evitada desde la aplicacion (ver enable_row_level_security).
 *
 * Por que hacen falta las dos:
 *
 *  - El scope de Eloquent filtra en PHP, lo que significa que el indice de la
 *    base no puede aprovecharse igual y que un `JOIN` mal escrito puede devolver
 *    filas ajenas. RLS no tiene ese problema: filtra antes, en el planificador.
 *  - RLS no resuelve el scoping automatico de las relaciones ni previene errores
 *    de logica (un admin que lee `Tenant::find($otroId)`). El scope si.
 *
 * En resumen: RLS es la red de seguridad, este trait es el camino normal.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = tenant();

            if ($tenantId === null) {
                /**
                 * Fail-closed. Si el contexto no esta resuelto, no se consulta.
                 *
                 * La alternativa habitual es permitir la consulta y confiar en que
                 * el filtro aparecera en otro lado. Con saldo de puntos, un fallo
                 * de isolation debe producir un error 500 visible, nunca datos de
                 * otro comercio. Un fallo ruidoso se detecta en staging; una fuga
                 * silenciosa se descubre en un lawsuit.
                 */
                throw TenantNotResolved::forModel(static::class);
            }

            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', tenant());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
