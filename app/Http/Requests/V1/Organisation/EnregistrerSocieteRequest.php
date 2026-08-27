<?php

namespace App\Http\Requests\V1\Organisation;

use Illuminate\Foundation\Http\FormRequest;

class EnregistrerSocieteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'ide' => ['nullable', 'string', 'max:30', 'regex:/^CHE-\d{3}\.\d{3}\.\d{3}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ide.regex' => 'Le numéro IDE suit le format « CHE-123.456.789 ».',
        ];
    }
}
