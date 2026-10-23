<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifierCodeEmailRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:8'],
            'clinique_id' => ['sometimes', 'integer'],
            'appareil' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
