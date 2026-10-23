<?php

namespace App\Http\Requests\V1\Espace;

use App\Rules\NumeroPuce;
use Illuminate\Foundation\Http\FormRequest;

/** « Déjà connu d'une clinique ? » : réclamation d'un animal par numéro de puce. */
class ReclamerAnimalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'puce' => ['required', 'string', 'max:32', new NumeroPuce],
            // Second facteur (23.10.2026) : le nom de l'animal, que connaît son propriétaire
            'nom' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'puce.required' => 'Indiquez le numéro de puce de votre animal.',
            'nom.required' => 'Indiquez le nom de votre animal, tel que la clinique le connaît.',
        ];
    }
}
