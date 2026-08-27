<?php

namespace App\Http\Requests\V1\Laboratoire;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Une demande d'analyses ne se réécrit pas : seule la note bouge —
 * y compris après validation (annotation du dossier).
 */
class ModifierAnalyseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'nullable', 'string'],
        ];
    }
}
