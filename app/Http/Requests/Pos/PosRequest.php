<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Support\IdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de las peticiones del POS que mueven puntos.
 *
 * Solo agrupa la resolucion de la clave de idempotencia, que earn y redeem
 * comparten. Las dos operaciones la necesitan y las dos la necesitan igual: sin
 * ella, un reintento del POS tras una perdida de red cobra dos veces.
 *
 * La clave llega en la cabecera `Idempotency-Key`, no en el cuerpo. Es una
 * convencion HTTP (la usan Stripe y GitHub) y tiene una ventaja util sobre un
 * campo del JSON: si el POS reintenta un cuerpo distinto con la misma clave, el
 * conflicto se detecta comparando lo que se recibio antes, en lugar de mezclar la
 * clave con los datos de negocio.
 */
abstract class PosRequest extends FormRequest
{
    /**
     * El permiso NO se comprueba aqui.
     *
     * Vive en el middleware `ability:` de la ruta, que es donde se lee junto a la
     * URL. Si ademas se autorizara en el FormRequest, un endpoint podria quedar
     * protegido por unavia y abierto por la otra segun como se escribiera el
     * controlador. Una sola puerta, declarada en un sitio.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sube la cabecera al cuerpo para que la validacion sea uniforme.
     *
     * La validacion de cabeceras por separado obliga a remember el caso en cada
     * endpoint. Incorporandola al payload, la clave ausente o demasiado larga
     * sale por el mismo camino que cualquier otro dato invalido: un 422 con la
     * lista de errores, no una excepcion.
     */
    protected function prepareForValidation(): void
    {
        $key = $this->header(IdempotencyKey::HEADER);

        $this->merge([
            /*
             * La normalizacion va ANTES de validar, no despues.
             *
             * Recortar y pasar a mayusculas antes de las reglas es lo unico que
             * funciona: el patron de caracteres admitidos rechaza los espacios, de
             * modo que un `Idempotency-Key: POS-1 ` con un espacio final se
             * rechazaria con un 422 y nunca llegaria a normalizarse. El POS no sabria
             * que es un reintento legitimo y lo repetiria, que es justo cuando el
             * cliente ya fue cobrado.
             */
            'idempotency_key' => is_string($key) ? IdempotencyKey::normalize($key) : $key,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            /*
             * La clave es obligatoria, no opcional.
             *
             * Un endpoint de puntos sin idempotencia es un endpoint que cobra dos
             * veces cuando la red falla. Es preferible rechazar la peticion y obligar
             * al POS a reintentar de forma segura a que aceptar una operacion que no
             * se puede distinguir de su repeticion.
             */
            'idempotency_key' => [
                'required',
                'string',
                'max:'.IdempotencyKey::MAX_LENGTH,
                'regex:'.IdempotencyKey::PATTERN,
            ],

            /*
             * El cliente se resuelve por id y no por correo ni telefono.
             *
             * Resolver por un dato que el cajero teclea abriria la puerta a canjear
             * sobre la cuenta equivocada sin que nadie se entere. El id viene del
             * escaneo de la tarjeta, que es el canal fiable. Buscar por codigo de
             * tarjeta es trabajo aparte y su propio endpoint.
             */
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'idempotency_key' => 'Idempotency-Key',
            'customer_id' => 'cliente',
            'amount_minor' => 'importe',
            'currency' => 'moneda',
            'external_ref' => 'referencia externa',
            'occurred_at' => 'momento de la operacion',
            'is_visit' => 'visita',
            'reward_code' => 'codigo de premio',
            'notes' => 'notas',
        ];
    }

    /**
     * La clave ya normalizada y validada.
     *
     * Se devuelve tal cual, sin normalizar otra vez: `validated()` devuelve la clave
     * canonica que produjo `prepareForValidation()`, y volver a aplicar la
     * normalizacion aqui seria redundante.
     */
    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function customerId(): int
    {
        return (int) $this->validated('customer_id');
    }
}
