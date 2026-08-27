<?php

namespace App\Http\Requests\V1\Bloc;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Enums\TypeSalle;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Programmation d'une intervention : patient de l'organisation, salle
 * de bloc de la clinique du jeton, chirurgien de l'organisation. Le
 * rendez-vous lié — la projection planning de l'intervention — doit
 * être un RDV `chir` non annulé de la clinique.
 */
class EnregistrerInterventionRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', $this->existeDansLOrganisation('patients')],
            'procedure' => ['required', 'string', 'max:255'],
            'prevu_le' => ['required', 'date'],
            'duree_minutes' => ['required', 'integer', 'between:10,1440'],
            'salle_id' => ['required', $this->existeDansLaClinique('salles')->where('type', TypeSalle::Bloc->value)],
            'chirurgien_id' => ['required', $this->existeDansLesCliniquesDeLOrganisation('employes')],
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'salle_id.exists' => 'Salle de bloc introuvable dans la clinique.',
            'rendez_vous_id.exists' => 'Le rendez-vous lié doit être un RDV de chirurgie non annulé de la clinique.',
        ];
    }
}
