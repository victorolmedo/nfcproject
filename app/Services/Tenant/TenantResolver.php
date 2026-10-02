<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Determina a que comercio pertenece una peticion.
 *
 * Orden de intento (el primero que matchea gana):
 *
 *   1. Dominio personalizado declarado en `tenant_domains`. Un comercio con su
 *      propio dominio debe funcionar aunque el subdominio no exista.
 *   2. Subdominio: `cafe-aurora.loyaltypass.app` -> slug `cafe-aurora`.
 *   3. Header explicito `X-Tenant-Slug`, solo si el driver lo permite.
 *   4. Segmento de ruta `/t/{slug}`.
 *
 * Decisiones deliberadas:
 *
 *  - NUNCA hay un tenant por defecto. Si `app.loyaltypass.com` no resuelve, la
 *    peticion se rechaza. Un fallback a "el primer tenant" seria una fuga
 *    silenciosa de datos entre comercios.
 *
 *  - Los subdominios reservados (`www`, `api`, `admin`, ...) se rechazan de
 *    forma explicita. Si `admin` resolviera a un tenant llamado "admin", la
 *    consola de plataforma y el comercio homonimo se pisarian.
 *
 *  - Solo se resuelven tenants `active`. Un tenant `trial` no opera, y devolverlo
 *    para que cada endpoint chequee el status multiplica el riesgo de que uno se
 *    olvide. El caso "operar en trial" se resuelve subiendo el tenant a active.
 */
class TenantResolver
{
    /**
     * Cache de resolucion por host, deephouse dentro de la peticion.
     *
     * Varias capas (middleware, sanctum guard, controladores) piden el tenant.
     * Resolverlo por host desde `tenant_domains` es una query extra que no
     * deberia repetirse cinco veces en la misma peticion.
     *
     * @var array<string, Tenant|null>
     */
    private array $cache = [];

    public function __construct(private readonly Request $request) {}

    /**
     * @throws Exceptions\TenantNotResolvable
     */
    public function resolve(): Tenant
    {
        $tenant = $this->attemptResolution();

        if ($tenant === null) {
            throw Exceptions\TenantNotResolvable::unknownHost($this->describeHost());
        }

        if ($tenant->status !== TenantStatus::Active) {
            throw Exceptions\TenantNotResolvable::inactiveTenant($tenant->slug, $tenant->status->value);
        }

        return $tenant;
    }

    /**
     * Primer metodo de la cadena que resuelve. NO comprueba el estado del tenant:
     * esa comprobacion vive unicamente en `resolve()`, que es el unico punto de
     * entrada de la aplicacion.
     *
     * Exponerlo permitiria a un endpoint futuro resolver un comercio suspendido
     * saltandose el filtro, y el filtro se habria puesto en el sitio equivocado.
     */
    private function attemptResolution(): ?Tenant
    {
        $driver = config('loyaltypass.tenant_driver', 'subdomain');

        // El header tiene prioridad cuando el driver lo declara explicito: es el
        // modo para clientes que no controlan el host (apps, integraciones, POS
        // embebido en el navegador de otro dominio).
        if ($driver === 'header' && $slug = $this->slugFromHeader()) {
            return $this->bySlug($slug);
        }

        $host = $this->host();

        if ($host !== null) {
            $byDomain = $this->byDomain($host);

            if ($byDomain !== null) {
                return $byDomain;
            }
        }

        // El subdominio se intenta siempre, incluso con driver=header. Es el modo
        // mas seguro (el host no lo elige el cliente) y no tiene costo extra: si
        // no hay subdominio, `slugFromHost` devuelve null y se sigue al header.
        if ($slug = $this->slugFromHost($host)) {
            return $this->bySlug($slug);
        }

        if ($driver === 'path' && $slug = $this->slugFromPath()) {
            return $this->bySlug($slug);
        }

        if ($driver !== 'header' && $slug = $this->slugFromHeader()) {
            return $this->bySlug($slug);
        }

        return null;
    }

