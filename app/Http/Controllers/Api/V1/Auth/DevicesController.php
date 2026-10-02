<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispositivos con sesion activa.
 *
 * Es la pantalla que un administrador necesita cuando suspeita de un robo de
 * credenciales: "cerrar sesion en todos los dispositivos" es la accion de
 * contencion de mayor impacto y la que mas a menudo se olvida en un panel hecho
 * a las prisas.
 */
class DevicesController extends ApiController
{
    /**
     * Lista los tokens no revocados y no expirados del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $devices = $request->user()
            ->tokens()
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'device_label', 'last_used_at', 'expires_at', 'created_at'])
            ->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'device_label' => $token->device_label,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'is_current' => $token->id === $currentId,
            ]);

        return $this->success($devices);
    }

    /**
     * Revoca un dispositivo concreto.
     *
     * No se permite revocar el propio token por esta via: para eso esta
     * `logout`. La diferencia evita que un cliente mal construido se quede sin
     * sesion creyendo que solo cerro una pestana.
     */
    public function destroy(Request $request, TokenIssuer $tokens, int $device): JsonResponse
    {
        abort_if($device === $request->user()->currentAccessToken()?->id, 422, 'Usa /logout para cerrar la sesion actual.');

        $user = $request->user();

        $revoked = $user->tokens()
            ->whereKey($device)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $user->id,
            ]);

        abort_if($revoked === 0, 404, 'Dispositivo no encontrado.');

        return $this->success(['message' => 'Dispositivo revocado.']);
    }
}
