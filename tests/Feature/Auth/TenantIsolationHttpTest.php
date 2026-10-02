<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AuthenticatedTestCase;

/**
 * Aislamiento de tenant a traves de HTTP.
 *
 * A diferencia de `TenantIsolationTest`, que trabaja con modelos, aqui todo pasa
 * por el pipeline real de peticion: subdominio, middleware, guard y consulta. Es
 * la unica forma de comprobar que el aislamiento se sostiene de extremo a extremo
 * y no solo en la capa de datos.
 */
final class TenantIsolationHttpTest extends AuthenticatedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'staff.auth'])
            ->get('/api/v1/_probe/customers', function (): array {
                $rows = Customer::query()->limit(50)->get();

                return [
                    'count' => $rows->count(),
                    'ids' => $rows->pluck('id')->all(),
                ];
            });
    }

    public function test_un_token_no_ve_los_datos_de_otro_comercio_a_traves_del_subdominio(): void
    {
        $propio = $this->makeTenant(['slug' => 'cafe-sol']);
        $ajeno = $this->makeTenant(['slug' => 'cafe-otro']);

        $this->asTenant($propio, fn () => Customer::factory()->count(3)->create());
        $this->asTenant($ajeno, fn () => Customer::factory()->count(5)->create());

        $user = $this->makeUser($propio, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($propio, 'cajero@cafe-sol.test', 'correcta-1234');

        $response = $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/customers'));

        $response->assertOk();

        /*
         * El token es legitimo de cafe-sol y la peticion llega por el subdominio de
         * cafe-sol, asi que 5 filas de cafe-otro en la respuesta seria una fuga
         * completa. RLS filtra antes de que la consulta llegue a PHP, y el scope de
         * Eloquent la filtra otra vez: son dos capas independientes y basta con que
         * una falle para que este test caiga.
         */
        $this->assertSame(3, $response->json('count'));

        $propios = $this->asTenant($propio, fn () => Customer::query()->pluck('id')->all());
        $ajenos = $this->asTenant($ajeno, fn () => Customer::query()->pluck('id')->all());

        foreach ((array) $response->json('ids') as $id) {
            $this->assertContains($id, $propios, 'La respuesta contiene un id de otro comercio.');
            $this->assertNotContains($id, $ajenos);
        }
    }

    public function test_el_mismo_token_en_otro_subdominio_no_gana_acceso(): void
    {
        $propio = $this->makeTenant(['slug' => 'cafe-sol']);
        $ajeno = $this->makeTenant(['slug' => 'cafe-otro']);

        $this->asTenant($ajeno, fn () => Customer::factory()->count(4)->create());

        $result = $this->loginFor($propio, 'cajero@cafe-sol.test', 'correcta-1234');

        $user = $this->makeUser($propio, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($propio, 'cajero@cafe-sol.test', 'correcta-1234');

        /*
         * Reutilizar un token valido de cafe-sol contra cafe-otro es exactamente el
         * intento mas comun de ataque entre tenants: el atacante ya tiene una
         * credencial legitima y solo cambia el host.
         *
         * La defensa es doble. El token se emitio con `tenant_id` = cafe-sol, y la
         * politica RLS de `personal_access_tokens` impide que la fila se lea desde
         * el contexto de cafe-otro, de modo que el guard no resuelve ningun usuario
         * y la peticion muere en 401. El scope de Eloquent seria la segunda red,
         * pero no hace falta: la primera ya basta.
         */
        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-otro', '/api/v1/_probe/customers'))
            ->assertUnauthorized();
    }

    public function test_el_contexto_de_tenant_se_limpia_al_terminar_cada_peticion(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertOk();

        /*
         * El GUC es de ambito de SESSION, asi que sobrevive a la peticion mientras
         * la conexion siga abierta. Con una conexion clasica de PHP-FPM cada
         * peticion abre y cierra la suya y no hay riesgo; con Octane o
         * pdo_persistent la conexion se reutiliza y el request #2 heredaria el
         * tenant del #1, con RLS filtrando por el comercio equivocado.
         *
         * Por eso `ResolveTenant` limpia el contexto en un `finally`. Este test lo
         * verifica leyendo el valor real del GUC, no el estado que cree tener la
         * aplicacion.
         */
        $this->assertNull(
            $this->currentDatabaseTenant(),
            'El GUC de tenant debe quedar vacio despues de la peticion.',
        );
    }

    public function test_una_ruta_fuera_del_grupo_api_falla_de_forma_explicita(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        // Ruta sin el grupo `api`, es decir sin `ResolveTenant`.
        Route::middleware(['staff.auth'])
            ->get('/api/v1/_probe/sin-grupo', fn (): array => ['ok' => true]);

        /*
         * Sin el chequeo de `AuthenticateStaff`, esto devolveria 401: RLS no ve
         * filas sin GUC, el guard no encuentra el token y responde "no
         * autenticado". Seguro, pero el 401 blames al cliente por un fallo de
         * desarrollo que nadie encontraria.
         *
         * Con el chequeo, se responde 500 `internal.tenant_context_missing`, que
         * dice exactamente que falta el middleware y en que peticion.
         */
        $response = $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/sin-grupo'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('internal.tenant_context_missing', $response->json('error.code'));
    }
}
