<?php

namespace App\Http\Middleware;

use App\Auth\CalculateurPermissions;
use App\Auth\ContexteClinique;
use App\Models\Employe;
use App\Models\JetonApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique le scope multi-clinique globalement : après `auth:sanctum`,
 * la clinique du jeton devient le contexte de la requête — les global
 * scopes des modèles s'y raccrochent. Charge aussi l'employé et ses
 * permissions effectives (rôle + dérogations), lues en base à CHAQUE
 * requête et conservées dans le contexte pour la durée de celle-ci :
 * un changement de permission prend effet immédiatement, sans
 * réémission de jeton. Refuse un jeton sans clinique ou dont le
 * rattachement employé a disparu — sauf en mode `clinique:facultatif`
 * (routes de la session elle-même : profil, passkeys, déconnexion),
 * où un jeton sans clinique passe sans contexte.
 */
class AppliquerContexteClinique
{
    public function __construct(
        private ContexteClinique $contexte,
        private CalculateurPermissions $calculateur,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        $jeton = $user?->currentAccessToken();

        if ($jeton instanceof JetonApi && $jeton->clinique_id === null && $mode === 'facultatif') {
            return $next($request);
        }

        if (! $jeton instanceof JetonApi || $jeton->clinique_id === null) {
            abort(403, 'Jeton non rattaché à une clinique.');
        }

        $employe = Employe::query()
            ->withoutGlobalScope('clinique')
            ->with(['role', 'derogations'])
            ->where('user_id', $user->id)
            ->where('clinique_id', $jeton->clinique_id)
            ->first();

        if (! $employe) {
            abort(403, 'Rattachement à la clinique révoqué.');
        }

        // Suspension d'accès (écran Organisation) : effet immédiat, le
        // jeton reste valide mais la porte est fermée.
        if ($employe->acces_suspendu) {
            abort(403, 'Accès suspendu par l’organisation.');
        }

        $this->contexte->definir($jeton->clinique, $employe, $this->calculateur->pour($employe));

        return $next($request);
    }
}
