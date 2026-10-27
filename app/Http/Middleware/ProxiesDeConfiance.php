<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Les proxys de confiance, lus dans la configuration à chaque requête
 * (24.10.2026). Derrière le répartiteur de charge de Laravel Cloud, sans
 * eux, toutes les requêtes viendraient de son adresse : les limites par
 * IP (connexion, code e-mail) deviendraient communes à tout le monde.
 * `TRUSTED_PROXIES` : « * » (toute adresse : la plateforme seule joint
 * l'API) ou une liste d'adresses séparées par des virgules ; vide, Laravel
 * fait confiance à tout sur Laravel Cloud.
 *
 * Faire confiance à tout laisse le visiteur choisir son adresse : la
 * bordure de Laravel Cloud (Cloudflare) allonge un X-Forwarded-For forgé
 * sans le remplacer, et Laravel prend la première. Sur Laravel Cloud, l'app
 * n'est joignable que par cette bordure : l'adresse du visiteur est celle
 * que Cloudflare écrit dans CF-Connecting-IP, qu'aucun visiteur ne peut
 * fixer (27.10.2026). `IP_PAR_CLOUDFLARE`, vrai par défaut sur Laravel Cloud.
 */
class ProxiesDeConfiance extends TrustProxies
{
    public function handle(Request $request, Closure $next)
    {
        $ip = $request->headers->get('CF-Connecting-IP');
        if (config('app.ip_par_cloudflare') && is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            // L'adresse du visiteur, et plus de chaîne X-Forwarded-For à remonter
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->remove('X-Forwarded-For');
        }

        return parent::handle($request, $next);
    }

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
