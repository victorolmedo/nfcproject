<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class LoginTest extends AuthenticatedTestCase
{
    public function test_un_usuario_valido_recibe_un_token_con_su_usuario_y_su_comercio(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol', 'name' => 'Cafe Sol']);
        $user = $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->assertSame(201, $result['status']);
        $this->assertNotNull($result['token']);
        $this->assertSame('Bearer', $result['json']['data']['token_type']);
        $this->assertSame($user->id, $result['json']['data']['user']['id']);
        $this->assertSame('cashier', $result['json']['data']['user']['role']);
        $this->assertSame('cafe-sol', $result['json']['data']['tenant']['slug']);

        // El saldo y los datos internos del comercio no viajan en la respuesta.
        $this->assertArrayNotHasKey('tax_id', $result['json']['data']['tenant']);
        $this->assertArrayNotHasKey('settings', $result['json']['data']['tenant']);
        $this->assertArrayNotHasKey('password', $result['json']['data']['user']);
    }

    public function test_el_email_se_normaliza_a_minusculas(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, '  CAJERO@Cafe-Sol.TEST  ', 'correcta-1234');

        $this->assertSame(201, $result['status'], 'El login debe ignorar mayusculas y espacios.');
    }

    public function test_una_password_incorrecta_no_entrega_token_y_no_revela_si_el_usuario_existe(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $existente = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'incorrecta');
        $inexistente = $this->loginFor($tenant, 'nadie@cafe-sol.test', 'incorrecta');

        $this->assertSame(401, $existente['status']);
        $this->assertSame(401, $inexistente['status']);

        /*
         * El mensaje es identico en ambos casos. Si difiriera, la API confirmaria
         * que correo esta registrado, y el atacante solo tendria que probar
         * passwords hasta dar con un mensaje distinto del que recibe un correo
         * inexistente.
         */
        $this->assertSame(
            $existente['json']['error']['message'],
            $inexistente['json']['error']['message'],
        );
        $this->assertSame('auth.invalid_credentials', $existente['json']['error']['code']);

        $this->assertArrayNotHasKey('token', $existente['json']['data'] ?? []);
        $this->assertNull($existente['token']);
    }

    public function test_cinco_intentos_fallidos_bloquean_la_cuenta(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->loginFor($tenant, 'cajero@cafe-sol.test', 'incorrecta');
        }

        // El quinto fallo dispara el bloqueo.
        $this->loginFor($tenant, 'cajero@cafe-sol.test', 'incorrecta');

        $user = $this->asTenant($tenant, fn (): User => User::where('email', 'cajero@cafe-sol.test')->firstOrFail());

        $this->assertTrue($user->isLocked(), 'La cuenta debe quedar bloqueada tras 5 intentos.');
        $this->assertSame(5, $user->failed_login_attempts);
    }

    public function test_una_cuenta_bloqueada_no_puede_entrar_aunque_la_password_sea_correcta(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'locked_until' => now()->addMinutes(15),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->assertSame(401, $result['status']);
        $this->assertNull($result['token']);
    }

    public function test_una_cuenta_inactiva_no_puede_entrar(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'is_active' => false,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->assertSame(401, $result['status']);
    }

    public function test_un_correo_de_otro_comercio_no_autentica_aunque_exista_en_otro_tenant(): void
    {
        $propio = $this->makeTenant(['slug' => 'cafe-sol']);
        $ajeno = $this->makeTenant(['slug' => 'cafe-otro']);

        // El mismo correo existe en el comercio ajeno, con la misma password.
        $this->makeUser($ajeno, [
            'email' => 'admin@comun.test',
            'password' => Hash::make('secreta-1234'),
        ]);

        $result = $this->loginFor($propio, 'admin@comun.test', 'secreta-1234');

        /*
         * Este es el test que mas importa del archivo. El indice unico es
         * `(tenant_id, email)`, asi que el correo si existe en la plataforma. Si el
         * resolver o el scope de Eloquent no acotaran la busqueda al tenant
         * activo, este login devolveria 201 y el aislamiento seria inexistente.
         */
        $this->assertSame(401, $result['status']);
        $this->assertNull($result['token']);
    }

    public function test_el_login_registra_el_ultimo_acceso(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $user = $this->asTenant($tenant, fn (): User => User::where('email', 'cajero@cafe-sol.test')->firstOrFail());

        $this->assertNotNull($user->last_login_at);
        $this->assertSame(0, $user->failed_login_attempts);
    }

    public function test_un_tenant_suspendido_no_puede_autenticarse(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol', 'status' => TenantStatus::Suspended]);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $response = $this->postJson($this->tenantUrl('cafe-sol', '/api/v1/auth/login'), [
            'email' => 'cajero@cafe-sol.test',
            'password' => 'correcta-1234',
            'device_name' => 'Tablet de test',
        ]);

        // El bloqueo ocurre en el middleware, antes de tocar la tabla de usuarios.
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('tenant.not_operational', $response->json('error.code'));
    }

    public function test_un_host_desconocido_devuelve_400_y_no_500(): void
    {
        $response = $this->postJson('https://no-existe.tenants.test/api/v1/auth/login', [
            'email' => 'cajero@no-existe.test',
            'password' => 'correcta-1234',
            'device_name' => 'Tablet de test',
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('tenant.not_resolvable', $response->json('error.code'));
    }

    public function test_un_subdominio_reservado_no_resuelve_a_ningun_comercio(): void
    {
        /*
         * Se crea deliberadamente un tenant cuyo slug choca con un subdominio
         * reservado. Si la lista de reservas no se aplicara, `admin.tenants.test`
         * resolveria a este comercio y la consola de plataforma pisaria al tenant.
         */
        $this->makeTenant(['slug' => 'admin']);

        $response = $this->postJson('https://admin.tenants.test/api/v1/auth/login', [
            'email' => 'cajero@admin.test',
            'password' => 'correcta-1234',
            'device_name' => 'Tablet de test',
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('tenant.not_resolvable', $response->json('error.code'));
    }

    public function test_sin_body_valido_devuelve_422_con_errores_por_campo(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);

        $response = $this->postJson($this->tenantUrl('cafe-sol', '/api/v1/auth/login'), [
            'email' => 'no-es-un-correo',
            'password' => '',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation.failed', $response->json('error.code'));
        $this->assertArrayHasKey('email', $response->json('error.details'));
        $this->assertArrayHasKey('password', $response->json('error.details'));
    }
}
