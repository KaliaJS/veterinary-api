<?php

namespace App\Auth;

use App\Models\Client;
use App\Models\User;

/**
 * Réconciliation compte ↔ fiches client. Une clinique ouvre une fiche
 * avec l'e-mail du propriétaire, parfois avant que celui-ci ne
 * s'inscrive : à chaque passage dans l'espace, les fiches sans compte
 * portant l'e-mail du compte lui sont rattachées — UNIQUEMENT si
 * l'adresse a été prouvée (code e-mail : `email_verified_at`). Une
 * inscription par passkey n'a rien prouvé : sans vérification, aucune
 * fiche n'est rattachée, sinon n'importe qui récupérerait les dossiers
 * d'un tiers en s'inscrivant avec son adresse.
 */
class RattachementFichesClient
{
    /** @return int Fiches rattachées par ce passage. */
    public function rattacher(User $user): int
    {
        if ($user->email_verified_at === null) {
            return 0;
        }

        return Client::withoutGlobalScopes()
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->update(['user_id' => $user->id]);
    }
}
