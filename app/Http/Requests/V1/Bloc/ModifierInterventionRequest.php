<?php

namespace App\Http\Requests\V1\Bloc;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Enums\TypeSalle;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\Intervention;
use App\Models\RendezVous;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reprogrammation / mise à jour du dossier opératoire. Une
 * intervention terminée n'accepte plus que la note opératoire — le
 * contrôleur fait ce tri, la forme des champs est identique à la
 * création. Le rendez-vous lié reste celui du patient de l'intervention
 * et n'en porte qu'une (24.10.2026) : changer de patient ou de
 * rendez-vous ne réattribue jamais le rendez-vous d'un autre animal.
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
                Rule::unique('interventions', 'rendez_vous_id')->ignore($this->intervention()->id),
            ],
            'protocole_anesthesique' => ['sometimes', 'array', 'max:12'],
            'protocole_anesthesique.*' => ['string', 'max:255'],
            'note_operatoire' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** Le rendez-vous lié (nouveau ou gardé) est celui du patient (nouveau ou gardé). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $intervention = $this->intervention();
            $rendezVousId = $this->has('rendez_vous_id') ? $this->input('rendez_vous_id') : $intervention->rendez_vous_id;
            if ($rendezVousId === null) {
                return;
            }
            $patientId = (int) ($this->input('patient_id') ?? $intervention->patient_id);
            $patientDuRendezVous = RendezVous::withoutGlobalScopes()->whereKey((int) $rendezVousId)->value('patient_id');
            if ($patientDuRendezVous !== $patientId) {
                $validator->errors()->add(
                    $this->has('rendez_vous_id') ? 'rendez_vous_id' : 'patient_id',
                    'Le rendez-vous lié est celui d’un autre patient : détachez-le ou liez celui de ce patient.',
                );
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rendez_vous_id.exists' => 'Le rendez-vous lié doit être un RDV de chirurgie non annulé de la clinique.',
            'rendez_vous_id.unique' => 'Ce rendez-vous a déjà son intervention.',
        ];
    }

    private function intervention(): Intervention
    {
        $intervention = $this->route('intervention');
        abort_unless($intervention instanceof Intervention, 404);

        return $intervention;
    }
}
