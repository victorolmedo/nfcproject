<?php

declare(strict_types=1);

use App\Exceptions\RendersApiError;
use App\Exceptions\TenantNotResolved;
use App\Http\Middleware\AuthenticateStaff;
use App\Http\Middleware\CheckStaffAbilities;
use App\Http\Middleware\ResolveTenant;
use App\Services\Tenant\Exceptions\TenantNotResolvable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * `ResolveTenant` se registra como prefijo de la API, no como middleware
         * global.
         *
         * Motivo: la resolucion depende del host/subdominio, que solo tiene
         * sentido en peticiones dirigidas a un comercio. El panel de
         * administracion de la plataforma y los comandos de consola comparten las
         * mismas tablas, pero no tienen un host de tenant, y forzarlos a
         * resolverlo los dejaria inservibles.
         *
         * El orden dentro del grupo `api` es lo que garantiza que el contexto
         * exista antes de `auth:sanctum`: el guard carga el User desde una tabla
         * con RLS, y sin GUC la consulta devuelve cero filas.
         */
        $middleware->api(prepend: [
            ResolveTenant::class,
        ]);

        /*
         * `staff.auth` = identidad + cuenta en condiciones de operar, en un solo
         * middleware.
         *
         * Se expone como un unico alias y no como dos middleware que cada ruta
         * recuerda poner, por un motivo concreto: si `auth:sanctum` y el chequeo de
         * `is_active` se declararan por separado, bastaria con que alguien olvidara
         * el segundo en una ruta nueva para que una cuenta desactivada siguiera
         * operando con el token que ya tenia. El forget seria invisible en
         * revision, porque la ruta "parece" protegida.
         *
         * Con un solo middleware, olvidarse la comprobacion deja de ser una opcion:
         * si no se usa el alias, no hay autenticacion.
         *
         * No se declara como alias con valor ARRAY a proposito:
         * `MiddlewareNameResolver` espera un string y hace `explode(':', ...)`, asi
         * que un array falla en runtime con "Array to string conversion".
         */
        $middleware->alias([
            'tenant.resolved' => ResolveTenant::class,
            'staff.auth' => AuthenticateStaff::class,

            /*
             * Alias de abilities.
             *
             * Se registran a mano porque el esqueleto slim de Laravel 12 no declara
             * los de Sanctum por su cuenta: sin estas lineas,
             * `middleware('ability:x')` falla al resolver con "Target class
             * [ability] does not exist".
             *
             * Apuntan a NUESTRO middleware, no al de Sanctum, porque el de Sanctum
             * lee las abilities congeladas en el token. Ver
             * `App\Http\Middleware\CheckStaffAbilities` para el motivo.
             */
            'ability' => CheckStaffAbilities::class,
            'anyAbility' => CheckStaffAbilities::class.'@handleAny',
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Un unico punto donde se decide como se ve un error en la API.
         *
         * Regla: si la peticion espera JSON, NUNCA se devuelve HTML. Un POS que
         * recibe una pagina de error de Laravel en un 500 no puede distinguir un
         * fallo de red de un fallo de negocio, y el usuario ve una pantalla en
         * blanco en lugar de un mensaje.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Errores de dominio: cada uno declara su codigo, su status HTTP y sus
         * detalles. Agregar un error nuevo no requiere tocar nada aqui, que es
         * justamente la razon de que exista la interfaz RendersApiError.
         */
        $exceptions->render(function (RendersApiError $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $body = [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ];

            if ($e->errorDetails() !== []) {
                $body['details'] = $e->errorDetails();
            }

            return response()->json(['error' => $body], $e->errorStatus());
        });

        /*
         * `TenantNotResolved` es un bug, no un error de peticion: significa que
         * se toco un modelo con scope de tenant sin middleware previo. Se registra
         * aparte de `RendersApiError` a proposito, para que siga arriving como
         * 500 y quede en los logs aunque el render este en modo JSON. Si se
         * devolviera como 4xx, un fallo de aislamiento pasaria por un error de
         * cliente y nadie lo miraria.
         */
        $exceptions->render(function (TenantNotResolved $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'internal.tenant_context_missing',
                    'message' => 'Error interno de aislamiento de tenant.',
                ],
            ], 500);
        });

        // Un host que no corresponde a ningun comercio es un 400 explicito, no un
        // 404 de "ruta no encontrada": la ruta /login existe, lo que no existe es
        // el comercio. El POS necesita distinguir ambos casos.
        $exceptions->render(function (TenantNotResolvable $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                ],
            ], $e->errorStatus());
        });

        // 401 uniforme, con el mismo sobre que el resto de errores.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'auth.unauthenticated',
                    'message' => 'Se requiere autenticacion.',
                ],
            ], 401);
        });

        /*
         * 422 de validacion, con los errores por campo ya en el formato de Laravel.
         */
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Los datos enviados no son validos.',
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        /*
         * Ultimo handler y a proposito: `HttpExceptionInterface` es tan amplia
         * que absorbe 403, 404, 405 y 429. Laravel evalua los handlers en orden
         * de registro, asi que los especificos de arriba ganan y este solo
         * recoge lo que no tiene tratamento dedicado.
         *
         * Nota sobre 404: no se distingue "ruta inexistente" de "modelo no
         * encontrado" a proposito. Laravel convierte `ModelNotFoundException` en
         * `NotFoundHttpException` ANTES de renderizar, asi que cualquier
         * handler para la excepcion original nunca se ejecutaria. Peor aun, el
         * mensaje por defecto de la convertida es "No query results for model
         * [App\Models\Customer] 999": filtra la clase del modelo y confirma que el
         * modelo existe. Un 404 generico no distingue entre un id que no existe y
         * uno que pertenece a otro tenant, que es lo que se quiere.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e->getStatusCode();

            [$code, $message] = match ($status) {
                403 => ['auth.forbidden', 'No tienes permiso para realizar esta accion.'],
                404 => ['resource.not_found', 'Recurso no encontrado.'],
                405 => ['method.not_allowed', 'Metodo no permitido.'],
                429 => ['too_many_requests', 'Demasiadas peticiones.'],
                default => ['http.error', $e->getMessage() ?: 'Error en la peticion.'],
            };

            $body = ['code' => $code, 'message' => $message];

            /*
             * El header `Retry-After` es lo que un cliente bien hecho necesita
             * para reintentar solo. Losing el dato obliga al POS a inventar un
             * backoff, y adivinar un backoff incorrecto es como seabrunden los
             * endpoints durante un pico de trafico.
             */
            if ($status === 429) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 0);
                $body['retry_after'] = $retryAfter;
            }

            return response()->json(['error' => $body], $status, $e->getHeaders());
        });
    })->create();
