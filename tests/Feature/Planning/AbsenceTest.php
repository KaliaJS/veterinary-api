<?php

namespace Tests\Feature\Planning;

use App\Enums\StatutRendezVous;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cycle des absences : demande en attente → validation (la colonne du
 * praticien est vidée, rendez-vous annulés et audités avec motif) ou
 * refus ; saisie directe validée ; suppression tolérée qui rouvre la
 * colonne sans restaurer les rendez-vous ; la garde ne bloque rien.
 */
class AbsenceTest extends TestCase
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

    public function test_une_demande_se_cree_en_attente_sans_toucher_au_planning(): void
    {
        $rendezVous = $this->rendezVousDemain();

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'conge',
            'debut_le' => today()->addDay()->toDateString(),
            'fin_le' => today()->addDays(3)->toDateString(),
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'att')
            ->assertJsonPath('meta.rendez_vous_annules', 0);

        $this->assertSame(StatutRendezVous::Prevu, $rendezVous->refresh()->status);
    }

    public function test_la_validation_vide_la_colonne_du_praticien_avec_motif_audite(): void
    {
        $rendezVous = $this->rendezVousDemain();
        $tampon = RendezVous::factory()->tampon()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(15, 0),
            'ends_at' => today()->addDay()->setTime(15, 30),
        ]);
        $autreLigne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rendezVousAilleurs = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $autreLigne->id,
            'starts_at' => today()->addDay()->setTime(10, 0),
            'ends_at' => today()->addDay()->setTime(10, 30),
        ]);

        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => today()->addDay(),
            'fin_le' => today()->addDays(2),
        ]);

        $this->postJson("/api/v1/absences/{$absence->id}/valider", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'ok')
            ->assertJsonPath('data.valide_par_user_id', $this->user->id)
            ->assertJsonPath('meta.rendez_vous_annules', 1);

        $this->assertSame(StatutRendezVous::Annule, $rendezVous->refresh()->status);
        $this->assertSame(StatutRendezVous::Annule, $tampon->refresh()->status);
        $this->assertSame(StatutRendezVous::Prevu, $rendezVousAilleurs->refresh()->status);

        $audits = $rendezVous->audits()->get();
        $this->assertSame(1, $audits->pluck('batch_id')->unique()->count());
        $this->assertSame('Absence praticien : congés', $audits->firstWhere('champ', 'motifAnnulation')->nouvelle_valeur);
        $this->assertSame($this->user->id, $audits->first()->user_id);
    }

    public function test_seule_une_demande_en_attente_se_valide_ou_se_refuse(): void
    {
        $validee = Absence::factory()->create(['employe_id' => $this->ligne->employe_id]);

        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/absences/{$validee->id}/valider", [], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/absences/{$validee->id}/refuser", [], $entetes)->assertUnprocessable();
    }

    public function test_le_refus_marque_la_demande_sans_toucher_au_planning(): void
    {
        $rendezVous = $this->rendezVousDemain();
        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => today()->addDay(),
            'fin_le' => today()->addDays(2),
        ]);

        $this->postJson("/api/v1/absences/{$absence->id}/refuser", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'ref');

        $this->assertSame(StatutRendezVous::Prevu, $rendezVous->refresh()->status);
    }

    public function test_la_saisie_directe_validee_annule_aussi_les_rendez_vous(): void
    {
        $rendezVous = $this->rendezVousDemain();

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'maladie',
            'debut_le' => today()->addDay()->toDateString(),
            'fin_le' => today()->addDay()->toDateString(),
            'statut' => 'ok',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.valide_par_user_id', $this->user->id)
            ->assertJsonPath('meta.rendez_vous_annules', 1);

        $this->assertSame(StatutRendezVous::Annule, $rendezVous->refresh()->status);
    }

    public function test_une_garde_validee_ne_bloque_rien(): void
    {
        $rendezVous = $this->rendezVousDemain();

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'garde',
            'debut_le' => today()->addDay()->toDateString(),
            'fin_le' => today()->addDay()->toDateString(),
            'statut' => 'ok',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('meta.rendez_vous_annules', 0);

        $this->assertSame(StatutRendezVous::Prevu, $rendezVous->refresh()->status);
    }

    public function test_la_suppression_rouvre_la_colonne_sans_restaurer_les_rendez_vous(): void
    {
        $rendezVous = $this->rendezVousDemain();
        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => today()->addDay(),
            'fin_le' => today()->addDay(),
        ]);

        $entetes = $this->enTetes($this->jeton);
        $this->postJson("/api/v1/absences/{$absence->id}/valider", [], $entetes)->assertOk();

        $this->deleteJson("/api/v1/absences/{$absence->id}", [], $entetes)->assertNoContent();

        $this->assertDatabaseMissing('absences', ['id' => $absence->id]);
        $this->assertSame(StatutRendezVous::Annule, $rendezVous->refresh()->status);
    }

    public function test_la_periode_se_modifie_avec_ses_bornes_coherentes(): void
    {
        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => today()->addDay(),
            'fin_le' => today()->addDays(2),
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/absences/{$absence->id}", [
            'fin_le' => today()->addDays(5)->toDateString(),
            'note' => 'Prolongée',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.fin_le', today()->addDays(5)->toDateString());

        $this->patchJson("/api/v1/absences/{$absence->id}", [
            'fin_le' => today()->toDateString(),
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fin_le');
    }

    public function test_la_liste_se_filtre_par_employe_et_statut(): void
    {
        Absence::factory()->create(['employe_id' => $this->ligne->employe_id]);
        $enAttente = Absence::factory()->enAttente()->create(['employe_id' => $this->ligne->employe_id]);

        $this->getJson('/api/v1/absences?statut=att', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enAttente->id);
    }

    /** RDV prévu demain 09:00 sur la ligne du praticien. */
    private function rendezVousDemain(): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => today()->addDay()->setTime(9, 0),
            'ends_at' => today()->addDay()->setTime(9, 30),
        ]);
    }
}
