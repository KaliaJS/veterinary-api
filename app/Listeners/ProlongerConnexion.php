<?php

namespace App\Listeners;

use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Connexion « tant qu'on s'en sert » (décision de Jim, 27.10.2026) :
 * chaque usage repousse la fin du jeton à 30 jours, sans limite totale.
 * Au plus une écriture par jour et par jeton ; l'iPhone, le site et la
 * montre n'ont rien à échanger pour rester connectés.
 */
class ProlongerConnexion
{
    public function handle(TokenAuthenticated $evenement): void
    {
        $jeton = $evenement->token;
        $fin = now()->addMinutes((int) config('sanctum.inactivite'));

        if ($jeton->expires_at !== null && $jeton->expires_at->lt($fin->copy()->subDay())) {
            $jeton->forceFill(['expires_at' => $fin])->save();
        }
    }
}
