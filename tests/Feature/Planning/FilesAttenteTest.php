<?php

namespace Tests\Feature\Planning;

use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Files alimentant le planning : « placer » crée le rendez-vous (une
 * urgence arrive en salle, une demande ou un inscrit en prévu marqué
 * nouveau) et retire l'entrée de la file ; une demande en ligne, elle,
 * reste acceptée (DemandesEnLigneTest).
 */
class FilesAttenteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        // Le matin à Zurich : « demain » est le même jour en UTC et à la clinique
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->setTime(9, 0));
        $this->clinique = Clinique::factory()->create();
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_une_urgence_se_declare_avec_arrivee_horodatee(): void
    {
        $this->postJson('/api/v1/urgences', [
            'patient_name' => 'Filou',
            'species' => 'CH',
            'owner_name' => 'M. Rebours',
            'reason' => 'Abdomen distendu',
            'triage' => 3,
            'duree_minutes' => 45,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.triage', 3);

        $this->assertNotNull(UrgenceAttente::withoutGlobalScopes()->sole()->arrivee_le);
    }

    public function test_placer_une_urgence_un_autre_jour_la_prevoit_et_retire_l_entree(): void
    {
        $urgence = UrgenceAttente::factory()->create([
            'clinique_id' => $this->clinique->id,
            'duree_minutes' => 45,
        ]);
        $debut = aujourdhui()->addDay()->setTime(10, 0);

        $reponse = $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut->toISOString(),
        ], $this->enTetes($this->jeton))->assertCreated();

        $rendezVous = RendezVous::withoutGlobalScopes()->findOrFail($reponse->json('data.id'));

        $this->assertSame('urgence', $rendezVous->type->value);
        // Demain : l'animal reviendra, il n'est pas en salle d'attente
        $this->assertSame('prevu', $rendezVous->status->value);
        $this->assertNull($rendezVous->arrived_at);
        $this->assertSame($urgence->patient_name, $rendezVous->patient_name);
        $this->assertTrue($rendezVous->ends_at->equalTo($debut->clone()->addMinutes(45)));
        $this->assertSame('création', $rendezVous->audits()->sole()->champ);

        $this->assertDatabaseMissing('urgences_attente', ['id' => $urgence->id]);
    }

    public function test_placer_une_urgence_sans_creneau_prend_le_prochain_libre(): void
    {
        $lundi = CarbonImmutable::today()->next('Monday');
        $this->travelTo($lundi->setTime(9, 12));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $urgence = UrgenceAttente::factory()->create([
            'clinique_id' => $this->clinique->id,
            'duree_minutes' => 20,
        ]);

        $reponse = $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [], $this->enTetes($jeton))
            ->assertCreated();

        $this->assertSame($lundi->setTime(9, 12)->toJSON(), $reponse->json('data.starts_at'));
        $this->assertSame($this->ligne->id, $reponse->json('data.ligne_planning_id'));
    }

    public function test_caser_une_urgence_au_plus_tot_passe_aux_jours_suivants(): void
    {
        // Un dimanche (fermé) : le premier trou est le lundi
        $dimancheFerme = CarbonImmutable::today()->next('Sunday');
        $this->travelTo($dimancheFerme->setTime(10, 0));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id]);

        $reponse = $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [], $this->enTetes($jeton))->assertCreated();

        $this->assertTrue(CarbonImmutable::parse($reponse->json('data.starts_at'))->setTimezone('Europe/Zurich')->isMonday());
        $this->assertSame('prevu', $reponse->json('data.status'));
    }

    public function test_placer_une_urgence_sans_aucun_creneau_repond_422(): void
    {
        // Plus aucune ligne au planning : rien de libre
        $this->ligne->delete();
        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseHas('urgences_attente', ['id' => $urgence->id]);
    }

    public function test_placer_a_une_heure_sans_ligne_prend_une_ligne_libre(): void
    {
        $inscription = ListeAttente::factory()->create(['clinique_id' => $this->clinique->id, 'duree_minutes' => 20]);
        $debut = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);

        $this->postJson("/api/v1/liste-attente/{$inscription->id}/placer", ['starts_at' => $debut->toIso8601String()], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.ligne_planning_id', $this->ligne->id);
    }

    public function test_le_calendrier_de_la_clinique_va_au_dela_d_un_mois(): void
    {
        $dansUnAn = CarbonImmutable::today()->addYear()->startOfWeek()->toDateString();

        $this->getJson("/api/v1/planning/semaine?semaine={$dansUnAn}&duree=45", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.semaine', $dansUnAn)
            ->assertJsonPath('data.duree_minutes', 45)
            ->assertJsonPath('data.jours.0.visible', true);
    }

    public function test_placer_une_demande_cree_un_rendez_vous_prevu_marque_nouveau_et_l_accepte(): void
    {
        $demande = DemandeRendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'duree_minutes' => 30,
        ]);

        $reponse = $this->postJson("/api/v1/demandes/{$demande->id}/placer", [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => now()->addDay()->setTime(16, 0)->toISOString(),
        ], $this->enTetes($this->jeton))->assertCreated();

        $reponse->assertJsonPath('data.status', 'prevu')
            ->assertJsonPath('data.type', 'consult')
            ->assertJsonPath('data.flags', ['neuf']);

        // La demande reste, acceptée : le propriétaire en suit l'issue
        $this->assertDatabaseHas('demandes_rendez_vous', ['id' => $demande->id, 'statut' => 'acceptee', 'rendez_vous_id' => $reponse->json('data.id')]);
    }

    public function test_retirer_une_demande_la_sort_de_la_file_sans_creer_de_rendez_vous(): void
    {
        $demande = DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('demandes_rendez_vous', ['id' => $demande->id]);
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
    }

    public function test_l_inscription_en_liste_d_attente_est_horodatee(): void
    {
        $this->postJson('/api/v1/liste-attente', [
            'patient_name' => 'Caramel',
            'owner_name' => 'Mme Weber',
            'reason' => 'Boiterie',
            'duree_minutes' => 20,
            'ligne_planning_id' => $this->ligne->id,
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertNotNull(ListeAttente::withoutGlobalScopes()->sole()->inscrit_le);
    }

    public function test_placer_un_inscrit_borne_la_duree_au_creneau_offert(): void
    {
        $inscription = ListeAttente::factory()->create([
            'clinique_id' => $this->clinique->id,
            'duree_minutes' => 30,
        ]);
        $debut = aujourdhui()->addDay()->setTime(11, 0);

        $reponse = $this->postJson("/api/v1/liste-attente/{$inscription->id}/placer", [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut->toISOString(),
            'duree_minutes' => 60,
        ], $this->enTetes($this->jeton))->assertCreated();

        // min(durée demandée, durée de l'entrée) — jamais plus long que
        // ce que l'inscrit demandait.
        $reponse->assertJsonPath('data.ends_at', $debut->clone()->addMinutes(30)->toJSON())
            ->assertJsonPath('data.flags', ['neuf']);

        $this->assertDatabaseMissing('liste_attente', ['id' => $inscription->id]);
    }
}
