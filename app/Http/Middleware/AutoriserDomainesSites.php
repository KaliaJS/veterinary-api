<?php

namespace App\Http\Middleware;

use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS : les origines autorisées sont une liste fixe (CORS_ALLOWED_ORIGINS)
 * — un site servi sur le domaine d'une clinique doit pourtant appeler
 * l'API (site public, prise de rendez-vous). Si l'origine de la requête
 * est le domaine relié d'un site, on l'ajoute à la liste pour cette
 * requête, avant HandleCors. Les domaines connus sont mis en cache une
 * minute.
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
            ->whereIn('domaine_statut', [DomaineSite::ATTENTE, DomaineSite::ACTIF])
            ->pluck('domaine')
            ->all());
    }
}
