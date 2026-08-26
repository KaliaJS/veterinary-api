<?php

namespace App\Auth;

use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Émission des jetons d'API : un jeton = un utilisateur + une clinique
 * + les abilities de son rôle dans cette clinique, avec expiration.
 * Le rafraîchissement recalcule les abilities (un changement de rôle
 * prend effet au prochain refresh, au plus tard à l'expiration).
 */
class EmetteurJetons
{
    /**
     * @return array{jeton: NewAccessToken, employe: Employe}
     *
     * @throws ValidationException
     */
    public function emettre(User $user, ?int $cliniqueId = null, string $nomAppareil = 'api'): array
    {
        $employe = $this->employePour($user, $cliniqueId);

        $jeton = $user->createToken($nomAppareil, Abilites::pour($employe), $this->expireLe());

        /** @var JetonApi $modele */
        $modele = $jeton->accessToken;
        $modele->forceFill(['clinique_id' => $employe->clinique_id])->save();

        return ['jeton' => $jeton, 'employe' => $employe];
    }

    /**
     * Remplace le jeton courant par un jeton neuf (même clinique,
     * abilities recalculées) et révoque l'ancien.
     *
     * @return array{jeton: NewAccessToken, employe: Employe}
     */
    public function rafraichir(User $user, JetonApi $jetonCourant): array
    {
        $resultat = $this->emettre($user, $jetonCourant->clinique_id, $jetonCourant->name);

        $jetonCourant->delete();

        return $resultat;
    }

    public function expireLe(): Carbon
    {
        return now()->addMinutes((int) config('sanctum.expiration'));
    }

    /**
     * Rattachement clinique retenu pour le jeton. Sans choix explicite,
     * l'unique rattachement de l'utilisateur ; s'il en a plusieurs, la
     * clinique doit être fournie.
     *
     * @throws ValidationException
     */
    private function employePour(User $user, ?int $cliniqueId): Employe
    {
        // Requête d'authentification : hors de tout contexte clinique
        // (le scope global ne doit jamais restreindre le choix du
        // rattachement au moment de l'émission).
        $employes = Employe::query()
            ->withoutGlobalScope('clinique')
            ->where('user_id', $user->id)
            ->get();

        if ($cliniqueId !== null) {
            $employe = $employes->firstWhere('clinique_id', $cliniqueId);

            return $employe ?? throw ValidationException::withMessages([
                'clinique_id' => 'Aucun rattachement à cette clinique.',
            ]);
        }

        return match ($employes->count()) {
            0 => throw ValidationException::withMessages([
                'email' => 'Ce compte n’est rattaché à aucune clinique.',
            ]),
            1 => $employes->first(),
            default => throw ValidationException::withMessages([
                'clinique_id' => 'Ce compte est rattaché à plusieurs cliniques — précisez clinique_id.',
            ]),
        };
    }
}
