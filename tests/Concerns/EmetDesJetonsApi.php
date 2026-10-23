<?php

namespace Tests\Concerns;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;

/**
 * Émet de vrais jetons Sanctum (liés à une clinique, avec abilities) :
 * les tests traversent toute la chaîne — guard, middleware `clinique`,
 * scopes globaux — au lieu de la court-circuiter avec Sanctum::actingAs.
 */
trait EmetDesJetonsApi
{
    /**
     * @return array{user: User, employe: Employe, jeton: string}
     */
    protected function employeAvecJeton(?Clinique $clinique = null, array $attributs = [], ?string $etat = null): array
    {
        // Dans les tests, le contexte « scoped » survit à la requête
        // précédente — on repart neutre pour créer les fixtures.
        app(ContexteClinique::class)->oublier();

        $user = User::factory()->create();

        $employe = Employe::factory()
            ->when($clinique, fn ($fabrique) => $fabrique->dans($clinique))
            ->when($etat, fn ($fabrique) => $fabrique->{$etat}())
            ->create(['user_id' => $user->id, ...$attributs]);

        $resultat = app(EmetteurJetons::class)->emettre($user);

        return [
            'user' => $user,
            'employe' => $employe,
            'jeton' => $resultat['jeton']->plainTextToken,
        ];
    }

    /**
     * Employé au rôle Administrateur (gestion de l'équipe, organisation).
     *
     * @return array{user: User, employe: Employe, jeton: string}
     */
    protected function administrateurAvecJeton(?Clinique $clinique = null, array $attributs = []): array
    {
        return $this->employeAvecJeton($clinique, $attributs, 'administrateur');
    }

    /**
     * Compte sans rattachement clinique (inscrit) : jeton sans clinique.
     *
     * @return array{user: User, jeton: string}
     */
    protected function proprietaireAvecJeton(array $attributs = []): array
    {
        app(ContexteClinique::class)->oublier();

        $user = User::factory()->create($attributs);

        return [
            'user' => $user,
            'jeton' => app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken,
        ];
    }

    /** @return array<string, string> */
    protected function enTetes(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }
}
