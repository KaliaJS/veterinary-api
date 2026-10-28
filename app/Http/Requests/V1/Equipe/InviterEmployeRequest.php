<?php

namespace App\Http\Requests\V1\Equipe;

use App\Rules\AdresseSansAccent;

/**
 * Invitation par e-mail (l'autre chemin que le code employé) : la même
 * fiche que l'embauche, avec le nom et l'adresse de la personne au lieu
 * de son code. Rien n'est créé avant qu'elle accepte.
 */
class InviterEmployeRequest extends EmbaucherEmployeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $regles = parent::rules();
        unset($regles['code']);

        return [
            ...$regles,
            'email' => ['required', 'email', 'max:255', new AdresseSansAccent],
            'nom' => ['required', 'string', 'max:255'],
        ];
    }
}
