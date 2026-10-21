<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Espace Plateforme : réservé aux modérateurs de Veterinary (donnés par commande). */
class ExigerModerateur
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->est_moderateur, 403, 'Réservé aux modérateurs de Veterinary.');

        return $next($request);
    }
}
