<?php

namespace App\Http\Requests\V1\FilesAttente;

use Illuminate\Foundation\Http\FormRequest;

/** D'autres horaires pour une demande en ligne : un à trois débuts à venir, un mot facultatif. */
class ProposerCreneauxRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'creneaux' => ['required', 'array', 'min:1', 'max:3'],
            'creneaux.*' => ['required', 'date', 'after:now', 'distinct'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
