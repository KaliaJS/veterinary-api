<?php

namespace App\Http\Requests\V1\Auth;

use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;

class VerifierEmailRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', new AdresseSansAccent],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Indiquez votre adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
        ];
    }
}
