<?php

namespace App\Http\Requests\V1\Espace;

use Illuminate\Foundation\Http\FormRequest;

class ModifierCoordonneesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prenom' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'tel' => ['nullable', 'string', 'max:255'],
            'rue' => ['nullable', 'string', 'max:255'],
            'npa' => ['nullable', 'digits:4'],
            'localite' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prenom.required' => 'Prénom et nom requis',
            'nom.required' => 'Prénom et nom requis',
        ];
    }
}
