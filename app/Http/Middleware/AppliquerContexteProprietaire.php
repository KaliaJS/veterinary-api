<?php

namespace App\Http\Middleware;

use App\Auth\ContexteProprietaire;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace propriétaire : après `auth:sanctum`, sans contexte clinique.
 * Charge les fiches client rattachées au compte — toutes organisations —
 * dans le ContexteProprietaire. Aucun rattachement automatique (par
 * e-mail ou autre) : une fiche n'est liée à un compte que par un geste
 * de la clinique (compte ouvert depuis la fiche, demande de rattachement
 * acceptée). Tout jeton convient : un employé est aussi, parfois,
 * propriétaire d'un animal.
 */
class AppliquerContexteProprietaire
{
    public function __construct(private ContexteProprietaire $contexte) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $clients = Client::withoutGlobalScopes()
            ->with('organisation')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $this->contexte->definir($user, $clients);

        return $next($request);
    }
}
