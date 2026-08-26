<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'API ne parle que JSON : la négociation de contenu est forcée pour
 * que toutes les erreurs (validation, 401, 404…) sortent au format
 * JSON standard quel que soit l'en-tête Accept du client.
 */
class ForcerReponseJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
