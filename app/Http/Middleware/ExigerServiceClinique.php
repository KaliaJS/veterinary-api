<?php

namespace App\Http\Middleware;

use App\Auth\ContexteClinique;
use App\Enums\ServiceClinique;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un module de l'espace vétérinaire n'est ouvert que si la clinique du
 * jeton propose le service (coché par l'administrateur, écran
 * Organisation), en plus de la permission du rôle : `service:chirurgie`
 * ferme le bloc d'une clinique qui n'opère pas. Après `clinique`.
 */
class ExigerServiceClinique
{
    public function __construct(private ContexteClinique $contexte) {}

    public function handle(Request $request, Closure $next, string $service): Response
    {
        $voulu = ServiceClinique::from($service);

        if (! $this->contexte->clinique()?->aService($voulu)) {
            abort(403, "La clinique ne propose pas ce service ({$voulu->libelle()}).");
        }

        return $next($request);
    }
}
