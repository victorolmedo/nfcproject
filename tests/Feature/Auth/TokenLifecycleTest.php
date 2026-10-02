<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\StaffRole;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\AuthenticatedTestCase;

final class TokenLifecycleTest extends AuthenticatedTestCase
{
    public function test_el_token_emitido_trae_el_tenant_y_el_dispositivo(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', [
            'device_name' => 'Tablet caja 1',
        ]);

        $token = $this->asTenant($tenant, fn (): PersonalAccessToken => PersonalAccessToken::query()->firstOrFail());

        $this->assertSame($tenant->id, $token->tenant_id, 'El token debe quedar ligado a su comercio.');
        $this->assertSame('Tablet caja 1', $token->name);
        $this->assertNotNull($token->device_label);
    }

    public function test_la_base_guarda_el_hash_y_no_el_token_en_claro(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');
        $plainText = $result['token'];

        $stored = $this->asTenant(
            $tenant,
            fn (): PersonalAccessToken => PersonalAccessToken::query()->firstOrFail(),
        );

        /*
         * Si el valor en claro acabara en la base, una filtracion de la base (o un
         * dump de un backup) entregaria credenciales directamente utilizables, sin
         * necesidad de crackers. Sanctum almacena SHA-256 del SEGMENTO secreto, y
         * este test falla en cuanto eso cambie.
         *
         * El formato del token en claro es `id|secreto`. Hay que quitar el `id|`
         * antes de hashear: hashear el string completo no coincide con lo que hay
         * en la columna `token`.
         */
        $this->assertNotSame($plainText, $stored->token);
        $this->assertStringContainsString('|', (string) $plainText, 'Sanctum entrega el token como id|secreto.');

        $secret = explode('|', (string) $plainText, 2)[1];

        $this->assertSame(hash('sha256', $secret), $stored->token);
    }

    public function test_el_token_por_defecto_expira_en_12_horas(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $token = $this->asTenant($tenant, fn (): PersonalAccessToken => PersonalAccessToken::query()->firstOrFail());

        $horas = (int) now()->diffInMinutes($token->expires_at, absolute: true) / 60;

        $this->assertEqualsWithDelta(12, $horas, 0.2);
    }

    public function test_un_cajero_no_puede_pedir_un_token_de_larga_duracion(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', [
            'remember_device' => true,
        ]);

        /*
         * Un token de 30 dias en manos de un cajero es una credencial de larga
         * duracion obtained con la password mas debil del sistema. Se rechaza y se
         * dice por que, que no es informacion sensible.
         */
        $this->assertSame(403, $result['status']);
        $this->assertSame('auth.long_lived_token_forbidden', $result['json']['error']['code']);
        $this->assertNull($result['token']);
    }

    public function test_un_admin_si_puede_pedir_un_token_de_larga_duracion(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'admin@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Admin,
        ]);

        $result = $this->loginFor($tenant, 'admin@cafe-sol.test', 'correcta-1234', [
            'remember_device' => true,
        ]);

        $this->assertSame(201, $result['status']);

        $token = $this->asTenant($tenant, fn (): PersonalAccessToken => PersonalAccessToken::query()->firstOrFail());

        $dias = now()->diffInDays($token->expires_at, absolute: true);

        $this->assertGreaterThan(20, $dias, 'Un token largo debe durar alrededor de 30 dias.');
    }

    public function test_me_devuelve_el_perfil_con_las_abilities_del_rol_vigente(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
            'role' => StaffRole::Cashier,
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $response = $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'));

        $response->assertOk();

        $abilities = $response->json('data.user.abilities');

        $this->assertContains('pos.redeem', $abilities);
        $this->assertNotContains('staff.write', $abilities);
        $this->assertNotContains('billing.read', $abilities);
    }

    public function test_una_peticion_sin_token_devuelve_401_y_no_500(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $response = $this->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('auth.unauthenticated', $response->json('error.code'));
    }

    public function test_logout_revoca_solo_el_dispositivo_actual(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $caja = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', ['device_name' => 'Caja 1']);
        $tablet = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', ['device_name' => 'Tablet']);

        $response = $this->withBearer($caja['token'])
            ->postJson($this->tenantUrl('cafe-sol', '/api/v1/auth/logout'));

        $response->assertOk();

        // La caja queda fuera...
        $this->withBearer($caja['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertUnauthorized();

        // ...y la tablet sigue viva.
        $this->withBearer($tablet['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertOk();
    }

    public function test_cambiar_la_contrasena_puede_cerrar_todas_las_sesiones(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $uno = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', ['device_name' => 'Caja 1']);
        $dos = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', ['device_name' => 'Caja 2']);

        $response = $this->withBearer($uno['token'])
            ->putJson($this->tenantUrl('cafe-sol', '/api/v1/auth/password'), [
                'current_password' => 'correcta-1234',
                'password' => 'Nueva-Clave-2026!',
                'password_confirmation' => 'Nueva-Clave-2026!',
                'logout_others' => true,
            ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.sessions_revoked'));

        /*
         * Ambos tokens mueren, incluido el de la peticion. Es lo esperable: quien
         * cambia la contrasena porque la cree comprometida necesita que el intruso
         * quede fuera, y dejar vivo su propio token lo obligaria a un logout/login
         * manual en plena incidente.
         */
        $this->withBearer($uno['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertUnauthorized();

        $this->withBearer($dos['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/me'))
            ->assertUnauthorized();

        // Y la password nueva sirve para entrar.
        $nuevo = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'Nueva-Clave-2026!');
        $this->assertSame(201, $nuevo['status']);
    }

    public function test_no_se_puede_cambiar_la_contrasena_sin_la_actual(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->withBearer($result['token'])
            ->putJson($this->tenantUrl('cafe-sol', '/api/v1/auth/password'), [
                'current_password' => 'equivocada',
                'password' => 'Nueva-Clave-2026!',
                'password_confirmation' => 'Nueva-Clave-2026!',
            ])
            ->assertStatus(422);
    }

    public function test_el_lista_de_dispositivos_no_expone_tokens(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234', [
            'device_name' => 'Tablet caja 1',
        ]);

        $response = $this->withBearer($result['token'])
            ->getJson($this->tenantUrl('cafe-sol', '/api/v1/auth/devices'));

        $response->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Tablet caja 1', $response->json('data.0.name'));
        $this->assertTrue($response->json('data.0.is_current'));

        $this->assertArrayNotHasKey('token', $response->json('data.0'));
        $this->assertArrayNotHasKey('abilities', $response->json('data.0'));
    }

    public function test_no_se_puede_revocar_el_dispositivo_actual_por_la_ruta_de_dispositivos(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $id = $this->asTenant(
            $tenant,
            fn (): PersonalAccessToken => PersonalAccessToken::query()->firstOrFail(),
        )->id;

        $this->withBearer($result['token'])
            ->deleteJson($this->tenantUrl('cafe-sol', "/api/v1/auth/devices/{$id}"))
            ->assertStatus(422);
    }
}
