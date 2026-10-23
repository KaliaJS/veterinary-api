<?php

namespace App\Http\Middleware;

use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS : les origines autorisées sont une liste fixe (config/cors.php,
 * CORS_ALLOWED_ORIGINS) ; un site servi sur le domaine d'une clinique doit
 * pourtant appeler l'API (contenu du site public). Si l'origine de la
 * requête est le domaine ACTIF d'un site (vérifié, jamais un domaine en
 * attente), on l'ajoute à la liste pour cette requête, avant HandleCors.
 * Les domaines actifs sont mis en cache une minute.
 */
class AutoriserDomainesSites
{
    public function handle(Request $request, Closure $next): Response
    {
        $origine = $request->headers->get('Origin');
        $hote = $origine ? strtolower((string) parse_url($origine, PHP_URL_HOST)) : null;

        if ($hote && in_array($hote, $this->domaines(), true)) {
            config(['cors.allowed_origins' => [...config('cors.allowed_origins', []), $origine]]);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function domaines(): array
    {
        return Cache::remember('sites.domaines.cors', 60, fn (): array => SiteWeb::withoutGlobalScopes()
            ->whereNotNull('domaine')
            ->where('domaine_statut', DomaineSite::ACTIF)
            ->pluck('domaine')
            ->all());
    }
}
