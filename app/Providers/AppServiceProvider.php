<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configureSanctum();
    }

    /**
     * Registra el modelo de token propio.
     *
     * Imprescindible para el aislamiento: `HasApiTokens::createToken()` inserta
     * sin `tenant_id` y la politica RLS de `personal_access_tokens` rechazaria
     * esa fila. El modelo personalizado lo rellena en el hook `creating`. Ver
     * `App\Models\PersonalAccessToken` para el detalle.
     */
    private function configureSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * Define los limites de peticiones de la API.
     *
     * Se separan tres limites porque atacan problemas distintos:
     *
     *  - `login`: por IP, y es el mas estricto. El costo por intento (un bcrypt)
     *    hace que sin este limite un atacante queme CPU de forma indefinida.
     *  - `api`: por usuario autenticado, para que un terminal con un bug en un
     *    bucle de reintentos no tumbe la API para los demas.
     *  - `api-ip`: por IP, sin usuario, para lo que llega antes de autenticarse
     *    (resolucion de tenant, /health, escaneos de rutas).
     */
    private function configureRateLimiters(): void
    {
        /*
         * Login: dos limites simultaneos, y ambos tienen que cumplirse.
         *
         * Por que dos y no uno:
         *
         * Un unico limite por IP falla en las dos direcciones. Limitar solo por
         * (correo, IP) deja que un atacante pruebe un monton de correos distintos
         * desde una maquina sin tocar nunca el tope, que es el ataque de
         * credenciales por defecto. Y limitar solo por IP castiga a un comercio
         * legitimo entero en cuanto uno de sus terminales se equivoca cinco veces,
         * porque los tres puntos de venta de un cafe comparten NAT y por tanto IP.
         *
         * La combinacion cubre los dos casos con numeros distintos:
         *
         *   - par (correo, IP), 5/min: frena el ataque dirigido contra UNA cuenta.
         *   - IP, 30/min: frena el spray de muchos correos desde una sola maquina.
         *
         * El 30 es deliberadamente holgado: cabe un local con varios puntos de
         * venta detras de un mismo router sin que se bloqueen entre si, y sigue
         * siendo un techo muy por debajo de lo que un atacante necesita.
         *
         * El bloqueo por cuenta de CredentialsVerifier es la tercera capa y cubre
         * lo que estas dos no pueden: un ataque dirigido que reparte los intentos
         * entre muchas IP, que es donde ninguna limitacion por IP sirve.
         *
         * No se declara un `->response()` propio. La respuesta 429 la construye el
         * renderer global de bootstrap/app.php, que ya emite
         * `too_many_requests` y copia `Retry-After` a `retry_after` en el cuerpo.
         * Un formato propio por endpoint es una segunda fuente de verdad para el
         * mismo error, y las dos se desincronizan.
         */
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(5)->by('pair:'.$email.'|'.$ip),
                Limit::perMinute(30)->by('ip:'.$ip),
            ];
        });

        /*
         * API autenticada: 120 por minuto por usuario.
         *
         * Se usa `$request->user()?->id` y no la IP, porque en un local con varios
         * terminales detras del mismo NAT (un cafe con tres cajas) un limite por IP
         * bloquearia a los tres a la vez. El resto de la respuesta de 429 lo
         * produce el renderer global.
         */
        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user()?->id;

            return Limit::perMinute(120)->by($userId === null ? 'ip:'.($request->ip() ?? 'unknown') : 'user:'.$userId);
        });

        /*
         * Escrituras: 30 por minuto. Un POS no deberia escribir mas que esto, y un
         * bucle de reintentos sobre una cola de puntos queda contenido.
         */
        RateLimiter::for('api-write', function (Request $request): Limit {
            $userId = $request->user()?->id;

            return Limit::perMinute(30)->by($userId === null ? 'ip:'.($request->ip() ?? 'unknown') : 'user:'.$userId);
        });

        /*
         * Operaciones de caja: 120 por minuto.
         *
         * Separate de `api-write` a proposito, y mas alto que sus 30 por minuto. Un
         * POS real acelera en hora punta y el limite de escrituras esta pensado para
         * endpoints que escriben mucho por peticion, no para cobrar. 120/min es unas
         * dos operaciones por segundo: de sobra para una caja, y suficiente para que
         * un bucle de reintentos insane quede contenido en lugar de saturar la base.
         *
         * Se limita por usuario, no por IP. Un local con varias cajas comparte IP y
         * un limite por IP frenaria al comercio entero por el exceso de un solo
         * terminal.
         */
        RateLimiter::for('pos', function (Request $request): Limit {
            $userId = $request->user()?->id;

            return Limit::perMinute(120)->by($userId === null ? 'ip:'.($request->ip() ?? 'unknown') : 'user:'.$userId);
        });
    }
}
