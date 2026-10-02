<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use Illuminate\Validation\Rule;

/**
 * Canje de un premio por puntos.
 *
 * El premio se pide por `reward_code`, no por `reward_id`. El codigo es lo que el
 * catalogo muestra y lo que el catalogo imprime en el menu del punto de venta; el
 * id es un detalle interno que ademas cambia entre entornos y obliga al POS a
 * codificarlo a mano. Ademas `code` es UNIQUE por tenant, asi que la referencia es
 * inequivoca sin necesidad de resolverla por nombre.
 */
class RedeemRequest extends PosRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),

            'reward_code' => [
                'required',
                'string',
                'max:64',
                /*
                 * `exists` con la tabla de premios. Con RLS activo, la comprobacion
                 * solo ve los premios del tenant resuelto, asi que un `reward_code`
                 * de otro comercio responde "no existe" y no "no tienes permiso".
                 * Es la respuesta correcta: para el POS ambos casos son lo mismo,
                 * un premio que no puede mostrarse.
                 */
                Rule::exists('rewards', 'code'),
            ],

            'external_ref' => ['nullable', 'string', 'max:64', Rule::notIn([''])],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function rewardCode(): string
    {
        return (string) $this->validated('reward_code');
    }

    public function externalRef(): ?string
    {
        $ref = $this->validated('external_ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && $notes !== '' ? $notes : null;
    }
}
