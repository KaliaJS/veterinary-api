<?php

namespace Tests\Feature\Espace;

use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — rendez-vous : liste à venir / passés, annulation
 * auditée, demande de report ; annuaire des cliniques avec créneaux et
 * dépôt d'une demande de rendez-vous.
 */
class RendezVousProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_liste_separe_a_venir_et_passes(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);

        $demain = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20), 'reason' => 'Contrôle otite']);
        $passe = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20), 'status' => StatutRendezVous::Fini]);
        RendezVous::factory()->pourPatient($max)->annule()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(20)]);
        RendezVous::factory()->create(['starts_at' => now()->addDay()]); // un autre client

        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.a_venir')
            ->assertJsonPath('data.a_venir.0.id', $demain->id)
            ->assertJsonPath('data.a_venir.0.motif', 'Contrôle otite')
            ->assertJsonPath('data.a_venir.0.patient.nom', 'Max')
            ->assertJsonPath('data.a_venir.0.peut_annuler', true)
            ->assertJsonMissingPath('data.a_venir.0.no_shows_client')
            ->assertJsonCount(2, 'data.passes')
            ->assertJsonPath('data.passes.1.id', $passe->id)
            ->assertJsonPath('data.passes.1.peut_annuler', false);
    }

    public function test_le_proprietaire_annule_son_rendez_vous_a_venir_et_le_geste_est_audite(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        $rdv = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20)]);

        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'annule')
            ->assertJsonPath('data.peut_annuler', false);

        $this->assertDatabaseHas('rendez_vous_audits', ['rendez_vous_id' => $rdv->id, 'user_id' => $user->id, 'champ' => 'status']);
        $this->assertDatabaseHas('rendez_vous_audits', ['rendez_vous_id' => $rdv->id, 'champ' => 'motifAnnulation']);

        // Déjà annulé : refusé. Un rendez-vous d'un autre compte : introuvable.
        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))->assertUnprocessable();
        $autre = RendezVous::factory()->create(['starts_at' => now()->addDay()]);
        $this->postJson("/api/v1/espace/rendez-vous/{$autre->id}/annuler", [], $this->enTetes($jeton))->assertNotFound();
    }

    public function test_une_demande_de_report_rejoint_la_file_de_la_clinique(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $rdv = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30), 'reason' => 'Vaccin']);

        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/reporter", [], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.clinique.id', $rdv->clinique_id);

        $this->assertDatabaseHas('demandes_rendez_vous', [
            'clinique_id' => $rdv->clinique_id,
            'patient_id' => $max->id,
            'client_id' => $fiche->id,
            'reason' => 'Report — Vaccin',
            'mode' => 'valider',
            'duree_minutes' => 30,
        ]);
    }

    public function test_l_annuaire_des_cliniques_porte_leurs_prochains_creneaux(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'ville' => 'Lausanne']);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        Clinique::factory()->create(['nom' => 'Sans ligne']);

        $reponse = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nom', 'Deux Rives')
            ->assertJsonPath('data.0.ville', 'Lausanne')
            ->assertJsonStructure(['data' => [['id', 'nom', 'ville', 'latitude', 'longitude', 'tel', 'organisation' => ['id', 'nom'], 'creneaux']]])
            ->assertJsonPath('data.1.creneaux', []);

        $creneaux = $reponse->json('data.0.creneaux');
        $this->assertCount(3, $creneaux);
        $this->assertTrue(strtotime($creneaux[0]['debut']) > time());
        $this->assertTrue(strtotime($creneaux[0]['debut']) <= strtotime($creneaux[1]['debut']));
    }

    public function test_une_demande_de_rendez_vous_est_deposee_pour_un_animal_du_compte_ou_un_nom_libre(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);
        $clinique = Clinique::factory()->create();
        $fiche = Client::factory()->create(['user_id' => $user->id, 'organisation_id' => $clinique->societe->organisation_id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $debut = now()->addDays(2)->setTime(14, 30);

        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => 'place',
            'patient_id' => $max->id,
            'motif' => 'Vaccination',
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.clinique.id', $clinique->id)
            ->assertJsonPath('data.patient_name', 'Max')
            ->assertJsonPath('data.mode', 'place');

        $this->assertDatabaseHas('demandes_rendez_vous', ['clinique_id' => $clinique->id, 'patient_id' => $max->id, 'client_id' => $fiche->id, 'reason' => 'Vaccination', 'mode' => 'valider']);

        // Nom libre, en visio, dans une clinique où le compte n'a pas de fiche.
        $autreClinique = Clinique::factory()->create();
        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $autreClinique->id,
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => 'visio',
            'patient_name' => 'Nala',
            'espece' => 'CH',
            'motif' => 'Consultation',
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.patient_name', 'Nala');

        $this->assertDatabaseHas('demandes_rendez_vous', ['clinique_id' => $autreClinique->id, 'patient_id' => null, 'client_id' => null, 'patient_name' => 'Nala', 'owner_name' => 'Anna Meyer']);

        // Sans animal : refusé ; l'animal d'un autre compte : refusé.
        $this->postJson('/api/v1/espace/demandes', ['clinique_id' => $clinique->id, 'debut_souhaite' => $debut->toIso8601String(), 'mode' => 'place', 'motif' => 'Consultation'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('patient_name');
        $intrus = Patient::factory()->create(['client_id' => Client::factory()->create(['user_id' => User::factory()->create()->id])->id]);
        $this->postJson('/api/v1/espace/demandes', ['clinique_id' => $clinique->id, 'debut_souhaite' => $debut->toIso8601String(), 'mode' => 'place', 'patient_id' => $intrus->id, 'motif' => 'Consultation'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
    }
}
