<?php

declare(strict_types=1);

namespace App;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contexto de tenant: la otra mitad del aislamiento.
 *
 * Row Level Security (ver migracion enable_row_level_security) filtra filas, pero
 * necesita saber QUE tenant esta activo. Ese dato viaja por dos canales distintos
 * y por eso la clase los mantiene sincronizados:
 *
 *   1. `Illuminate\Support\Facades\Context` -> lo usa el scope de Eloquent
 *      (trait BelongsToTenant) y el resto del codigo de aplicacion.
 *   2. El GUC de PostgreSQL `app.current_tenant` -> lo usa el motor para evaluar
 *      las politicas RLS.
 *
 * Que seam el valor correcto NO es opcional: si el scope de Eloquent filtra por
 * tenant 5 y el GUC dice 3, las consultas devuelven cero filas de forma
 * inexplicable, o peor: si el GUC dice 5 y el scope dice 3, el GUC deja pasar filas
 * de otro tenant. Nunca se escribe uno sin el otro.
 *
 * Sobre Context frente a un singleton estatico: con Octane el proceso PHP
 * sobrevive entre peticiones, y un singleton mal limpiado hace que el request #501
 * herede el tenant del #500. `Context` se limpia solo.
 */
final class TenantContext
{
    /** Clave de Context que usa la capa de aplicacion. */
    public const KEY = 'tenant_id';

    /** GUC de PostgreSQL que evaluan las politicas RLS. */
    public const GUC = 'app.current_tenant';

    private const CACHE_KEY = 'tenant_entity';

    /*
    |---------------------------------------------------------------------------
    | Lectura
    |---------------------------------------------------------------------------
    */

    public static function id(): ?int
    {
        $value = Context::get(self::KEY);

        return $value === null ? null : (int) $value;
    }

    public static function isSet(): bool
    {
        return self::id() !== null;
    }

    /**
     * Valor del GUC tal como lo ve el motor. Puede diferir de id() si alguien
     * manipulo la sesion por fuera; los tests asertan sobre esto para detectar
     * exactamente esa desincronizacion.
     */
    public static function databaseId(): ?int
    {
        $value = DB::selectOne('SELECT current_setting(?, true) AS v', [self::GUC])?->v;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Entidad Tenant activa, o null si no hay contexto.
     *
     * Se cachea porque se consulta en cada linea del POS, en cada render del pase
     * y en cada respuesta de API. Durante una misma peticion el tenant no cambia.
     */
    public static function instance(): ?Tenant
    {
        if ($cached = Context::get(self::CACHE_KEY)) {
            return $cached;
        }

        $tenantId = self::id();

        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::query()
            ->withoutGlobalScopes()
            ->find($tenantId);

        if ($tenant !== null) {
            Context::add(self::CACHE_KEY, $tenant);
        }

        return $tenant;
    }

    public static function instanceOrFail(): Tenant
    {
        $tenant = self::instance();

        if ($tenant === null) {
            throw Exceptions\TenantNotResolved::forQuery();
        }

        return $tenant;
    }

    /*
    |---------------------------------------------------------------------------
    | Escritura
    |---------------------------------------------------------------------------
    */

    /**
     * Establece el tenant activo en AMBOS canales.
     *
     * El GUC se escribe SIEMPRE a nivel de sesion, nunca con
     * `set_config(..., true)`. El motivo es que una peticion HTTP no corre dentro
     * de una transaccion: un `SET LOCAL` fuera de un bloque transaccional no
     * tiene efecto en PostgreSQL (solo emite un warning), y el middleware de
     * resolucion de tenant corre exactamente ahi.
     *
     * El riesgo de un GUC de sesion es que sobreviva a la peticion en una
     * conexion persistente (Octane, pdo_persistent) y el request siguiente
     * herede el tenant del anterior. Ese riesgo se cubre por el otro lado: toda
     * via de entrada (middleware y run()) limpia el GUC en su `finally`.
     */
    public static function set(int $tenantId): void
    {
        Context::add(self::KEY, $tenantId);
        Context::forget(self::CACHE_KEY);

        DB::statement('SELECT set_config(?, ?, false)', [self::GUC, (string) $tenantId]);
    }

    /**
     * Limpia el contexto completo: canal de aplicacion y GUC de base de datos.
     *
     * Llamar a forget() sin tocar el GUC dejaria la sesion de base apuntando al
     * ultimo tenant de la peticion anterior. En un servidor PHP clasico eso es
     * inocuo porque cada request abre su propia conexion, pero con Octane es una
     * fuga de datos entre tenants waiting to happen.
     */
    public static function forget(): void
    {
        Context::forget(self::KEY);
        Context::forget(self::CACHE_KEY);

        try {
            DB::statement('SELECT set_config(?, ?, false)', [self::GUC, '']);
        } catch (Throwable $e) {
            // Si la transaccion ya esta abortada, PostgreSQL rechaza el comando.
            // No es recuperable y no vale la pena propagar: el GUC se limpia
            // solo cuando la transaccion haga rollback.
            report($e);
        }
    }

    /**
     * Ejecuta un callback con un tenant activo y restaura el contexto previo al
     * terminar, tanto si el callback retorna normalmente como si lanza.
     *
     * El callback NO se envuelve en una transaccion a proposito. Cada operacion
     * de negocio decide sus propios limites transaccionales, y algunos necesitan
     * commitear en fases: una emision de pase, por ejemplo, no debe perder todo
     * el trabajo si falla la ultima llamada a la API de Google.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        $previousId = self::id();
        $previousDatabaseId = self::databaseId();

        try {
            self::set($tenantId);

            return $callback();
        } finally {
            self::restore($previousId, $previousDatabaseId);
        }
    }

    /**
     * Restaura el estado previo en los dos canales.
     *
     * La restauracion es explicita y no se delega al rollback de la transaccion
     * por dos motivos concretos:
     *
     *   1. `run()` no envuelve el callback en una transaccion, asi que no hay
     *      rollback al que delegar.
     *   2. `SET LOCAL` dentro de un savepoint NO se revierte al hacer RELEASE,
     *      solo al hacer ROLLBACK TO SAVEPOINT. Como Laravel envuelve las
     *      `DB::transaction()` anidadas en un savepoint, un `run()` dentro de
     *      otro `run()` dejaria el tenant interior pegado en el exterior.
     *
     * Que seam un error de limpieza NUNCA debe tapar la excepcion original del
     * callback, que es justo la que el developer necesita ver. Por eso la parte
     * de base de datos va en try/catch.
     */
    private static function restore(?int $tenantId, ?int $databaseId): void
    {
        // El canal de aplicacion se restaura siempre y en primer lugar: no toca
        // la base y por tanto no puede fallar.
        Context::forget(self::CACHE_KEY);

        if ($tenantId === null) {
            Context::forget(self::KEY);
        } else {
            Context::add(self::KEY, $tenantId);
        }

        try {
            DB::statement('SELECT set_config(?, ?, false)', [self::GUC, (string) ($databaseId ?? '')]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
