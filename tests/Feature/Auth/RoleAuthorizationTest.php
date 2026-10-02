<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\StaffRole;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AuthenticatedTestCase;

/**
 * Autorizacion por rol.
 *
 * Monta rutas efimeras con `Route::` durante el test para poder comprobar el
 * middleware `ability:` de verdad. Es preferible a proteger endpoints reales
 * inventados solo para el test, porque asi lo que se verifica es exactamente la
 * cadena de middleware que usara el POS.
 *
 * Incluyen el grupo `api` a proposito. `ResolveTenant` se antepone a ese grupo, no
 * al pipeline entero, asi que una ruta registrada fuera de el se queda sin tenant
 * y falla con un 401 que no explica nada. Declarar el grupo aqui reproduce lo que
 * hace `routes/api.php` de verdad.
 */
final class RoleAuthorizationTest extends AuthenticatedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'staff.auth', 'ability:pos.redeem'])
            ->post('/api/v1/_probe/pos', fn (): array => ['ok' => true]);

        Route::middleware(['api', 'staff.auth', 'ability:staff.write'])
            ->post('/api/v1/_probe/staff', fn (): array => ['ok' => true]);

        Route::middleware(['api', 'staff.auth', 'ability:billing.read'])
            ->get('/api/v1/_probe/billing', fn (): array => ['ok' => true]);
    }

    public function test_un_cajero_puede_operar_en_el_pos_pero_no_gestionar_personal(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/pos'))
            ->assertOk();

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'))
            ->assertStatus(403);
    }

    public function test_un_cajero_no_accede_a_facturacion(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/billing'))
            ->assertStatus(403);
    }

    public function test_un_admin_gestiona_personal_pero_no_facturacion(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'admin@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Admin,
        ]);

        $result = $this->loginFor($tenant, 'admin@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'))
            ->assertOk();

        // Facturacion es exclusiva del dueno. Es la separacion que evita que un
        // administrador con acceso operativo vea los datos de cobro del negocio.
        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/billing'))
            ->assertStatus(403);
    }

    public function test_el_dueno_lo_tiene_todo(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'dueno@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Owner,
        ]);

        $result = $this->loginFor($tenant, 'dueno@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/pos'))->assertOk();

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'))->assertOk();

        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/billing'))->assertOk();
    }

    public function test_un_403_no_se_confunde_con_un_401(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        /*
         * El POS que recibe un 403 y lo trata como "sesion caducada" entra en un bucle
         * de reautenticacion: vuelve a pedir token, vuelve a recibir 403, repite.
         * Por eso el codigo de error diferencia los dos casos y no se responde
         * simplemente con un "no autorizado".
         */
        $response = $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'));

        $response->assertStatus(403);
        $this->assertSame('auth.forbidden', $response->json('error.code'));
        $this->assertNotSame('auth.unauthenticated', $response->json('error.code'));
    }

    public function test_el_rol_se_reevalua_en_cada_peticion_y_no_queda_congelado_en_el_token(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $user = $this->makeUser($tenant, [
            'email' => 'admin@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Admin,
        ]);

        $result = $this->loginFor($tenant, 'admin@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'))
            ->assertOk();

        /*
         * El token guarda las abilities con las que se emitio, asi que por si solo
         * seguiria concediendo `staff.write`. Pero la autorizacion real se deriva
         * del rol vigente, de modo que degradar a la cuenta surte efecto en la
         * siguiente peticion, sin necesidad de revocar tokens a mano.
         */
        $this->asTenant($tenant, function () use ($user): void {
            $user->forceFill(['role' => StaffRole::Cashier])->save();
        });

        $this->withBearer($result['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/_probe/staff'))
            ->assertStatus(403);
    }

    public function test_desactivar_la_cuenta_corta_el_acceso_inmediatamente(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $user = $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertOk();

        $this->asTenant($tenant, function () use ($user): void {
            $user->forceFill(['is_active' => false])->save();
        });

        /*
         * 403 y no 401, sin revocar el token.
         *
         * El token sigue siendo criptograficamente valido: lo que cambio es el
         * estado de la cuenta. La respuesta correcta es "no te dejo", no "no se
         * quien eres", y el codigo `auth.account_disabled` le dice al POS que
         * reintentarlo es inutil porque la causa no es la sesion. Devolver 401
         * haria que el cliente reautenticara en bucle sin resolver nada.
         */
        $response = $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'));

        $response->assertStatus(403);
        $this->assertSame('auth.account_disabled', $response->json('error.code'));
    }
}
