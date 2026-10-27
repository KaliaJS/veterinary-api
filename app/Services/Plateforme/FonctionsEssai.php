<?php

namespace App\Services\Plateforme;

use App\Models\JetonApi;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Fonctions en essai (Laravel Pennant, côté API seulement, 27.10.2026) :
 * une fonction listée dans `plateforme.fonctions_essai` est active pour
 * tout le monde, pour un compte ou pour une organisation. Rien n'est actif
 * par défaut ; un modérateur active et retire depuis l'écran Plateforme,
 * sans redéployer. La portée « tout le monde » est la chaîne `tous`.
 */
class FonctionsEssai
{
    public const string TOUS = 'tous';

    /** @return array<string, string> nom => libellé */
    public function liste(): array
    {
        return (array) config('plateforme.fonctions_essai', []);
    }

    public function existe(string $nom): bool
    {
        return array_key_exists($nom, $this->liste());
    }

    /** Fonctions actives pour cette personne et l'organisation de sa clinique. @return list<string> */
    public function actives(User $user, ?Organisation $organisation): array
    {
        return array_values(array_filter(array_keys($this->liste()), fn (string $nom): bool => $this->estActive($nom, $user, $organisation)));
    }

    public function estActive(string $nom, User $user, ?Organisation $organisation): bool
    {
        if (! $this->existe($nom)) {
            return false;
        }
        $this->definir($nom);

        return Feature::for(self::TOUS)->active($nom)
            || Feature::for($user)->active($nom)
            || ($organisation !== null && Feature::for($organisation)->active($nom));
    }

    /** L'organisation de la clinique du jeton (null : session sans clinique). */
    public function organisationDuJeton(?JetonApi $jeton): ?Organisation
    {
        return $jeton?->clinique?->societe?->organisation;
    }

    public function activer(string $nom, User|Organisation|string $portee): void
    {
        $this->definir($nom);
        Feature::for($portee)->activate($nom);
    }

    public function retirer(string $nom, User|Organisation|string $portee): void
    {
        $this->definir($nom);
        Feature::for($portee)->deactivate($nom);
    }

    /**
     * Qui a la fonction, lu dans la table de Pennant : tout le monde, des
     * comptes, des organisations.
     *
     * @return array{tous: bool, comptes: list<array{id: int, nom: string, email: string}>, organisations: list<array{id: int, nom: string}>}
     */
    public function porteurs(string $nom): array
    {
        $actives = DB::table((string) config('pennant.stores.database.table', 'features'))
            ->where('name', $nom)
            ->get(['scope', 'value'])
            ->filter(fn (object $ligne): bool => json_decode((string) $ligne->value) === true)
            ->pluck('scope');

        $ids = fn (string $classe): array => $actives
            ->filter(fn (string $portee): bool => str_starts_with($portee, $classe.'|'))
            ->map(fn (string $portee): int => (int) substr($portee, strlen($classe) + 1))
            ->values()->all();

        return [
            'tous' => $actives->contains(self::TOUS),
            'comptes' => User::query()->whereIn('id', $ids(User::class))->orderBy('name')->get()
                ->map(fn (User $user): array => ['id' => $user->id, 'nom' => $user->name, 'email' => $user->email])->values()->all(),
            'organisations' => Organisation::query()->whereIn('id', $ids(Organisation::class))->orderBy('nom')->get()
                ->map(fn (Organisation $organisation): array => ['id' => $organisation->id, 'nom' => $organisation->nom])->values()->all(),
        ];
    }

    /** Inactive par défaut, pour toute portée. */
    private function definir(string $nom): void
    {
        if (! in_array($nom, Feature::defined(), true)) {
            Feature::define($nom, fn (mixed $portee): bool => false);
        }
    }
}
