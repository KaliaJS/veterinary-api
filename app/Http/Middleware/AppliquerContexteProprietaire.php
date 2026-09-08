<?php

namespace App\Http\Middleware;

use App\Auth\ContexteProprietaire;
use App\Auth\RattachementFichesClient;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace propriétaire : après `auth:sanctum`, sans contexte clinique.
 * Rattache les fiches en attente (e-mail vérifié), puis charge les
 * fiches client du compte — toutes organisations — dans le
 * ContexteProprietaire. Tout jeton convient : un employé est aussi,
 * parfois, propriétaire d'un animal.
 */
class AppliquerContexteProprietaire
{
    public function __construct(
        private ContexteProprietaire $contexte,
        private RattachementFichesClient $rattachement,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->rattachement->rattacher($user);

        $clients = Client::withoutGlobalScopes()
            ->with('organisation')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $this->contexte->definir($user, $clients);

        return $next($request);
    }
}
