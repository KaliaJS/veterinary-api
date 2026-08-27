<?php

namespace App\Http\Requests\V1\Imagerie;

use App\Enums\ModaliteImagerie;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enregistrement d'un examen à la worklist : patient de
 * l'organisation, opérateur des cliniques de l'organisation (défaut :
 * l'employé du jeton). Le rendez-vous lié — la projection planning de
 * l'examen — doit être un RDV `imagerie` non annulé de la clinique,
 * pas déjà maître d'un autre examen.
 */
class EnregistrerExamenRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', $this->existeDansLOrganisation('patients')],
            'modalite' => ['required', Rule::enum(ModaliteImagerie::class)],
            'libelle' => ['required', 'string', 'max:255'],
            'incidence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'realise_le' => ['required', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'prescripteur_id' => ['sometimes', $this->existeDansLesCliniquesDeLOrganisation('employes')],
            'rendez_vous_id' => [
                'sometimes', 'nullable',
                $this->existeDansLaClinique('rendez_vous')
                    ->where('type', TypeRendezVous::Imagerie->value)
                    ->whereNot('status', StatutRendezVous::Annule->value),
                Rule::unique('examens_imagerie', 'rendez_vous_id'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rendez_vous_id.exists' => 'Le rendez-vous lié doit être un RDV d\'imagerie non annulé de la clinique.',
            'rendez_vous_id.unique' => 'Ce rendez-vous est déjà la projection d\'un autre examen.',
        ];
    }
}
