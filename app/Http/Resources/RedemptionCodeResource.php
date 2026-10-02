<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RedemptionCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RedemptionCode
 */
class RedemptionCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,

            /*
             * El valor del premio se expone en las dos formas en que puede venir,
             * sin decidir por el POS cual aplica. Un descuento fijo trae
             * `value_minor` y un porcentaje trae `value_percent`; los demas tipos de
             * premio (un producto, una cortesia) no traen ninguno de los dos. El POS
             * decide como presentarlo, y no tiene que adivinar.
             */
            'value_minor' => $this->value_minor,
            'value_percent' => $this->value_percent,
            'currency' => $this->currency,

            /*
             * `is_redeemable` se calcula en el servidor y no se deduce de la fecha.
             * Es la misma comprobacion que hara el endpoint que gaste el codigo, de
             * modo que el POS puede deshabilitar el boton con la misma logica que va
             * a validar el canje, sin reimplementarla ni adelantarse a la respuesta.
             */
            'is_redeemable' => $this->isRedeemable(),
            'expires_at' => $this->expires_at?->toIso8601String(),

            'reward_id' => $this->reward_id,
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
