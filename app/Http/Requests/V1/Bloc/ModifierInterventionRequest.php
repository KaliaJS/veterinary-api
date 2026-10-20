<?php

namespace App\Http\Requests\V1\Bloc;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Enums\TypeSalle;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reprogrammation / mise à jour du dossier opératoire. Une
 * intervention terminée n'accepte plus que la note opératoire — le
 * contrôleur fait ce tri, la forme des champs est identique à la
 * création.
 */
class ModifierInterventionRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['sometimes', $this->existeDansLOrganisation('patients')],
            'procedure' => ['sometimes', 'string', 'max:255'],
            'prevu_le' => ['sometimes', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'between:10,1440'],
            'salle_id' => ['sometimes', $this->existeDansLaClinique('salles')->where('type', TypeSalle::Bloc->value)],
            'chirurgien_id' => ['sometimes', $this->employeDeLOrganisation()],
            'rendez_vous_id' => [
                'sometimes', 'nullable',
                $this->existeDansLaClinique('rendez_vous')
                    ->where('type', TypeRendezVous::Chirurgie->value)
                    ->whereNot('status', StatutRendezVous::Annule->value),
            ],
            'protocole_anesthesique' => ['sometimes', 'array', 'max:12'],
            'protocole_anesthesique.*' => ['string', 'max:255'],
            'note_operatoire' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
