<?php

namespace App\Http\Requests\V1\Organisation;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

class ModifierCliniqueRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:100'],
            'raison_sociale' => ['sometimes', 'string', 'max:255'],
            'societe_id' => ['sometimes', $this->existeDansLOrganisation('societes')],
            'rue' => ['sometimes', 'string', 'max:255'],
            'npa' => ['sometimes', 'string', 'regex:/^\d{4}$/'],
            'ville' => ['sometimes', 'string', 'max:100'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'tel' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'fuseau_horaire' => ['sometimes', 'timezone'],
            'nombre_boxes' => ['sometimes', 'integer', 'between:0,50'],
        ];
    }
}