    /**
     * Busca un tenant por slug sin exigir ningun estado.
     *
     * No es un punto de entrada alternativo a `resolve()`: es interno, y por eso
     * es privado. Exponerlo daria a cualquier endpoint la capacidad de montar un
     * contexto de tenant suspendido, que es justo lo que el diseno de
     * fail-closed pretende impedir.
     */
    private function findBySlug(string $slug): ?Tenant
    {
        $slug = Str::slug($slug);

        if ($slug === '') {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }

    private function bySlug(string $slug): ?Tenant
    {
        $key = 'slug:'.$slug;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->findBySlug($slug);
    }

    private function byDomain(string $host): ?Tenant
    {
        $key = 'host:'.$host;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $domain = TenantDomain::query()
            ->where('host', $host)
            ->whereNotNull('verified_at')
            ->first();

        return $this->cache[$key] = $domain?->tenant;
    }

    /**
     * Extrae el slug del subdominio.
     *
     * Solo cuando el host es un subdominio real del dominio raiz. Un host como
     * `cafe-aurora.cafe-aurora.com` NO debe resolverse: no pertenece a la
     * plataforma y tratarlo como si lo fuera permitiria que cualquiera apuntara
     * un CNAME a nuestra IP y se apropie del namespace de otro comercio.
     */
    private function slugFromHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $rootDomain = $this->rootDomain();

        if ($rootDomain === '' || ! str_ends_with($host, '.'.$rootDomain)) {
            return null;
        }

        $subdomain = Str::beforeLast($host, '.'.$rootDomain);

        // El host tiene que ser EXACTAMENTE un subdominio de primer nivel. Con dos
        // niveles (`a.b.loyaltypass.app`) no se es dueno de `b` y no se puede
        // garantizar a quien pertenece.
        if (! str_contains($subdomain, '.')) {
            return $this->isReserved($subdomain) ? null : $subdomain;
        }

        return null;
    }

    private function slugFromHeader(): ?string
    {
        $header = (string) config('loyaltypass.header_name', 'X-Tenant-Slug');
        $value = $this->request->header($header);

        if (! is_string($value) || $value === '') {
            return null;
        }

        // El header lo elige el cliente, asi que se acota a la forma de un slug.
        // Sin esta validacion, `../../otro` o un string de 4 KB llegan al indice.
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/i', $value)) {
            return null;
        }

        return Str::lower($value);
    }

    private function slugFromPath(): ?string
    {
        $segment = $this->request->segment(2);

        if ($segment === null && str_starts_with($this->request->path(), 't/')) {
            $segment = explode('/', $this->request->path())[1] ?? null;
        }

        if ($segment === null || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/i', $segment)) {
            return null;
        }

        return Str::lower($segment);
    }

    private function isReserved(string $subdomain): bool
    {
        return in_array(Str::lower($subdomain), config('loyaltypass.reserved_subdomains', []), true);
    }

    /**
     * Host normalizado: sin puerto, en minusculas, sin punto final.
     *
     * El puerto se quita porque `Host` lo incluye y `tenant_domains.host` se
     * guarda sin puerto. Si no se normalizara, un tenant nunca resolveria en
     * desarrollo, que es la forma clasica de perder un dia.
     */
    private function host(): ?string
    {
        $host = $this->request->getHost();

        return $host === '' ? null : Str::lower(rtrim($host, '.'));
    }

    /**
     * Dominio raiz normalizado, sin puerto.
     *
     * El puerto se quita porque `Request::getHost()` no lo devuelve, y comparar
     * `cafe-aurora.localhost` contra un root configurado como `localhost:8000`
     * falla siempre. Como el `.env` de desarrollo incluye el puerto para que la
     * URL del navegador funcione, el valor casi siempre lo traeria y ningun
     * subdominio resolveria en local.
     *
     * Se normaliza aqui y no se corrige el `.env` a proposito: `LOYALTYPASS_ROOT_DOMAIN`
     * documenta el host completo que el usuario escribe en el navegador, que si
     * incluye el puerto, y ese es un dato legitimo. El punto de comparacion no lo es.
     */
    private function rootDomain(): string
    {
        $root = (string) config('loyaltypass.root_domain', '');

        if ($root === '') {
            return '';
        }

        // `parse_url` no distingue mayusculas ni puntos sobrantes, asi que se
        // normaliza a mano el resultado.
        $withoutScheme = preg_replace('#^https?://#i', '', $root) ?? $root;

        $host = str_contains($withoutScheme, '/')
            ? (parse_url($withoutScheme, PHP_URL_HOST) ?: $withoutScheme)
            : $withoutScheme;

        // Se corta en la primera `:` para eliminar el puerto. Un IPv6 entre
        // corchetes se deja intacto: `[::1]:8000` es un host valido.
        if (! str_starts_with($host, '[')) {
            $host = explode(':', $host, 2)[0];
        }

        return Str::lower(rtrim($host, '.'));
    }

    private function describeHost(): string
    {
        return $this->host() ?? '(sin host)';
    }
}
