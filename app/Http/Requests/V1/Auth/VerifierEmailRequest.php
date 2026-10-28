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
}
