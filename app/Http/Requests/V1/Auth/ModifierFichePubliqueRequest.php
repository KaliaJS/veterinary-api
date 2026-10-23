<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\TypeParcours;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fiche publique d'un vétérinaire (annuaire) : quelques lignes sur lui,
 * les langues qu'il parle et son parcours (la liste entière, dans
 * l'ordre voulu : postes et formations).
 */
class ModifierFichePubliqueRequest extends FormRequest
{
    /** Langues proposées sur la fiche */
    public const LANGUES = ['fr', 'de', 'it', 'rm', 'en', 'es', 'pt'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $annee = ['nullable', 'integer', 'min:1950', 'max:'.(aujourdhui()->year + 6)];

        return [
            'presentation' => ['nullable', 'string', 'max:600'],
            'langues' => ['present', 'array'],
            'langues.*' => ['distinct', Rule::in(self::LANGUES)],
            'parcours' => ['present', 'array', 'max:30'],
            'parcours.*.type' => ['required', Rule::enum(TypeParcours::class)],
            'parcours.*.titre' => ['required', 'string', 'max:160'],
            'parcours.*.lieu' => ['nullable', 'string', 'max:160'],
            'parcours.*.ville' => ['nullable', 'string', 'max:80'],
            'parcours.*.annee_debut' => $annee,
            'parcours.*.annee_fin' => $annee,
        ];
    }

    /** Une fin avant le début : refusée sur la ligne en cause. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('parcours', []) as $i => $ligne) {
                $debut = $ligne['annee_debut'] ?? null;
                $fin = $ligne['annee_fin'] ?? null;
                if (is_numeric($debut) && is_numeric($fin) && (int) $fin < (int) $debut) {
                    $validator->errors()->add("parcours.$i.annee_fin", 'L’année de fin vient avant celle du début.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'parcours.*.titre.required' => 'Chaque ligne du parcours a besoin d’un intitulé.',
            'presentation.max' => 'La présentation tient en 600 caractères.',
        ];
    }
}
