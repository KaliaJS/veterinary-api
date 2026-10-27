<?php

namespace App\Listeners;

use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Connexion « tant qu'on s'en sert » (décision de Jim, 27.10.2026) :
 * chaque usage repousse la fin du jeton à 30 jours pile, sans limite
 * totale ; l'iPhone, le site et la montre n'ont rien à échanger pour
 * rester connectés. Rien n'est écrit ici : Sanctum enregistre le jeton
 * juste après cet événement (heure de dernier usage, `sanctum.last_used_at`)
 * et la nouvelle fin part dans la même écriture.
 */
class ProlongerConnexion
{
    public function handle(TokenAuthenticated $evenement): void
    {
        $evenement->token->forceFill([
            'expires_at' => now()->addMinutes((int) config('sanctum.inactivite')),
        ]);
    }
}
