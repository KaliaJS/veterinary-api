<?php

namespace App\Http\Requests\V1\Tele;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Démarrage d'un appel vidéo — deux entrées (tele.js) : depuis un RDV
 * `tele` non annulé de la clinique (dont la session devient le dossier
 * maître), ou un appel libre vers un patient de l'organisation.
 */
class DemarrerSessionRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rendez_vous_id' => [
                'sometimes', 'nullable',
                $this->existeDansLaClinique('rendez_vous')
                    ->where('type', TypeRendezVous::Teleconsultation->value)
                    ->whereNotIn('status', [StatutRendezVous::Annule->value, StatutRendezVous::Noshow->value]),
            ],
            'patient_id' => ['required_without:rendez_vous_id', 'nullable', $this->existeDansLOrganisation('patients')],
            'motif' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rendez_vous_id.exists' => 'Le rendez-vous lié doit être une téléconsultation non annulée de la clinique.',
            'patient_id.required_without' => 'Un appel libre vise un patient.',
        ];
    }
}
