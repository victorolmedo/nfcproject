<?php

declare(strict_types=1);

namespace App\Services\Loyalty\Exceptions;

use App\Exceptions\RendersApiError;
use RuntimeException;

/**
 * El premio no se puede canjear por disponibilidad, no por saldo.
 *
 * Se separa de `InsufficientPoints` a proposito. Son fallos distintos con
 * respuestas distintas para el cajero: aqui el problema es del comercio (no hay
 * stock, el premio esta agotado o despublicado), y la accion es cambiar el premio
 * que se ofrece. En `InsufficientPoints` el problema es del cliente y la accion es
 * cobrarle mas. Confundirlos llevaria a decirle al cliente "te faltan puntos"
 * cuando en realidad el cafe se acabo.
 */
class RewardUnavailable extends RuntimeException implements RendersApiError
{
    /**
     * @param  string  $reason  codigo estable del motivo, para que el POS decida
     *                          sin parsear texto.
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $rewardId,
    ) {
        parent::__construct($message);
    }

    public static function outOfStock(int $rewardId, string $title): self
    {
        return new self(
            "El premio \"{$title}\" esta agotado.",
            'reward_out_of_stock',
            $rewardId,
        );
    }

    public static function inactive(int $rewardId, string $title): self
    {
        return new self(
            "El premio \"{$title}\" no esta disponible para canje.",
            'reward_inactive',
            $rewardId,
        );
    }

    /**
     * La ventana de vigencia del catalogo ya termino.
     *
     * Es un caso aparte de `inactive` porque la causa no es que el comercio haya
     * apagado el premio, sino que la promocion duro lo que tenia que durar. El POS
     * lo muestra distinto para que nadie piense que se puede reactivar desde el
     * panel.
     */
    public static function expired(int $rewardId, string $title, string $validUntil): self
    {
        return new self(
            "El premio \"{$title}\" dejo de estar disponible el {$validUntil}.",
            'reward_expired',
            $rewardId,
        );
    }

    public static function notYetValid(int $rewardId, string $title, string $validFrom): self
    {
        return new self(
            "El premio \"{$title}\" estara disponible a partir del {$validFrom}.",
            'reward_not_yet_valid',
            $rewardId,
        );
    }

    public static function customerLimitReached(
        int $rewardId,
        string $title,
        int $limit,
        int $alreadyRedeemed,
    ): self {
        return new self(
            "El cliente ya canjeo \"{$title}\" {$alreadyRedeemed} veces y el limite son {$limit}.",
            'reward_customer_limit_reached',
            $rewardId,
        );
    }

    public static function totalLimitReached(int $rewardId, string $title, int $limit): self
    {
        return new self(
            "El premio \"{$title}\" agoto su limite global de {$limit} canjes.",
            'reward_total_limit_reached',
            $rewardId,
        );
    }

    public function errorCode(): string
    {
        return 'loyalty.reward_unavailable';
    }

    public function errorStatus(): int
    {
        return 409;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return [
            'reason' => $this->reason,
            'reward_id' => $this->rewardId,
        ];
    }
}
