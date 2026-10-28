<?php

namespace App\Auth;

use App\Events\AccesCoupe;
use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use App\Support\TempsReel;

/**
 * Révocation des jetons (23.10.2026) : la porte se ferme déjà à la
 * requête suivante (middleware `clinique`), mais le jeton lui-même
 * disparaît aussi, pour qu'il ne serve plus nulle part (espace,
 * invitations, inscription d'une clinique).
 */
class RevocationJetons
{
    /** Les jetons de cette personne sur les cliniques de son organisation (ou sur une seule). */
    public function pourEmploye(Employe $employe, ?int $cliniqueId = null): int
    {
        $cliniques = $cliniqueId !== null
            ? [$cliniqueId]
            : $employe->organisation->cliniques()->pluck('cliniques.id')->all();

        $revoques = JetonApi::query()
            ->where('tokenable_type', $employe->user->getMorphClass())
            ->where('tokenable_id', $employe->user_id)
            ->whereIn('clinique_id', $cliniques)
            ->delete();
        // Ses écrans ouverts l'apprennent aussitôt (décision de Jim, 28.10.2026)
        $userId = (int) $employe->user_id;
        $liste = array_values(array_map('intval', $cliniques));
        TempsReel::diffuser(fn () => AccesCoupe::dispatch($userId, $liste));

        return $revoques;
    }

    /** Tous les jetons du compte, sauf celui de la requête en cours. */
    public function autresQueLeCourant(User $user): int
    {
        $courant = $user->currentAccessToken();

        return $user->tokens()
            ->when($courant instanceof JetonApi, fn ($q) => $q->whereKeyNot($courant->getKey()))
            ->delete();
    }
}
