<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Auth\ChoixClinique;
use App\Auth\EmetteurJetons;
use App\Http\Resources\V1\ChoixCliniqueResource;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Fin commune des voies de connexion : le secret a été contrôlé. Le
 * rattachement clinique est résolu, puis le secret est consommé et le
 * jeton émis. Un compte multi-clinique sans `clinique_id` reçoit la
 * liste des cliniques et un jeton de choix (23.10.2026 : à usage unique,
 * cinq minutes, lié au compte) ; le secret est consommé tout de suite,
 * il ne se rejoue plus. Un compte sans rattachement (inscription
 * publique) reçoit une session sans clinique.
 */
trait ConnecteAvecChoixDeClinique
{
    /** @param  Closure(): void  $consommerLeSecret */
    private function connecter(
        Request $request,
        EmetteurJetons $emetteur,
        User $user,
        Closure $consommerLeSecret,
    ): JetonEmisResource|ChoixCliniqueResource {
        $appareil = $request->string('appareil', 'api')->toString();
        $rattachements = $emetteur->rattachements($user);

        if ($rattachements->isEmpty()) {
            $consommerLeSecret();

            return new JetonEmisResource($emetteur->emettreSansClinique($user, $appareil), null);
        }

        $rattachement = $emetteur->rattachementParmi(
            $rattachements,
            $request->filled('clinique_id') ? $request->integer('clinique_id') : null,
        );

        if ($rattachement === null) {
            $consommerLeSecret();

            return new ChoixCliniqueResource($rattachements, ChoixClinique::ouvrir($user, $appareil));
        }

        $consommerLeSecret();

        return new JetonEmisResource($emetteur->emettrePour($user, $rattachement, $appareil), $rattachement->employe);
    }
}
