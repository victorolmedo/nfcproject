<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'source' => $this->source->value,

            'customer_id' => $this->customer_id,
            'reward_id' => $this->reward_id,
            'external_ref' => $this->external_ref,
            'idempotency_key' => $this->idempotency_key,

            /*
             * El delta es el dato que el cajero necesita ver: cuanto se movio el
             * saldo en esta operacion. Va con signo explicito, y `points_balance_after`
             * junto a el para que el POS pueda mostrar "de 50 a 20" sin una segunda
             * consulta al cliente.
             */
            'points_delta' => $this->points_delta,
            'points_balance_after' => $this->points_balance_after,

            /*
             * `capped_by` y `not_earned_reason` se exponen tal cual, sin traducir.
             *
             * Son la explicacion de por que el cliente recibio menos de lo teorico, o
             * nada. Traducirlos a un texto para el usuario final obligaria al POS a
             * inventar su propio vocabulario y perderia la precision: el POS decide
             * el mensaje, el motor decide el motivo. Los dos motivos son excluyentes
             * por construccion, asi que vienen en el mismo sitio sin ambiguedad.
             */
            'capped_by' => $this->metadata['capped_by'] ?? null,
            'theoretical_points' => $this->metadata['theoretical_points'] ?? null,
            'not_earned_reason' => $this->metadata['not_earned_reason'] ?? null,

            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
