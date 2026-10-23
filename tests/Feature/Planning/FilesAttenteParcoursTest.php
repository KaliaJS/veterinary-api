<?php

namespace Tests\Feature\Planning;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Liste d'attente et file des urgences, parcours nominal : lecture dans
 * l'ordre de la file (inscription ; gravité puis arrivée), entrée rattachée
 * à une fiche patient (nom, espèce et propriétaire repris de la fiche),
 * retrait sans rendez-vous, placement au plus tôt ou aujourd'hui ; et ce
 * que la validation refuse.
 */
class FilesAttenteParcoursTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private CarbonImmutable $lundi;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        // Un lundi matin à la clinique : ouverte, la journée devant soi
        $this->lundi = CarbonImmutable::now('Europe/Zurich')->next('Monday')->setTime(9, 0);
        $this->travelTo($this->lundi);
        $this->clinique = Clinique::factory()->create();
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    /* Liste d'attente */

    public function test_la_liste_d_attente_se_lit_dans_l_ordre_d_inscription(): void
    {
        $derniere = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id, 'inscrit_le' => now()->subHour()]);
        $premiere = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id, 'inscrit_le' => now()->subDays(3)]);
        $deuxieme = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id, 'inscrit_le' => now()->subDay()]);

        $reponse = $this->getJson('/api/v1/liste-attente', $this->enTetes($this->jeton))->assertOk();

        $this->assertSame([$premiere->id, $deuxieme->id, $derniere->id], array_column($reponse->json('data'), 'id'));
        $reponse->assertJsonPath('data.0.patient_name', $premiere->patient_name)
            ->assertJsonPath('data.0.preference', $premiere->preference)
            ->assertJsonPath('data.0.duree_minutes', $premiere->duree_minutes);
    }

    public function test_un_inscrit_rattache_a_une_fiche_en_reprend_le_nom_l_espece_et_le_proprietaire(): void
    {
        $patient = $this->patient();

        $this->postJson('/api/v1/liste-attente', [
            'patient_id' => $patient->id,
            'patient_name' => 'Saisi à la main',
            'owner_name' => 'Saisi à la main',
            'tel' => '079 555 12 34',
            'reason' => 'Contrôle dermatite',
            'preference' => 'Le matin',
            'duree_minutes' => 30,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.patient_id', $patient->id)
            ->assertJsonPath('data.client_id', $patient->client_id)
            ->assertJsonPath('data.patient_name', 'Pacha')
            ->assertJsonPath('data.species', 'CT')
            ->assertJsonPath('data.owner_name', 'Weber')
            ->assertJsonPath('data.tel', '079 555 12 34')
            ->assertJsonPath('data.preference', 'Le matin');

        $this->assertSame($this->clinique->id, ListeAttente::query()->withoutGlobalScopes()->sole()->clinique_id);
    }

    public function test_retirer_un_inscrit_le_sort_de_la_liste_sans_rendez_vous(): void
    {
        $inscription = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($this->jeton);

        $this->deleteJson("/api/v1/liste-attente/{$inscription->id}", [], $entetes)->assertNoContent();

        $this->getJson('/api/v1/liste-attente', $entetes)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, RendezVous::query()->withoutGlobalScopes()->count());
        $this->deleteJson("/api/v1/liste-attente/{$inscription->id}", [], $entetes)->assertNotFound();
    }

    public function test_placer_un_inscrit_au_plus_tot_prend_le_premier_creneau_libre(): void
    {
        $inscription = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id, 'duree_minutes' => 30, 'reason' => 'Boiterie']);

        $reponse = $this->postJson("/api/v1/liste-attente/{$inscription->id}/placer", [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.ligne_planning_id', $this->ligne->id)
            ->assertJsonPath('data.status', 'prevu')
            ->assertJsonPath('data.type', 'consult')
            ->assertJsonPath('data.flags', ['neuf'])
            ->assertJsonPath('data.reason', 'Boiterie');

        $debut = CarbonImmutable::parse($reponse->json('data.starts_at'));
        $this->assertSame($this->lundi->utc()->toJSON(), $debut->toJSON());
        $this->assertSame(30, (int) $debut->diffInMinutes(CarbonImmutable::parse($reponse->json('data.ends_at'))));
        $this->assertDatabaseMissing('liste_attente', ['id' => $inscription->id]);
    }

    public function test_l_inscription_refuse_une_duree_hors_bornes_une_fiche_ou_une_colonne_d_ailleurs(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $base = ['patient_name' => 'Caramel', 'owner_name' => 'Mme Weber', 'reason' => 'Suivi', 'duree_minutes' => 20];
        $patientAilleurs = Patient::factory()->create();
        $ligneAilleurs = LignePlanning::factory()->create(['clinique_id' => Clinique::factory()->create(['societe_id' => $this->clinique->societe_id])->id]);

        $this->postJson('/api/v1/liste-attente', [...$base, 'duree_minutes' => 5], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('duree_minutes');
        $this->postJson('/api/v1/liste-attente', [...$base, 'reason' => ''], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/liste-attente', [...$base, 'species' => 'licorne'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('species');
        $this->postJson('/api/v1/liste-attente', [...$base, 'patient_id' => $patientAilleurs->id], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->postJson('/api/v1/liste-attente', [...$base, 'ligne_planning_id' => $ligneAilleurs->id], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('ligne_planning_id');

        $this->assertSame(0, ListeAttente::query()->withoutGlobalScopes()->count());
    }

    public function test_placer_sur_une_colonne_exige_une_heure(): void
    {
        $inscription = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson("/api/v1/liste-attente/{$inscription->id}/placer", ['ligne_planning_id' => $this->ligne->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseHas('liste_attente', ['id' => $inscription->id]);
    }

    /* Urgences */

    public function test_la_file_des_urgences_se_lit_par_gravite_puis_par_arrivee(): void
    {
        $benigneAncienne = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'triage' => 1, 'arrivee_le' => now()->subHours(2)]);
        $graveRecente = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'triage' => 3, 'arrivee_le' => now()->subMinutes(5)]);
        $graveAncienne = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'triage' => 3, 'arrivee_le' => now()->subHour()]);
        $moyenne = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'triage' => 2, 'arrivee_le' => now()->subHours(3)]);

        $reponse = $this->getJson('/api/v1/urgences', $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(
            [$graveAncienne->id, $graveRecente->id, $moyenne->id, $benigneAncienne->id],
            array_column($reponse->json('data'), 'id'),
        );
        $reponse->assertJsonPath('data.0.triage', 3)->assertJsonPath('data.0.reason', $graveAncienne->reason);
    }

    public function test_une_urgence_rattachee_a_une_fiche_en_reprend_l_identite(): void
    {
        $patient = $this->patient();

        $this->postJson('/api/v1/urgences', [
            'patient_id' => $patient->id,
            'patient_name' => 'Saisi à la main',
            'owner_name' => 'Saisi à la main',
            'reason' => 'Ingestion de chocolat',
            'triage' => 2,
            'duree_minutes' => 30,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.patient_id', $patient->id)
            ->assertJsonPath('data.client_id', $patient->client_id)
            ->assertJsonPath('data.patient_name', 'Pacha')
            ->assertJsonPath('data.species', 'CT')
            ->assertJsonPath('data.owner_name', 'Weber')
            ->assertJsonPath('data.triage', 2);
    }

    public function test_placer_une_urgence_aujourd_hui_la_met_en_salle_d_attente(): void
    {
        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'duree_minutes' => 20]);
        $debut = $this->lundi->setTime(10, 0);

        $reponse = $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut->toIso8601String(),
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.type', 'urgence')
            ->assertJsonPath('data.status', 'salle')
            ->assertJsonPath('data.patient_name', $urgence->patient_name);

        $rendezVous = RendezVous::query()->withoutGlobalScopes()->findOrFail($reponse->json('data.id'));
        $this->assertNotNull($rendezVous->arrived_at);
        $this->assertTrue($rendezVous->starts_at->equalTo($debut));
        $this->assertDatabaseMissing('urgences_attente', ['id' => $urgence->id]);
    }

    public function test_retirer_une_urgence_la_sort_de_la_file_sans_rendez_vous(): void
    {
        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id]);
        $autre = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id]);
        $entetes = $this->enTetes($this->jeton);

        $this->deleteJson("/api/v1/urgences/{$urgence->id}", [], $entetes)->assertNoContent();

        $reponse = $this->getJson('/api/v1/urgences', $entetes)->assertOk();
        $this->assertSame([$autre->id], array_column($reponse->json('data'), 'id'));
        $this->assertSame(0, RendezVous::query()->withoutGlobalScopes()->count());
    }

    public function test_la_declaration_refuse_une_gravite_ou_une_duree_hors_bornes(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $base = ['patient_name' => 'Filou', 'owner_name' => 'M. Rebours', 'reason' => 'Dyspnée', 'triage' => 3, 'duree_minutes' => 30];
        $patientAilleurs = Patient::factory()->create();

        foreach ([0, 4] as $triage) {
            $this->postJson('/api/v1/urgences', [...$base, 'triage' => $triage], $entetes)
                ->assertUnprocessable()->assertJsonValidationErrors('triage');
        }
        $this->postJson('/api/v1/urgences', [...$base, 'duree_minutes' => 300], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('duree_minutes');
        $this->postJson('/api/v1/urgences', [...$base, 'patient_id' => $patientAilleurs->id], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');

        $this->assertSame(0, UrgenceAttente::query()->withoutGlobalScopes()->count());
    }

    private function patient(): Patient
    {
        $client = Client::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'civilite' => null,
            'nom' => 'Weber',
        ]);

        return Patient::factory()->for($client)->create(['nom' => 'Pacha', 'espece' => 'CT']);
    }
}
