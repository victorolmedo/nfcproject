<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Base de los tests HTTP.
 *
 * Resuelve el tenant por subdominio de verdad, en vez de setear el contexto a
 * mano. Es deliberado: si el test se saltara `ResolveTenant` y fijara el GUC a
 * mano, estaria probando los controllers pero no el middleware que hace cumplir
 * el aislamiento. Un test de autorizacion que se salta la frontera que quiere
 * proteger no prueba nada.
 */
abstract class AuthenticatedTestCase extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    /**
     * Dominio raiz de los tests: coincide con el que produce `getHost()` en las
     * peticiones, es decir sin puerto.
     */
    protected string $rootDomain = 'tenants.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loyaltypass.root_domain' => $this->rootDomain,
            'loyaltypass.tenant_driver' => 'subdomain',
        ]);
    }

    protected function tenantUrl(string $slug, string $path = ''): string
    {
        return "https://{$slug}.{$this->rootDomain}{$path}";
    }

    /**
     * Crea un usuario bajo el contexto de su tenant y devuelve la fila.
     */
    protected function makeUser(Tenant $tenant, array $attributes = []): User
    {
        return $this->asTenant($tenant, fn (): User => User::factory()->create($attributes + [
            'tenant_id' => $tenant->id,
        ]));
    }

    /**
     * Prepara la peticion siguiente con un token Bearer.
     *
     * El `forgetGuards()` no es cosmetico y su ausencia produce tests que mienten.
     *
     * Laravel reutiliza la MISMA instancia de la aplicacion entre las llamadas a
     * `postJson()` dentro de un test, y el guard de sesion cachea el usuario ya
     * resuelto en `RequestGuard::$user`. Sin forgetear los guards, la segunda
     * peticion de una serie devuelve 200 con el usuario de la primera, y un test
     * que comprueba "este token ya no sirve" pasaria sin comprobar nada.
     *
     * En produccion esto no ocurre: cada peticion corre en su propia instancia.
     * Es una limitacion del arnes de test, no del codigo.
     */
    protected function withBearer(?string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', $token === null ? '' : 'Bearer '.$token);
    }

    /**
     * Login completo contra el subdominio del tenant.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{status: int, json: array<string, mixed>, token: ?string}
     */
    protected function loginFor(Tenant $tenant, string $email, string $password, array $overrides = []): array
    {
        $response = $this->postJson($this->tenantUrl($tenant->slug, '/api/v1/auth/login'), [
            'email' => $email,
            'password' => $password,
            'device_name' => 'Tablet de test',
            ...$overrides,
        ]);

        $json = $response->json() ?? [];

        return [
            'status' => $response->getStatusCode(),
            'json' => $json,
            'token' => $json['data']['token'] ?? null,
        ];
    }
}
