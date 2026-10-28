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
}
