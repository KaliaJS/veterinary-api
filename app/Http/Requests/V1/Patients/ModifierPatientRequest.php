<?php

namespace App\Http\Requests\V1\Patients;

use App\Enums\AggressionTrigger;
use App\Enums\Allergen;
use App\Enums\Espece;
use App\Enums\Sexe;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Http\Requests\V1\Patients\Concerns\PatientRecordMessages;
use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierPatientRequest extends FormRequest
{
    use PatientRecordMessages, ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', $this->existeDansLOrganisation('clients')],
            'nom' => ['sometimes', 'string', 'max:255'],
            'espece' => ['sometimes', Rule::enum(Espece::class)],
            'race' => ['sometimes', 'nullable', 'string', 'max:255'],
            // La robe (06.11.2026)
            'coat' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sexe' => ['sometimes', Rule::enum(Sexe::class)],
            'ne_le' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            // Jour local suisse (l'application compte en UTC)
            'decede_le' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            'est_sterilise' => ['sometimes', 'boolean'],
            'puce' => ['sometimes', 'nullable', 'string', 'max:32', new NumeroPuce],
            'assurance' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Le vétérinaire référent : un vétérinaire de l'organisation, de quelque clinique qu'il soit (06.11.2026)
            'referring_vet_id' => ['sometimes', 'nullable', $this->referringVetRule()],
            'est_agressif' => ['sometimes', 'boolean'],
            'a_allergies' => ['sometimes', 'boolean'],
            // Les précisions des alertes (02.11.2026)
            'aggression_triggers' => ['sometimes', 'nullable', 'array'],
            'aggression_triggers.*' => ['distinct', Rule::enum(AggressionTrigger::class)],
            'needs_muzzle' => ['sometimes', 'boolean'],
            'allergens' => ['sometimes', 'nullable', 'array'],
            'allergens.*' => ['distinct', Rule::enum(Allergen::class)],
            'allergy_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'traitement_en_cours' => ['sometimes', 'nullable', 'string'],
            // Le poids de forme (03.11.2026) : les deux bornes ensemble, le haut au-dessus du bas
            'healthy_weight_min_kg' => ['present_with:healthy_weight_max_kg', 'nullable', 'numeric', 'min:0.01', 'max:200'],
            'healthy_weight_max_kg' => ['present_with:healthy_weight_min_kg', 'nullable', 'numeric', 'min:0.01', 'max:200', 'gte:healthy_weight_min_kg'],
            'rappels_coupes' => ['sometimes', 'boolean'],
            'client_depuis' => ['sometimes', 'integer', 'min:1990', 'max:'.aujourdhui()->year],
        ];
    }
}
