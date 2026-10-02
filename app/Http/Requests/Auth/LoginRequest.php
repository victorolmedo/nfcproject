<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validacion del login.
 *
 * Nota sobre por que el email NO se normaliza con `Str::lower` en la
 * FormRequest: se normaliza en el controller, dentro de la transaccion, para que
 * el valor que se busca en la base sea el mismo que se uso para el indice unico.
 * Si se normalizara en dos lugares, cualquier divergencia futura pasaria
 * desapercibida.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El login es, por definicion, accesible sin estar autenticado.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:4096'],
            'device_name' => ['required', 'string', 'min:2', 'max:120'],
            'remember_device' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato valido.',
            'password.required' => 'La contrasena es obligatoria.',
            'device_name.required' => 'Indica el nombre del dispositivo (ej: "Tablet caja 1").',
        ];
    }
}
