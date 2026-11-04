<?php

namespace App\Http\Requests\V1\Organisation;

use App\Auth\ContexteClinique;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assistant de création d'une clinique (écran Organisation) : identité
 * légale, adresse suisse, rattachement à une société de l'organisation.
 * Les horaires d'ouverture naissent avec la clinique : ceux d'une autre
 * clinique de l'organisation (`hours_from`, 04.11.2026), sinon les
 * valeurs par défaut.
 */
class EnregistrerCliniqueRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:100'],
            'raison_sociale' => ['required', 'string', 'max:255'],
            'societe_id' => ['required', $this->existeDansLOrganisation('societes')],
            'rue' => ['required', 'string', 'max:255'],
            'npa' => ['required', 'string', 'regex:/^\d{4}$/'],
            'ville' => ['required', 'string', 'max:100'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'tel' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'fuseau_horaire' => ['sometimes', 'timezone'],
            'nombre_boxes' => ['sometimes', 'integer', 'between:0,50'],
            'hours_from' => ['sometimes', 'nullable', 'integer', Rule::in(
                app(ContexteClinique::class)->clinique()->societe->organisation->cliniques()->pluck('cliniques.id')->all(),
            )],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hours_from.in' => 'Choisissez une clinique de l’organisation.',
        ];
    }
}
