<?php

namespace App\Http\Requests\V1\Site;

use App\Models\SiteClinique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contenu du site : modification partielle (chaque bloc est remplacé
 * en entier quand il est envoyé). Slug en minuscules et tirets, unique
 * entre toutes les cliniques.
 */
class ModifierSiteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $site = SiteClinique::query()->first();

        return [
            'slug' => ['sometimes', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('sites_cliniques', 'slug')->ignore($site?->id)],
            'est_publie' => ['sometimes', 'boolean'],
            'couleur' => ['sometimes', Rule::in(SiteClinique::COULEURS)],
            'accroche' => ['sometimes', 'nullable', 'string', 'max:160'],
            'presentation' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'tel_urgences' => ['sometimes', 'nullable', 'string', 'max:40'],
            'services' => ['sometimes', 'array', 'max:20'],
            'services.*.titre' => ['required', 'string', 'max:80'],
            'services.*.description' => ['nullable', 'string', 'max:400'],
            'equipe' => ['sometimes', 'array', 'max:40'],
            'equipe.*.nom' => ['required', 'string', 'max:80'],
            'equipe.*.role' => ['nullable', 'string', 'max:80'],
            'equipe.*.bio' => ['nullable', 'string', 'max:400'],
            'actualites' => ['sometimes', 'array', 'max:30'],
            'actualites.*.titre' => ['required', 'string', 'max:120'],
            'actualites.*.texte' => ['nullable', 'string', 'max:1500'],
            'actualites.*.date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Lettres minuscules, chiffres et tirets uniquement.',
            'slug.unique' => 'Cette adresse est déjà prise par une autre clinique.',
        ];
    }
}
