<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Auth\EmetteurJetons;
use App\Http\Resources\V1\ChoixCliniqueResource;
use App\Http\Resources\V1\JetonEmisResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Fin commune des voies de connexion : le secret a été contrôlé mais
 * pas consommé. Le rattachement clinique est résolu D'ABORD — un
 * compte multi-clinique sans `clinique_id` reçoit la liste des
 * cliniques et garde son secret pour rejouer la requête —, puis le
 * secret est consommé et le jeton émis. Un compte sans rattachement
 * (inscription publique) reçoit une session sans clinique.
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

        $employe = $emetteur->employeParmi(
            $rattachements,
            $request->filled('clinique_id') ? $request->integer('clinique_id') : null,
        );

        if ($employe === null) {
            return new ChoixCliniqueResource($rattachements);
        }

        $consommerLeSecret();

        return new JetonEmisResource($emetteur->emettrePour($user, $employe, $appareil), $employe);
    }
}
