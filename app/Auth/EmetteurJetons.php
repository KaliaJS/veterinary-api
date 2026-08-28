<?php

namespace App\Auth;

use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Émission des jetons d'API : un jeton = un utilisateur + une clinique,
 * avec expiration. Le jeton ne porte AUCUNE permission (abilities
 * Sanctum à ['*']) : les permissions se vérifient en base à chaque
 * requête, via le middleware `clinique` et les Gates — un changement
 * prend effet immédiatement, sans réémission.
 *
 * Le choix du rattachement (employePour) est séparé de l'émission
 * (emettrePour) : les voies de connexion résolvent le rattachement
 * AVANT de consommer leur secret (code e-mail, défi passkey), pour
 * qu'un rattachement multiple sans choix ne brûle rien.
 */
class EmetteurJetons
{
    /**
     * Connexion en un temps : rattachement résolu puis jeton émis. Un
     * rattachement multiple sans choix est refusé (422) — les voies de
     * connexion HTTP préfèrent employePour() pour proposer la liste.
     *
     * @return array{jeton: NewAccessToken, employe: Employe}
     *
     * @throws ValidationException
     */
    public function emettre(User $user, ?int $cliniqueId = null, string $nomAppareil = 'api'): array
    {
        $employe = $this->employePour($user, $cliniqueId) ?? throw ValidationException::withMessages([
            'clinique_id' => 'Ce compte est rattaché à plusieurs cliniques — précisez clinique_id.',
        ]);

        return ['jeton' => $this->emettrePour($user, $employe, $nomAppareil), 'employe' => $employe];
    }

    /** Jeton neuf lié à la clinique du rattachement donné. */
    public function emettrePour(User $user, Employe $employe, string $nomAppareil = 'api'): NewAccessToken
    {
        $jeton = $user->createToken($nomAppareil, ['*'], $this->expireLe());

        /** @var JetonApi $modele */
        $modele = $jeton->accessToken;
        $modele->forceFill(['clinique_id' => $employe->clinique_id])->save();

        return $jeton;
    }

    /**
     * Remplace le jeton courant par un jeton neuf (même clinique) et
     * révoque l'ancien.
     *
     * @return array{jeton: NewAccessToken, employe: Employe}
     */
    public function rafraichir(User $user, JetonApi $jetonCourant): array
    {
        $resultat = $this->emettre($user, $jetonCourant->clinique_id, $jetonCourant->name);

        $jetonCourant->delete();

        return $resultat;
    }

    /**
     * Changement de clinique : jeton neuf vers un autre rattachement de
     * l'utilisateur, l'ancien est révoqué. Le jeton porte la clinique —
     * le changement est une ré-émission, jamais un paramètre de requête.
     *
     * @return array{jeton: NewAccessToken, employe: Employe}
     *
     * @throws ValidationException
     */
    public function changerClinique(User $user, JetonApi $jetonCourant, int $cliniqueId): array
    {
        $resultat = $this->emettre($user, $cliniqueId, $jetonCourant->name);

        $jetonCourant->delete();

        return $resultat;
    }

    public function expireLe(): Carbon
    {
        return now()->addMinutes((int) config('sanctum.expiration'));
    }

    /**
     * Rattachements clinique de l'utilisateur, clinique chargée —
     * requête d'authentification, hors de tout contexte clinique (le
     * scope global ne doit jamais restreindre le choix du rattachement).
     *
     * @return Collection<int, Employe>
     */
    public function rattachements(User $user): Collection
    {
        return Employe::query()
            ->withoutGlobalScope('clinique')
            ->with('clinique')
            ->where('user_id', $user->id)
            ->orderBy('clinique_id')
            ->get();
    }

    /**
     * Rattachement retenu pour le jeton. Sans choix explicite : l'unique
     * rattachement de l'utilisateur, ou `null` s'il en a plusieurs (le
     * front doit faire choisir parmi rattachements()). Un choix qui ne
     * correspond à aucun rattachement, ou un compte sans rattachement,
     * sont refusés.
     *
     * @throws ValidationException
     */
    public function employePour(User $user, ?int $cliniqueId): ?Employe
    {
        $employes = $this->rattachements($user);

        if ($employes->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => 'Ce compte n’est rattaché à aucune clinique.',
            ]);
        }

        if ($cliniqueId !== null) {
            return $employes->firstWhere('clinique_id', $cliniqueId) ?? throw ValidationException::withMessages([
                'clinique_id' => 'Aucun rattachement à cette clinique.',
            ]);
        }

        return $employes->count() === 1 ? $employes->first() : null;
    }
}
