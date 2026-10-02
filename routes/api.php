<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\DevicesController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Pos\EarnController;
use App\Http\Controllers\Api\V1\Pos\RedeemController;
use Illuminate\Support\Facades\Route;

/*
 * API v1.
 *
 * El middleware `ResolveTenant` NO se declara aqui: se antepone a todo el grupo
 * `api` en bootstrap/app.php. Si se declarara por ruta, un endpoint nuevo
 * olvidado de anadirlo seria un agujero de acceso a datos, y ese es exactamente
 * el error que el aislamiento no puede tolerar. Por defecto se hereda, y para
 * abrir una excepcion habria que escribirse `->withoutMiddleware(...)` a mano.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    /*
     * Rutas publicas.
     *
     * `throttle:login` limita por IP. El limite va antes del controlador porque
     * la defensa mas barata es no dejar que la peticion llegue a hacer un bcrypt:
     * cada intento cuesta ~100 ms de CPU, y 5 minutos de ventana a 5 intentos
     * significa que un atacante puede quemar un nucleo de forma indefinida sin
     * llegar jamas a la verificacion.
     */
    Route::middleware('throttle:login')->group(function (): void {
        Route::post('/auth/login', LoginController::class)->name('auth.login');
    });

    /*
     * Rutas autenticadas.
     *
     * `staff.auth` = `auth:sanctum` + comprobacion de cuenta activa. Ver el
     * comentario del alias en bootstrap/app.php: la razon de agruparlos es que no
     * se puedan separar por olvido.
     *
     * `ability:*` NO se aplica de forma global a proposito. Un permiso global
     * seria o demasiado estricto (el cajero no podria ni autenticarse) o demasiado
     * laxo (el cajero podria gestionar personal). Cada endpoint declara la suya,
     * de modo que el permiso se lee junto a la URL, que es donde se revisa.
     */
    Route::middleware('staff.auth')->group(function (): void {
        Route::get('/auth/me', MeController::class)->name('auth.me');

        Route::post('/auth/logout', LogoutController::class)
            ->middleware('throttle:api')
            ->name('auth.logout');

        /*
         * Sin `ability:` a proposito: cambiar la propia contrasena no es un
         * permiso, es una consecuencia de estar autenticado. Meterlo como ability
         * obligaria a anadirla a los tres roles y a mantenerla sincronizada con
         * `User::abilities()` para no dejar a alguien fuera por olvido. La
         * autorizacion real esta en `ChangePasswordRequest::authorize()`, que solo
         * pide estar autenticado, y el endpoint no acepta un id de usuario: no hay
         * forma de cambiar la contrasena de otro.
         */
        Route::put('/auth/password', ChangePasswordController::class)
            ->middleware('throttle:api')
            ->name('auth.password');

        Route::get('/auth/devices', DevicesController::class.'@index')
            ->middleware('throttle:api')
            ->name('auth.devices.index');

        Route::delete('/auth/devices/{device}', DevicesController::class.'@destroy')
            ->middleware('throttle:api')
            ->name('auth.devices.destroy');
    });

    /*
     * Operaciones del punto de venta.
     *
     * Van en su propio grupo, separado del bloque de autenticacion, porque no son
     * "auth" sino la operacion de negocio: las mueve el POS al cobrar y al canjear.
     * `staff.auth` se mantiene porque sin token no hay cajero que haya escaneado a
     * nadie, y no basta con saber quien es: hay que saber que la cuenta siga activa.
     *
     * El permiso se declara en cada ruta y no como middleware global por el motivo
     * ya indicado arriba: o se rompe el uso normal del POS, o un cajero acabaria
     * con permisos de administracion. Aqui se lee junto a la URL.
     */
    Route::middleware('staff.auth')->prefix('pos')->name('pos.')->group(function (): void {
        Route::post('/earn', EarnController::class)
            ->middleware(['ability:pos.earn', 'throttle:pos'])
            ->name('earn');

        Route::post('/redeem', RedeemController::class)
            ->middleware(['ability:pos.redeem', 'throttle:pos'])
            ->name('redeem');
    });
});
