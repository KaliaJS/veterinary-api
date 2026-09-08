<?php

namespace App\Http\Requests\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Enums\Espece;
use App\Enums\Sexe;
use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nouvel animal depuis l'espace (formulaire « Nouvel animal » du
 * proto). L'animal naît sur une fiche client du compte : `fiche_id`
 * est obligatoire dès que le compte en a plusieurs.
 */
class EnregistrerAnimalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fiches = app(ContexteProprietaire::class)->clientIds();

        return [
            'fiche_id' => [count($fiches) > 1 ? 'required' : 'sometimes', 'integer', Rule::in($fiches)],
            'nom' => ['required', 'string', 'max:255'],
            'espece' => ['required', Rule::enum(Espece::class)],
            'race' => ['nullable', 'string', 'max:255'],
            'sexe' => ['required', Rule::enum(Sexe::class)],
            'ne_le' => ['nullable', 'date', 'before_or_equal:today'],
            'poids_kg' => ['nullable', 'numeric', 'min:0.01', 'max:500'],
            'puce' => ['nullable', 'string', 'max:32', new NumeroPuce],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fiche_id.required' => 'Choisissez la clinique où rattacher cet animal.',
            'fiche_id.in' => 'Cette fiche n’appartient pas à votre compte.',
            'nom.required' => 'Le nom de l’animal est requis',
        ];
    }
}
