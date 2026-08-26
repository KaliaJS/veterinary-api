<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\Espece;
use App\Enums\TypeRendezVous;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Édition de la fiche RDV (motif, patient, praticien, salle, horaire).
 * Le statut a ses endpoints dédiés ; les drapeaux se posent à la
 * création.
 */
class ModifierRendezVousRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ligne_planning_id' => ['sometimes', $this->existeDansLaClinique('lignes_planning')],
            'starts_at' => ['sometimes', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'min:10', 'max:1440'],
            'type' => ['sometimes', Rule::enum(TypeRendezVous::class)],
            'salle_id' => ['sometimes', 'nullable', $this->existeDansLaClinique('salles')],
            'patient_id' => ['sometimes', 'nullable', $this->existeDansLOrganisation('patients')],
            'patient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'species' => ['sometimes', 'nullable', Rule::enum(Espece::class)],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
