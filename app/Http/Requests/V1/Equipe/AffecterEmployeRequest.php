<?php

namespace App\Http\Requests\V1\Equipe;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/** Cliniques d'un employé : toutes, ou au moins une cochée. */
class AffecterEmployeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'toutes_les_cliniques' => ['sometimes', 'boolean'],
            'cliniques' => ['required_unless:toutes_les_cliniques,true', 'array', 'min:1'],
            'cliniques.*' => ['integer', 'distinct', $this->estUneCliniqueDeLOrganisation()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cliniques.required_unless' => 'Choisissez au moins une clinique.',
            'cliniques.min' => 'Choisissez au moins une clinique.',
        ];
    }
}
