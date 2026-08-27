<?php

namespace App\Http\Requests\V1\Laboratoire;

use App\Auth\ContexteClinique;
use App\Enums\PrioriteAnalyse;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nouvelle demande d'analyses (laboForm du front) : patient de
 * l'organisation, au moins un panel du référentiel de l'organisation.
 * Le prescripteur est l'employé du jeton, le numéro vient du
 * Numeroteur — jamais du client.
 */
class DemanderAnalyseRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', $this->existeDansLOrganisation('patients')],
            'panels' => ['required', 'array', 'min:1'],
            'panels.*' => [
                'string', 'distinct',
                Rule::exists('panels_labo', 'code')
                    ->where('organisation_id', app(ContexteClinique::class)->organisationId()),
            ],
            'priorite' => ['sometimes', Rule::enum(PrioriteAnalyse::class)],
            'preleve_le' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'panels.required' => 'Cochez au moins un panel d\'analyses.',
            'panels.*.exists' => 'Panel inconnu du référentiel de l\'organisation.',
        ];
    }
}
