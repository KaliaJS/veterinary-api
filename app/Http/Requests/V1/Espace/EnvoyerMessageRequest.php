<?php

namespace App\Http\Requests\V1\Espace;

use Illuminate\Foundation\Http\FormRequest;

class EnvoyerMessageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinique_id' => ['required', 'integer', 'exists:cliniques,id'],
            'texte' => ['required', 'string', 'max:2000'],
        ];
    }
}
