<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Carbon;

/**
 * Resultado de emitir un token, ya materializado a valores primitivos.
 *
 * Existe para que el controller no tenga que tocar el modelo de Sanctum ni
 * conocer la forma de `plainTextToken`. Ademas documenta algo importante: el
 * token en claro se devuelve UNA sola vez y no se vuelve a leer de la base, que
 * solo guarda el hash.
 */
final readonly class PersonalAccessTokenResult
{
    public function __construct(
        public string $token,
        public Carbon $expiresAt,
        public ?int $tokenId,
    ) {}
}
