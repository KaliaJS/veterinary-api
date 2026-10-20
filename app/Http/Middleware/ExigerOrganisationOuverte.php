<?php

namespace App\Http\Middleware;

use App\Auth\ContexteClinique;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toute l'application clinique est payante et réservée aux cliniques
 * vérifiées : une organisation en attente de vérification, refusée ou
 * sans abonnement en cours ne passe pas (403 avec un `code` que le front
 * lit pour afficher la bonne page). Seules les routes de facturation
 * (formule, carte, factures) y échappent, pour pouvoir payer.
 */
class ExigerOrganisationOuverte
{
    public function __construct(private ContexteClinique $contexte) {}

    public function handle(Request $request, Closure $next): Response
    {
        $fermeture = $this->contexte->clinique()?->societe?->organisation?->fermeture();

        if ($fermeture !== null) {
            return response()->json($fermeture, Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
