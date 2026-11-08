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

/**
 * La dette n'est jamais saisie (dérivée de la facturation) et la
 * déclaration AMICUS a son endpoint dédié — ni l'une ni l'autre ne
 * passent ici.
 */
class EnregistrerPatientRequest extends FormRequest
{
    use PatientRecordMessages, ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', $this->existeDansLOrganisation('clients')],
            'nom' => ['required', 'string', 'max:255'],
            'espece' => ['required', Rule::enum(Espece::class)],
            'race' => ['nullable', 'string', 'max:255'],
            // La robe (06.11.2026)
            'coat' => ['nullable', 'string', 'max:120'],
            'isabv_species' => ['sometimes', 'nullable', 'string', Rule::exists('isabv_codes', 'code')->where('kind', 'species')],
            'sexe' => ['required', Rule::enum(Sexe::class)],
            'ne_le' => ['nullable', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            'est_sterilise' => ['sometimes', 'boolean'],
            'puce' => ['nullable', 'string', 'max:32', new NumeroPuce],
            // L'assurance de l'animal (28.10.2026)
            'assurance' => ['nullable', 'string', 'max:120'],
            // Le vétérinaire référent : un vétérinaire de l'organisation (06.11.2026)
            'referring_vet_id' => ['nullable', $this->referringVetRule()],
            'est_agressif' => ['sometimes', 'boolean'],
            'a_allergies' => ['sometimes', 'boolean'],
            // Les précisions des alertes (02.11.2026)
            'aggression_triggers' => ['sometimes', 'nullable', 'array'],
            'aggression_triggers.*' => ['distinct', Rule::enum(AggressionTrigger::class)],
            'needs_muzzle' => ['sometimes', 'boolean'],
            'allergens' => ['sometimes', 'nullable', 'array'],
            'allergens.*' => ['distinct', Rule::enum(Allergen::class)],
            'allergy_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'traitement_en_cours' => ['nullable', 'string'],
            'rappels_coupes' => ['sometimes', 'boolean'],
            'client_depuis' => ['sometimes', 'integer', 'min:1990', 'max:'.aujourdhui()->year],
        ];
    }
}
