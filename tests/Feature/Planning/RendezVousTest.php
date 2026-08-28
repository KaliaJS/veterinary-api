<?php

namespace Tests\Feature\Planning;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Salle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Points structurants du domaine : audit par champ avec batch_id sur
 * toute mutation, annulation statutaire (jamais de suppression),
 * chevauchement autorisé, tampon figé, suppression tolérée uniquement
 * dans la fenêtre d'undo d'une création.
 */
class RendezVousTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private User $user;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        ['user' => $this->user, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_creation_enregistre_le_rendez_vous_et_son_audit_de_creation(): void
    {
        $debut = today()->addDay()->setTime(10, 0);

        $reponse = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut->toISOString(),
            'duree_minutes' => 30,
            'patient_name' => 'Nala',
            'species' => 'CT',
            'owner_name' => 'Mme Lefèvre',
            'reason' => 'Boiterie',
        ], $this->enTetes($this->jeton))->assertCreated();

        $rendezVous = RendezVous::withoutGlobalScopes()->findOrFail($reponse->json('data.id'));

        $this->assertSame(StatutRendezVous::Prevu, $rendezVous->status);
        $this->assertTrue($rendezVous->ends_at->equalTo($debut->clone()->addMinutes(30)));

        $audit = $rendezVous->audits()->sole();
        $this->assertSame('création', $audit->champ);
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame($this->ligne->id, $audit->nouvelle_valeur['laneId']);
        $this->assertNotEmpty($audit->batch_id);
    }

    public function test_la_creation_realigne_les_denormalisations_sur_la_fiche_patient(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);
        $patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id]);

        $reponse = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(9, 0)->toISOString(),
            'duree_minutes' => 20,
            'patient_id' => $patient->id,
            'patient_name' => 'Nom périmé',
        ], $this->enTetes($this->jeton))->assertCreated();

        $reponse->assertJsonPath('data.patient_name', $patient->nom)
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.owner_name', $client->nom_affiche);
    }

    public function test_le_chevauchement_d_intervalles_est_accepte(): void
    {
        $debut = today()->addDay()->setTime(10, 0);

        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut,
            'ends_at' => $debut->clone()->addMinutes(30),
        ]);

        $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $debut->clone()->addMinutes(15)->toISOString(),
            'duree_minutes' => 30,
        ], $this->enTetes($this->jeton))->assertCreated();
    }

    public function test_la_modification_audite_chaque_champ_dans_un_meme_lot(): void
    {
        $salle = Salle::factory()->create(['clinique_id' => $this->clinique->id, 'nom' => 'Consultation 3']);
        $rendezVous = $this->rendezVous(['reason' => 'Ancien motif']);

        $this->patchJson("/api/v1/rendez-vous/{$rendezVous->id}", [
            'reason' => 'Nouveau motif',
            'salle_id' => $salle->id,
        ], $this->enTetes($this->jeton))->assertOk();

        $audits = $rendezVous->audits()->get();

        $this->assertSame(1, $audits->pluck('batch_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['reason', 'room'], $audits->pluck('champ')->all());
        $this->assertSame('Consultation 3', $audits->firstWhere('champ', 'room')->nouvelle_valeur);
    }

    public function test_le_deplacement_conserve_la_duree_et_audite_debut_et_ligne_dans_un_meme_lot(): void
    {
        $autreLigne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rendezVous = $this->rendezVous();
        $nouveauDebut = today()->addDay()->setTime(15, 30);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/deplacer", [
            'starts_at' => $nouveauDebut->toISOString(),
            'ligne_planning_id' => $autreLigne->id,
        ], $this->enTetes($this->jeton))->assertOk();

        $rendezVous->refresh();
        $this->assertTrue($rendezVous->starts_at->equalTo($nouveauDebut));
        $this->assertTrue($rendezVous->ends_at->equalTo($nouveauDebut->clone()->addMinutes(30)));
        $this->assertSame($autreLigne->id, $rendezVous->ligne_planning_id);

        $audits = $rendezVous->audits()->get();
        $this->assertSame(1, $audits->pluck('batch_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['startsAt', 'laneId'], $audits->pluck('champ')->all());
    }

    public function test_le_redimensionnement_n_audite_que_la_fin(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/redimensionner", [
            'duree_minutes' => 45,
        ], $this->enTetes($this->jeton))->assertOk();

        $rendezVous->refresh();
        $this->assertTrue($rendezVous->ends_at->equalTo($rendezVous->starts_at->clone()->addMinutes(45)));
        $this->assertSame(['endsAt'], $rendezVous->audits()->pluck('champ')->all());
    }

    public function test_une_duree_sous_10_minutes_est_refusee_en_422(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/redimensionner", [
            'duree_minutes' => 5,
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('duree_minutes');
    }

    public function test_un_tampon_n_est_ni_deplacable_ni_redimensionnable_ni_modifiable(): void
    {
        $tampon = RendezVous::factory()->tampon()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/rendez-vous/{$tampon->id}/deplacer", ['starts_at' => now()->toISOString()], $entetes)
            ->assertUnprocessable();
        $this->postJson("/api/v1/rendez-vous/{$tampon->id}/redimensionner", ['duree_minutes' => 30], $entetes)
            ->assertUnprocessable();
        $this->patchJson("/api/v1/rendez-vous/{$tampon->id}", ['reason' => 'Interdit'], $entetes)
            ->assertUnprocessable();
    }

    public function test_le_passage_en_salle_memorise_l_arrivee_et_l_audite_dans_le_meme_lot(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", [
            'status' => 'salle',
        ], $this->enTetes($this->jeton))->assertOk();

        $rendezVous->refresh();
        $this->assertSame(StatutRendezVous::Salle, $rendezVous->status);
        $this->assertNotNull($rendezVous->arrived_at);

        $audits = $rendezVous->audits()->get();
        $this->assertSame(1, $audits->pluck('batch_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['status', 'arrivedAt'], $audits->pluck('champ')->all());
    }

    public function test_reposer_le_meme_statut_est_sans_effet_ni_audit(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", [
            'status' => 'prevu',
        ], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(0, $rendezVous->audits()->count());
    }

    public function test_le_statut_annule_ne_passe_pas_par_l_endpoint_de_statut(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", [
            'status' => 'annule',
        ], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_le_non_venu_ne_part_que_de_prevu_ou_salle(): void
    {
        $enCours = $this->rendezVous(['status' => StatutRendezVous::Cours]);
        $this->postJson("/api/v1/rendez-vous/{$enCours->id}/non-venu", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();

        $prevu = $this->rendezVous();
        $this->postJson("/api/v1/rendez-vous/{$prevu->id}/non-venu", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.status', 'noshow');

        $this->assertSame(['status'], $prevu->audits()->pluck('champ')->all());
    }

    public function test_l_annulation_est_un_statut_motive_jamais_une_suppression(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/annuler", [
            'motif' => 'Propriétaire souffrant',
        ], $this->enTetes($this->jeton))->assertOk();

        $rendezVous->refresh();
        $this->assertSame(StatutRendezVous::Annule, $rendezVous->status);
        $this->assertDatabaseHas('rendez_vous', ['id' => $rendezVous->id]);

        $audits = $rendezVous->audits()->get();
        $this->assertSame(1, $audits->pluck('batch_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['status', 'motifAnnulation'], $audits->pluck('champ')->all());
        $this->assertSame('Propriétaire souffrant', $audits->firstWhere('champ', 'motifAnnulation')->nouvelle_valeur);
    }

    public function test_un_rendez_vous_annule_est_fige(): void
    {
        $annule = $this->rendezVous(['status' => StatutRendezVous::Annule]);

        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/rendez-vous/{$annule->id}/annuler", [], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/rendez-vous/{$annule->id}/statut", ['status' => 'prevu'], $entetes)->assertUnprocessable();
    }

    public function test_les_annules_restent_dans_la_liste(): void
    {
        $this->rendezVous(['status' => StatutRendezVous::Annule]);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'annule');
    }

    public function test_la_liste_se_filtre_par_plage_de_dates(): void
    {
        $demain = today()->addDay();
        $this->rendezVous(['starts_at' => $demain->clone()->setTime(9, 0), 'ends_at' => $demain->clone()->setTime(9, 30)]);
        $horsPlage = $this->rendezVous([
            'starts_at' => $demain->clone()->addDay()->setTime(9, 0),
            'ends_at' => $demain->clone()->addDay()->setTime(9, 30),
        ]);

        $this->getJson(
            '/api/v1/rendez-vous?du='.$demain->toDateString().'&au='.$demain->clone()->addDay()->toDateString(),
            $this->enTetes($this->jeton),
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $horsPlage->id]);
    }

    public function test_la_liste_sert_le_compte_rendu_redige_et_les_no_shows_du_client(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);
        $autreClinique = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        // Un no-show dans une clinique sœur compte aussi : le client est d'organisation.
        RendezVous::factory()->create([
            'clinique_id' => $autreClinique->id,
            'client_id' => $client->id,
            'status' => StatutRendezVous::Noshow,
        ]);
        $this->rendezVous(['client_id' => $client->id, 'status' => StatutRendezVous::Noshow]);
        $avecCompteRendu = $this->rendezVous(['client_id' => $client->id]);
        CompteRendu::factory()->create(['rendez_vous_id' => $avecCompteRendu->id]);
        $sansClient = $this->rendezVous(['client_id' => null]);

        $reponse = $this->getJson('/api/v1/rendez-vous?per_page=100', $this->enTetes($this->jeton))->assertOk();

        $parId = collect($reponse->json('data'))->keyBy('id');

        $this->assertTrue($parId[$avecCompteRendu->id]['a_compte_rendu']);
        $this->assertSame(2, $parId[$avecCompteRendu->id]['no_shows_client']);
        $this->assertFalse($parId[$sansClient->id]['a_compte_rendu']);
        $this->assertSame(0, $parId[$sansClient->id]['no_shows_client']);

        // Un modèle isolé (fiche, mutation) recalcule les mêmes dérivés.
        $this->getJson("/api/v1/rendez-vous/{$avecCompteRendu->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.a_compte_rendu', true)
            ->assertJsonPath('data.no_shows_client', 2);
    }

    public function test_le_filtre_actifs_ecarte_annules_et_tampons(): void
    {
        $this->rendezVous();
        $this->rendezVous(['status' => StatutRendezVous::Annule]);
        $this->rendezVous(['type' => TypeRendezVous::Tampon, 'patient_name' => null]);

        $this->getJson('/api/v1/rendez-vous?actifs=1&per_page=1', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/rendez-vous?per_page=1', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_la_suppression_est_toleree_dans_la_fenetre_d_undo_de_la_creation(): void
    {
        $id = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(11, 0)->toISOString(),
            'duree_minutes' => 20,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertDatabaseMissing('rendez_vous', ['id' => $id]);
    }

    public function test_la_suppression_est_refusee_apres_un_autre_geste(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $id = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(11, 0)->toISOString(),
            'duree_minutes' => 20,
        ], $entetes)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/rendez-vous/{$id}/redimensionner", ['duree_minutes' => 30], $entetes)->assertOk();

        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $entetes)->assertUnprocessable();
        $this->assertDatabaseHas('rendez_vous', ['id' => $id]);
    }

    public function test_la_suppression_est_refusee_hors_fenetre_et_pour_un_autre_auteur(): void
    {
        $id = $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(11, 0)->toISOString(),
            'duree_minutes' => 20,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        ['jeton' => $autreJeton] = $this->employeAvecJeton($this->clinique);
        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $this->enTetes($autreJeton))->assertUnprocessable();

        // La fenêtre est de 20 s : 30 s plus tard, c'est refusé.
        $this->travel(30)->seconds();
        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertDatabaseHas('rendez_vous', ['id' => $id]);
    }

    public function test_l_historique_d_audit_expose_lots_champs_et_auteur(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'salle'], $entetes)->assertOk();
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/redimensionner", ['duree_minutes' => 40], $entetes)->assertOk();

        $reponse = $this->getJson("/api/v1/rendez-vous/{$rendezVous->id}/audits", $entetes)->assertOk();

        $this->assertCount(3, $reponse->json('data'));
        $this->assertSame(
            ['status', 'arrivedAt', 'endsAt'],
            array_column($reponse->json('data'), 'champ'),
        );
        $this->assertSame($this->user->name, $reponse->json('data.0.user.nom'));
        $this->assertSame(2, count(array_unique(array_column($reponse->json('data'), 'batch_id'))));
    }

    /** RDV prévu de 30 min demain 14:00 sur la ligne du test. */
    private function rendezVous(array $attributs = []): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(14, 0),
            'ends_at' => today()->addDay()->setTime(14, 30),
            ...$attributs,
        ]);
    }
}
