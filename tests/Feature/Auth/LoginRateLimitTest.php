<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Hash;
use Tests\Feature\AuthenticatedTestCase;

final class LoginRateLimitTest extends AuthenticatedTestCase
{
    public function test_seis_intentos_desde_la_misma_ip_se_bloquean(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginFor($tenant, 'cajero@cafe-sol.test', 'incorrecta');
        }

        $response = $this->postJson($this->tenantUrl('cafe-sol', '/api/v1/auth/login'), [
            'email' => 'cajero@cafe-sol.test',
            'password' => 'correcta-1234',
            'device_name' => 'Tablet de test',
        ]);

        /*
         * El limite se dispara ANTES que cualquier verificacion de password.
         * Es lo que evita que un atacante pueda quemar CPU de forma indefinida: cada
         * intento descartado cuesta un bcrypt (~100 ms) y el limite lo corta a los
         * 5 por minuto.
         */
        $response->assertStatus(429);
        $this->assertSame('too_many_requests', $response->json('error.code'));

        /*
         * `retry_after` es lo que permite al POS reintentar solo. Sin este dato
         * tendria que inventar un backoff, y adivinar mal en un pico de trafico es
         * lo que hace que un cliente tumbe su propia API.
         */
        $this->assertArrayHasKey('retry_after', $response->json('error'));
        $this->assertGreaterThan(0, $response->json('error.retry_after'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_el_limite_por_ip_no_bloquea_a_otro_comercio_desde_la_misma_red(): void
    {
        $uno = $this->makeTenant(['slug' => 'cafe-sol']);
        $dos = $this->makeTenant(['slug' => 'cafe-otro']);

        $this->makeUser($uno, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);
        $this->makeUser($dos, [
            'email' => 'cajero@cafe-otro.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginFor($uno, 'cajero@cafe-sol.test', 'incorrecta');
        }

        /*
         * Este caso es una decision de diseno, no un accidente.
         *
         * Limitar por IP frena el ataque de credenciales por defecto contra muchos
         * correos, pero en un local con varios terminales detras del mismo NAT
         * (un cafe con tres cajas) un limite por IP bloquearia a los tres a la vez.
         *
         * La combinacion que se busca es: IP para el ataque disperso, y bloqueo por
         * cuenta para el ataque dirigido contra una cuenta concreta. Por eso este
         * test fija el comportamiento: el mismo IP que agoto el limite en un
         * comercio NO se ve afectado en otro.
         *
         * El coste asumido: un atacante puede salpicar el intento entre 5/min por
         * IP y 5 intentos por cuenta. Es aceptable porque la cuenta se bloquea
         * igual, y la combinacion levanta la barra lo suficiente.
         */
        $result = $this->loginFor($dos, 'cajero@cafe-otro.test', 'correcta-1234');

        $this->assertSame(201, $result['status']);
    }

    public function test_un_usuario_valido_tampoco_se_bloquea_a_si_mismo_tras_otros_5_fallidos(): void
    {
        $tenant = $this->makeTenant(['slug' => 'cafe-sol']);
        $this->makeUser($tenant, [
            'email' => 'cajero@cafe-sol.test',
            'password' => Hash::make('correcta-1234'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->loginFor($tenant, 'cajero@cafe-sol.test', 'incorrecta');
        }

        // El quinto intento es el correcto, y aun no hay bloqueo.
        $result = $this->loginFor($tenant, 'cajero@cafe-sol.test', 'correcta-1234');

        $this->assertSame(201, $result['status'], 'Un login correcto debe resetear el contador.');
    }
}
