<?php

namespace App\Http\Requests\V1\Clients;

use App\Rules\AdresseSansAccent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'e-mail du compte est celui de la fiche par défaut ; sa
 * disponibilité (aucun utilisateur existant) se vérifie au contrôleur
 * car la valeur peut venir de la fiche, pas de la requête.
 */
class CreerCompteClientRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:255', new AdresseSansAccent],
        ];
    }
}
