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
            // Second facteur (24.10.2026) : le nom de famille du propriétaire
            // inscrit à la clinique, qu'il connaît toujours ; le nom de
            // l'animal, souvent public, ne suffisait pas
            'nom_proprietaire' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'puce.required' => 'Indiquez le numéro de puce de votre animal.',
            'nom_proprietaire.required' => 'Indiquez votre nom de famille, tel que la clinique l’a noté.',
        ];
    }
}
