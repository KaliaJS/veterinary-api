<?php

namespace App\Http\Requests\V1\Auth;

use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;

class VerifierCodeEmailRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', new AdresseSansAccent],
            'code' => ['required', 'digits:8'],
            'clinique_id' => ['sometimes', 'integer'],
            'appareil' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Indiquez votre adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
            'code.required' => 'Saisissez le code reçu par e-mail.',
            'code.digits' => 'Le code a 8 chiffres.',
        ];
    }
}
