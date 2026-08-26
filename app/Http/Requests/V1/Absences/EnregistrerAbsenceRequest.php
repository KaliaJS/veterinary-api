<?php

namespace App\Http\Requests\V1\Absences;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Demande (`att`, défaut) ou saisie directe admin (`ok`, qui vide la
 * colonne du praticien comme une validation). `ref` ne se crée pas :
 * c'est l'issue d'un refus.
 */
class EnregistrerAbsenceRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employe_id' => ['required', $this->existeDansLaClinique('employes')],
            'type' => ['required', Rule::enum(TypeAbsence::class)],
            'debut_le' => ['required', 'date'],
            'fin_le' => ['required', 'date', 'after_or_equal:debut_le'],
            'statut' => ['sometimes', Rule::in([StatutAbsence::EnAttente->value, StatutAbsence::Validee->value])],
            'note' => ['nullable', 'string'],
        ];
    }
}
