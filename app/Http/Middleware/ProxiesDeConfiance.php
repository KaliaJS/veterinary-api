<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Les proxys de confiance, lus dans la configuration à chaque requête
 * (24.10.2026). Derrière le répartiteur de charge de Laravel Cloud, sans
 * eux, toutes les requêtes viendraient de son adresse : les limites par
 * IP (connexion, code e-mail) deviendraient communes à tout le monde.
 * `TRUSTED_PROXIES` : « * » (le pair direct, quand l'API n'est joignable
 * que par la plateforme) ou une liste d'adresses séparées par des virgules.
 */
class ProxiesDeConfiance extends TrustProxies
{
    protected function proxies()
    {
        $proxies = trim((string) config('app.proxies_de_confiance'));

        return match ($proxies) {
            '' => null,
            '*', '**' => $proxies,
            default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
        };
    }
}
