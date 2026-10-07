<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Domaines personnalisés, côté public : le front, ouvert sur un nom qui
 * n'est pas le sien, demande quel site afficher ; le serveur (Caddy
 * on-demand TLS) demande s'il peut émettre un certificat pour un nom.
 */
class DomainePublicController extends Controller
{
    public function site(string $domaine): JsonResponse
    {
        $site = SiteWeb::withoutGlobalScopes()
            ->where('domaine', strtolower($domaine))
            ->where('domaine_statut', DomaineSite::ACTIF)
            ->firstOrFail();

        return response()->json(['data' => ['slug' => $site->slug]]);
    }

    public function autorise(Request $request): Response
    {
        $existe = SiteWeb::withoutGlobalScopes()
            ->where('domaine', strtolower((string) $request->query('domain')))
            ->whereIn('domaine_statut', [DomaineSite::ATTENTE, DomaineSite::ACTIF])
            ->exists();

        return response()->noContent($existe ? 204 : 404);
    }
}
