<?php

namespace App\Auth;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Émission des jetons d'API : un jeton = un utilisateur + une clinique,
 * avec expiration — ou un utilisateur seul (jeton « sans clinique »)
 * pour un compte sans rattachement : inscription publique, ou employé
 * dont tous les rattachements ont disparu. Une telle session n'ouvre
 * que la session elle-même (profil, passkeys, déconnexion), jamais une
 * route clinique. Le jeton ne porte AUCUNE permission (abilities
 * Sanctum à ['*']) : les permissions se vérifient en base à chaque
 * requête, via le middleware `clinique` et les Gates — un changement
 * prend effet immédiatement, sans réémission.
 *
 * Le choix du rattachement (rattachementPour) est séparé de l'émission
 * (emettrePour) : les voies de connexion résolvent le rattachement
 * AVANT de consommer leur secret (code e-mail, défi passkey), pour
 * qu'un rattachement multiple sans choix ne brûle rien.
 */
class EmetteurJetons
{
    /**
     * Connexion en un temps : rattachement résolu puis jeton émis. Un
     * rattachement multiple sans choix est refusé (422) — les voies de
     * connexion HTTP préfèrent rattachementPour() pour proposer la liste.
     *
     * @return array{jeton: NewAccessToken, employe: Employe}
     *
     * @throws ValidationException
     */
    public function emettre(User $user, ?int $cliniqueId = null, string $nomAppareil = 'api'): array
    {
        $rattachement = $this->rattachementPour($user, $cliniqueId) ?? throw ValidationException::withMessages([
            'clinique_id' => 'Ce compte est rattaché à plusieurs cliniques, précisez clinique_id.',
        ]);

        return ['jeton' => $this->emettrePour($user, $rattachement, $nomAppareil), 'employe' => $rattachement->employe];
    }

    /** Jeton neuf lié à la clinique du rattachement donné. */
    public function emettrePour(User $user, Rattachement $rattachement, string $nomAppareil = 'api'): NewAccessToken
    {
        $jeton = $user->createToken($nomAppareil, ['*'], $this->expireLe());

        /** @var JetonApi $modele */
        $modele = $jeton->accessToken;
        $modele->forceFill(['clinique_id' => $rattachement->clinique->id])->save();

        return $jeton;
    }

    /**
     * Jeton sans clinique — compte sans rattachement. Le middleware
     * `clinique` ferme toute route de domaine à ce jeton.
     */
    public function emettreSansClinique(User $user, string $nomAppareil = 'api'): NewAccessToken
    {
        return $user->createToken($nomAppareil, ['*'], $this->expireLe());
    }

    /**
     * Remplace le jeton courant par un jeton neuf (même clinique, ou
     * sans clinique s'il n'en portait pas) et révoque l'ancien.
     *
     * @return array{jeton: NewAccessToken, employe: Employe|null}
     */
    public function rafraichir(User $user, JetonApi $jetonCourant): array
    {
        // Durée absolue : une session ne se prolonge pas indéfiniment
        if ($jetonCourant->debutSession()->lt(now()->subDays(JetonApi::SESSION_MAX_JOURS))) {
            $jetonCourant->delete();

            throw new AuthenticationException('Session expirée : reconnectez-vous.');
        }

        $resultat = $jetonCourant->clinique_id === null
            ? ['jeton' => $this->emettreSansClinique($user, $jetonCourant->name), 'employe' => null]
            : $this->emettre($user, $jetonCourant->clinique_id, $jetonCourant->name);

        $this->garderLaSession($resultat['jeton'], $jetonCourant);
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

        $this->garderLaSession($resultat['jeton'], $jetonCourant);
        $jetonCourant->delete();

        return $resultat;
    }

    /** Le jeton neuf garde le début de la session de l'ancien. */
    private function garderLaSession(NewAccessToken $neuf, JetonApi $ancien): void
    {
        $neuf->accessToken->forceFill(['session_depuis' => $ancien->debutSession()])->save();
    }

    public function expireLe(): Carbon
    {
        return now()->addMinutes((int) config('sanctum.expiration'));
    }

    /**
     * Rattachements de l'utilisateur : chacune de ses fiches (une par
     * organisation) dans chacune des cliniques où elle est affectée,
     * dans l'ordre des cliniques — requête d'authentification, hors de
     * tout contexte clinique (les scopes globaux ne doivent jamais
     * restreindre le choix du rattachement).
     *
     * @return Collection<int, Rattachement>
     */
    public function rattachements(User $user): Collection
    {
        return Employe::withoutGlobalScopes()
            ->with('cliniques.societe')
            ->where('user_id', $user->id)
            ->get()
            // Une clinique de l'organisation de la fiche seulement (défense en profondeur)
            ->flatMap(fn (Employe $employe) => $employe->cliniques
                ->filter(fn (Clinique $clinique): bool => $clinique->societe?->organisation_id === $employe->organisation_id)
                ->map(fn (Clinique $clinique): Rattachement => new Rattachement($employe, $clinique)))
            ->sortBy(fn (Rattachement $rattachement): int => $rattachement->clinique->id)
            ->values();
    }

    /**
     * Rattachement retenu pour le jeton. Sans choix explicite : l'unique
     * rattachement de l'utilisateur, ou `null` s'il en a plusieurs (le
     * front doit faire choisir parmi rattachements()). Un choix qui ne
     * correspond à aucun rattachement, ou un compte sans rattachement,
     * sont refusés — les voies de connexion HTTP testent d'abord
     * rattachements() et émettent un jeton sans clinique dans ce cas.
     *
     * @throws ValidationException
     */
    public function rattachementPour(User $user, ?int $cliniqueId): ?Rattachement
    {
        return $this->rattachementParmi($this->rattachements($user), $cliniqueId);
    }

    /**
     * Même résolution, sur des rattachements déjà chargés.
     *
     * @param  Collection<int, Rattachement>  $rattachements
     *
     * @throws ValidationException
     */
    public function rattachementParmi(Collection $rattachements, ?int $cliniqueId): ?Rattachement
    {
        if ($rattachements->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => 'Ce compte n’est rattaché à aucune clinique.',
            ]);
        }

        if ($cliniqueId !== null) {
            return $rattachements->first(fn (Rattachement $rattachement): bool => $rattachement->clinique->id === $cliniqueId)
                ?? throw ValidationException::withMessages([
                    'clinique_id' => 'Aucun rattachement à cette clinique.',
                ]);
        }

        return $rattachements->count() === 1 ? $rattachements->first() : null;
    }
}
