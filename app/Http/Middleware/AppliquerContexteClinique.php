<?php

namespace App\Http\Middleware;

use App\Auth\ContexteClinique;
use App\Models\Employe;
use App\Models\JetonApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique le scope multi-clinique globalement : après `auth:sanctum`,
 * la clinique du jeton devient le contexte de la requête — les global
 * scopes des modèles s'y raccrochent. Aucun endpoint n'a à filtrer
 * lui-même par clinique. Refuse un jeton sans clinique ou dont le
 * rattachement employé a disparu.
 */
class AppliquerContexteClinique
{
    public function __construct(private ContexteClinique $contexte) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $jeton = $user?->currentAccessToken();

        if (! $jeton instanceof JetonApi || $jeton->clinique_id === null) {
            abort(403, 'Jeton non rattaché à une clinique.');
        }

        $estEmploye = Employe::query()
            ->withoutGlobalScope('clinique')
            ->where('user_id', $user->id)
            ->where('clinique_id', $jeton->clinique_id)
            ->exists();

        if (! $estEmploye) {
            abort(403, 'Rattachement à la clinique révoqué.');
        }

        $this->contexte->definir($jeton->clinique);

        return $next($request);
    }
}
