<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil del usuario autenticado y datos del comercio.
 *
 * El POS lo llama al arrancar para saber si su token sigue siendo valido y con
 * que permisos opera, sin depender de lo que haya cacheado en el dispositivo.
 */
class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user' => new UserResource($user),
            'tenant' => new TenantResource($user->tenant),
        ]);
    }
}
