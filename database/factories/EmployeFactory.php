<?php

namespace Database\Factories;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employe>
 */
class EmployeFactory extends Factory
{
    public function definition(): array
    {
        $prenom = fake()->firstName();
        $nom = fake()->lastName();

        return [
            // Sans dans() : une clinique neuve, dans sa propre organisation
            'organisation_id' => fn (): int => Clinique::factory()->create()->societe->organisation_id,
            // Chaque employé a son compte
            'user_id' => User::factory(),
            'nom' => 'Dr '.$prenom.' '.$nom,
            'initiales' => Str::upper(Str::substr($prenom, 0, 1).Str::substr($nom, 0, 1)),
            'role_libelle' => 'Vétérinaire',
            'role_id' => fn (array $attributes) => $this->roleParDefaut($attributes['organisation_id'], RolesParDefaut::VETERINAIRE),
            'taux' => fake()->randomElement([60, 80, 100]),
            'contrat' => 'CDI',
            'entree_le' => fake()->dateTimeBetween('-8 years', '-3 months')->modify('first day of this month'),
            'tel' => '07'.fake()->numerify('# ### ## ##'),
            'email' => Str::lower(Str::substr($prenom, 0, 1).'.'.Str::ascii($nom)).'@clinique.ch',
            'conges_droit' => fake()->randomElement([20, 25]),
            'conges_pris' => fake()->numberBetween(0, 15),
        ];
    }

    public function asv(): static
    {
        return $this->state(function (): array {
            $prenom = fake()->firstName();
            $nom = fake()->lastName();

            return [
                'nom' => $prenom.' '.$nom,
                'initiales' => Str::upper(Str::substr($prenom, 0, 1).Str::substr($nom, 0, 1)),
                'role_libelle' => fake()->randomElement(['ASV', 'ASV · Accueil', 'ASV · Bloc']),
                'role_id' => fn (array $attributes) => $this->roleParDefaut($attributes['organisation_id'], RolesParDefaut::ASV),
            ];
        });
    }

    /**
     * Affecté à ces cliniques (de la même organisation). Les affectations
     * se posent avant les rappels afterCreating : configure() ne rajoute
     * alors pas de clinique par défaut.
     */
    public function dans(Clinique|int ...$cliniques): static
    {
        $modeles = collect($cliniques)->map(fn (Clinique|int $clinique): Clinique => $clinique instanceof Clinique ? $clinique : Clinique::findOrFail($clinique));

        return $this
            ->state(['organisation_id' => $modeles->first()->societe->organisation_id])
            ->hasAttached($modeles, [], 'cliniques');
    }

    /** Sans dans() : la clinique de son organisation. */
    public function configure(): static
    {
        return $this->afterCreating(function (Employe $employe): void {
            if ($employe->cliniques()->doesntExist()) {
                $employe->cliniques()->attach(
                    Clinique::query()->whereRelation('societe', 'organisation_id', $employe->organisation_id)->orderBy('id')->value('id'),
                );
            }
        });
    }

    /**
     * Rôle par défaut de l'organisation — créé au besoin (les seeders
     * coupent les événements de modèle).
     */
    private function roleParDefaut(int $organisationId, string $nom): int
    {
        $organisation = Organisation::findOrFail($organisationId);

        RolesParDefaut::creer($organisation);

        return Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->id)
            ->where('nom', $nom)
            ->firstOrFail()
            ->id;
    }
}
