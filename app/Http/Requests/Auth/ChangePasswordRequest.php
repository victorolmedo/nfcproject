<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Cualquier usuario autenticado puede cambiar su propia contrasena. Que
        // sea admin no le da permiso para cambiar la de otro, y este endpoint no
        // acepta un id de usuario precisamente para que no pueda.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * `current_password` NO se usa, y es deliberado.
             *
             * La regla de Laravel resuelve el usuario por el guard POR DEFECTO,
             * que en este proyecto es `web` y no `sanctum`. Como la API se
             * autentica por token, el guard por defecto puede no tener usuario, y la
             * regla cae entonces a validar contra un `email`+`password` que este
             * endpoint ni siquiera recibe.
             *
             * El resultado es un 422 cuya causa real no aparece en ningun sitio, y
             * depende del estado del guard en ese momento: el mismo test pasa en
             * solitario y falla en la suite completa. Por eso la verificacion se
             * hace contra `$request->user()` en el controller, que es explicito y
             * no depende de la configuracion de guards.
             */
            'current_password' => ['required', 'string', 'max:4096'],

            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'logout_others' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'contrasena actual',
            'password' => 'nueva contrasena',
        ];
    }
}
