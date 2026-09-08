<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — mes animaux : liste sur toutes les fiches,
 * fiche détaillée (carnet dédupliqué par vaccin, visites), ajout sur
 * une fiche du compte, isolation.
 */
class AnimauxProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_liste_couvre_toutes_les_fiches_du_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $ficheA = Client::factory()->create(['user_id' => $user->id]);
        $ficheB = Client::factory()->create(['user_id' => $user->id]);
        Patient::factory()->create(['client_id' => $ficheA->id, 'nom' => 'Max', 'ne_le' => today()->subYears(5)->subMonths(2)]);
        Patient::factory()->create(['client_id' => $ficheB->id, 'nom' => 'Paco', 'ne_le' => today()->subMonths(8)]);
        Patient::factory()->create(['nom' => 'Intrus']);

        $this->getJson('/api/v1/espace/animaux', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nom', 'Max')
            ->assertJsonPath('data.0.age_libelle', '5 ans')
            ->assertJsonPath('data.0.organisation.id', $ficheA->organisation_id)
            ->assertJsonPath('data.1.nom', 'Paco')
            ->assertJsonPath('data.1.age_libelle', '8 mois')
            ->assertJsonPath('data.1.fiche_id', $ficheB->id);
    }

    public function test_la_fiche_porte_le_carnet_et_les_visites(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $max->pesees()->create(['poids_kg' => 24.5, 'pesee_le' => today()]);

        // Rage : deux injections, seule la dernière compte (en retard de 8 jours).
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => today()->subYears(3), 'echeance_le' => today()->subYears(2)]);
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => today()->subYear(), 'echeance_le' => today()->subDays(8)]);
        Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'CHPPi', 'injectee_le' => today()->subDays(30), 'echeance_le' => today()->addDays(334)]);
        $veterinaire = Employe::factory()->create();
        Visite::factory()->create(['patient_id' => $max->id, 'visite_le' => today()->subDays(30), 'motif' => 'Vaccins annuels + contrôle', 'employe_id' => $veterinaire->id]);

        $this->getJson("/api/v1/espace/animaux/{$max->id}", $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.poids_kg', 24.5)
            ->assertJsonCount(2, 'data.vaccinations')
            ->assertJsonPath('data.vaccinations.0.nom', 'Rage')
            ->assertJsonPath('data.vaccinations.0.jours', -8)
            ->assertJsonPath('data.vaccinations.0.statut', 'retard')
            ->assertJsonPath('data.vaccinations.1.nom', 'CHPPi')
            ->assertJsonPath('data.vaccinations.1.statut', 'ok')
            ->assertJsonPath('data.visites.0.motif', 'Vaccins annuels + contrôle')
            ->assertJsonPath('data.visites.0.veterinaire', $veterinaire->nom)
            ->assertJsonPath('data.ordonnance_active_id', null);
    }

    public function test_l_animal_d_un_autre_compte_est_introuvable(): void
    {
        $autre = User::factory()->create();
        $ficheAutre = Client::factory()->create(['user_id' => $autre->id]);
        $intrus = Patient::factory()->create(['client_id' => $ficheAutre->id]);

        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->getJson("/api/v1/espace/animaux/{$intrus->id}", $this->enTetes($jeton))->assertNotFound();
    }

    public function test_un_animal_s_ajoute_sur_la_fiche_du_compte_avec_son_poids(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/espace/animaux', [
            'nom' => 'Nala',
            'espece' => 'CH',
            'race' => 'Beagle',
            'sexe' => 'Femelle',
            'ne_le' => today()->subYears(3)->toDateString(),
            'poids_kg' => 12.4,
            'puce' => '756 098 100 234 567',
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.nom', 'Nala')
            ->assertJsonPath('data.espece_libelle', 'Chien')
            ->assertJsonPath('data.age_libelle', '3 ans')
            ->assertJsonPath('data.poids_kg', 12.4)
            ->assertJsonPath('data.fiche_id', $fiche->id)
            ->assertJsonPath('data.organisation.id', $fiche->organisation_id);

        $this->assertDatabaseHas('patients', ['nom' => 'Nala', 'client_id' => $fiche->id, 'organisation_id' => $fiche->organisation_id]);
    }

    public function test_l_ajout_exige_le_nom_et_une_fiche(): void
    {
        ['jeton' => $jetonSansFiche] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/espace/animaux', ['nom' => 'Nala', 'espece' => 'CH', 'sexe' => 'Femelle'], $this->enTetes($jetonSansFiche))
            ->assertUnprocessable()->assertJsonValidationErrors('fiche_id');

        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        Client::factory()->create(['user_id' => $user->id]);
        Client::factory()->create(['user_id' => $user->id]);

        // Plusieurs fiches : il faut choisir ; une fiche étrangère est refusée.
        $this->postJson('/api/v1/espace/animaux', ['nom' => 'Nala', 'espece' => 'CH', 'sexe' => 'Femelle'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('fiche_id');
        $this->postJson('/api/v1/espace/animaux', ['nom' => 'Nala', 'espece' => 'CH', 'sexe' => 'Femelle', 'fiche_id' => Client::factory()->create()->id], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('fiche_id');

        $this->postJson('/api/v1/espace/animaux', ['espece' => 'CH', 'sexe' => 'Femelle'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('nom');
    }
}
