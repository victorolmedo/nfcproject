<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use Illuminate\Validation\Rule;

/**
 * Credito de puntos por una compra.
 *
 * El importe llega en `amount_minor` (centavos) y nunca en decimal. Un `amount` de
 * tipo float o decimal introduce errores de redondeo en la operacion que mas dinero
 * toca: 19.99 * 0.001 puede dar 0.01999 o 0.02000 segun la ruta. Con enteros de
 * centimos el calculo es exacto de principio a fin.
 */
class EarnRequest extends PosRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),

            /*
             * `min:0` y no `gt:0`: una compra de 0 es legitima (un canje de cortesia,
             * una devolucion) y el motor sabe tratarla, escribiendo la cabecera sin
             * asiento. El limite de negocio lo aplica la regla de acumulo, no el
             * formulario.
             *
             * El servicio sigue rechazando los negativos. Aqui solo se evita que un
             * valor absurdo llegue a la base de datos; la razon por la que un importe
             * negativo no se acredita en silencio la decide `LoyaltyRules`.
             */
            'amount_minor' => ['required', 'integer', 'min:0', 'max:99999999999'],

            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            /*
             * Referencia del ticket o de la operacion en el sistema del comercio.
             *
             * Es lo que permite reconciliar la caja al cierre. El indice unico de la
             * tabla impide que dos tickets distintos acrediten dos veces la misma
             * venta, y tambien impide que el mismo ticket se procese dos veces si llegara con dos
             * claves de idempotencia distintas.
             */
            'external_ref' => ['nullable', 'string', 'max:64', Rule::notIn([''])],

            'occurred_at' => ['nullable', 'date'],

            /*
             * Una compra puede no traer importe (un canje de puntos por una
             * cortesia, una visita que el comercio registra a mano). Entonces el
             * POS envia solo la visita y la regla de visitas decide el otorgamiento.
             */
            'is_visit' => ['nullable', 'boolean'],
        ];
    }

    public function amountMinor(): int
    {
        return (int) $this->validated('amount_minor');
    }

    public function currency(): string
    {
        return (string) $this->validated('currency');
    }

    public function externalRef(): ?string
    {
        $ref = $this->validated('external_ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function isVisit(): bool
    {
        return (bool) ($this->validated('is_visit') ?? false);
    }
}
