<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\CalculusGrade;
use App\Enums\EtatDent;
use App\Enums\GingivitisGrade;
use App\Enums\Occlusion;
use App\Models\Patient;
use App\Support\DentalFormula;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Le schéma dentaire complet d'un patient (04.11.2026), qui remplace le
 * précédent : `teeth` (numéro Triadan → { states, note }), une dent absente
 * de la liste redevient saine ; `overview`, le bilan de la bouche. Les
 * numéros sont ceux de la formule dentaire de l'espèce du patient.
 */
class RemplacerReleveDentaireRequest extends FormRequest
{
    /** Le droit avant la forme : un compte sans droit reçoit 403, pas 422 */
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user()?->can('update', $patient) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'teeth' => ['present', 'array', 'max:44'],
            'teeth.*' => ['array:states,note'],
            'teeth.*.states' => ['present', 'array', 'max:'.count(EtatDent::cases())],
            // Chaque état une fois par dent (`distinct` comparerait toutes les dents entre elles)
            'teeth.*.states.*' => ['string', Rule::enum(EtatDent::class)],
            'teeth.*.note' => ['nullable', 'string', 'max:500'],
            'overview' => ['present', 'array:gingivitis,calculus,occlusion,local_block'],
            'overview.gingivitis' => ['nullable', Rule::enum(GingivitisGrade::class)],
            'overview.calculus' => ['nullable', Rule::enum(CalculusGrade::class)],
            'overview.occlusion' => ['nullable', Rule::enum(Occlusion::class)],
            'overview.local_block' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $teeth = $this->input('teeth');
            if (! is_array($teeth) || $teeth === []) {
                return;
            }
            $patient = $this->route('patient');
            $formula = $patient instanceof Patient ? DentalFormula::teethFor($patient->espece) : null;
            if ($formula === null) {
                $validator->errors()->add('teeth', 'Cette espèce n’a pas de schéma dentaire.');

                return;
            }
            foreach ($teeth as $dent => $releve) {
                if (! preg_match('/^\d{3}$/', (string) $dent) || ! in_array((int) $dent, $formula, true)) {
                    $validator->errors()->add('teeth', "« {$dent} » n’est pas une dent de cette espèce.");
                }
                $etats = is_array($releve) && is_array($releve['states'] ?? null) ? $releve['states'] : [];
                foreach ($etats as $i => $etat) {
                    if (array_search($etat, $etats, true) !== $i) {
                        $validator->errors()->add("teeth.{$dent}.states.{$i}", 'Cet état est déjà sur la dent.');
                    }
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'teeth.max' => 'Trop de dents dans le relevé.',
            'teeth.*.array' => 'Le relevé de cette dent est illisible.',
            'teeth.*.states.*' => 'Cet état n’existe pas.',
            'teeth.*.note.max' => 'La note tient en 500 caractères.',
            'overview.gingivitis' => 'Choisissez aucune, légère, modérée ou sévère.',
            'overview.calculus' => 'Choisissez aucun, minimal, léger, modéré ou massif.',
            'overview.occlusion' => 'Choisissez normale ou MAL1 à MAL4.',
            'overview.local_block.max' => 'Le bloc local tient en 200 caractères.',
        ];
    }
}
