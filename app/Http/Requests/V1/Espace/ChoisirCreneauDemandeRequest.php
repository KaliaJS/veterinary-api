<?php

namespace App\Http\Requests\V1\Espace;

use Illuminate\Foundation\Http\FormRequest;

/** Le propriétaire choisit l'un des horaires proposés par la clinique. */
class ChoisirCreneauDemandeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
        ];
    }
}
