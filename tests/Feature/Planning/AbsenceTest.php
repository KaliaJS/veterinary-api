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
            'debut_le' => aujourdhui()->addDay()->toDateString(),
            'fin_le' => aujourdhui()->addDays(3)->toDateString(),
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
            'starts_at' => aujourdhui()->addDay()->setTime(15, 0),
            'ends_at' => aujourdhui()->addDay()->setTime(15, 30),
        ]);
        $autreLigne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rendezVousAilleurs = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $autreLigne->id,
            'starts_at' => aujourdhui()->addDay()->setTime(10, 0),
            'ends_at' => aujourdhui()->addDay()->setTime(10, 30),
        ]);

        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => aujourdhui()->addDay(),
            'fin_le' => aujourdhui()->addDays(2),
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

        $this->postJson("/api/v1/absences/{$validee->id}/valider", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seule une demande en attente se valide.']);
        $this->postJson("/api/v1/absences/{$validee->id}/refuser", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seule une demande en attente se refuse.']);
    }

    public function test_le_refus_marque_la_demande_sans_toucher_au_planning(): void
    {
        $rendezVous = $this->rendezVousDemain();
        $absence = Absence::factory()->enAttente()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => aujourdhui()->addDay(),
            'fin_le' => aujourdhui()->addDays(2),
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
            'debut_le' => aujourdhui()->addDay()->toDateString(),
            'fin_le' => aujourdhui()->addDay()->toDateString(),
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
            'debut_le' => aujourdhui()->addDay()->toDateString(),
            'fin_le' => aujourdhui()->addDay()->toDateString(),
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
            'debut_le' => aujourdhui()->addDay(),
            'fin_le' => aujourdhui()->addDay(),
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
            'debut_le' => aujourdhui()->addDay(),
            'fin_le' => aujourdhui()->addDays(2),
        ]);

        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/absences/{$absence->id}", [
            'fin_le' => aujourdhui()->addDays(5)->toDateString(),
            'note' => 'Prolongée',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.fin_le', aujourdhui()->addDays(5)->toDateString());

        $this->patchJson("/api/v1/absences/{$absence->id}", [
            'fin_le' => aujourdhui()->toDateString(),
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fin_le');
    }

    /** Une absence validée avancée à demain annule les rendez-vous de demain, et le dit (24.10.2026). */
    public function test_une_absence_validee_modifiee_vide_les_nouveaux_jours(): void
    {
        $rendezVous = $this->rendezVousDemain();
        $absence = Absence::factory()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => aujourdhui()->addDays(5),
            'fin_le' => aujourdhui()->addDays(6),
        ]);
        $this->assertSame('ok', $absence->statut->value);

        $this->patchJson("/api/v1/absences/{$absence->id}", ['debut_le' => aujourdhui()->addDay()->toDateString()], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.rendez_vous_annules', 1)
            ->assertJsonPath('meta.rendez_vous_restants', 0);
        $this->assertSame('annule', $rendezVous->refresh()->status->value);
    }

    /** Une note seule ne relance rien ; une période modifiée n'annule jamais un rendez-vous fini, ni un rendez-vous passé (24.10.2026). */
    public function test_modifier_une_absence_n_annule_que_les_rendez_vous_prevus_a_venir(): void
    {
        $absence = Absence::factory()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => aujourdhui()->addDays(2),
            'fin_le' => aujourdhui()->addDays(4),
        ]);
        // Un rendez-vous mis sur la colonne pendant l'absence, puis terminé
        $fini = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id, 'status' => 'fini',
            'starts_at' => aujourdhui()->addDays(3)->setTime(9, 0), 'ends_at' => aujourdhui()->addDays(3)->setTime(9, 30),
        ]);
        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/absences/{$absence->id}", ['note' => 'Certificat reçu'], $entetes)
            ->assertOk()->assertJsonPath('meta.rendez_vous_annules', 0);
        $this->assertSame('fini', $fini->refresh()->status->value);

        // Avancée à hier : le rendez-vous d'hier, passé, et le fini restent
        $hier = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->subDay()->setTime(9, 0), 'ends_at' => aujourdhui()->subDay()->setTime(9, 30),
        ]);
        $demain = $this->rendezVousDemain();
        $this->patchJson("/api/v1/absences/{$absence->id}", ['debut_le' => aujourdhui()->subDay()->toDateString()], $entetes)
            ->assertOk()->assertJsonPath('meta.rendez_vous_annules', 1);
        $this->assertSame('annule', $demain->refresh()->status->value);
        $this->assertSame('prevu', $hier->refresh()->status->value);
        $this->assertSame('fini', $fini->refresh()->status->value);
    }

    /** Un rendez-vous ne se déplace pas sur la colonne d'un praticien absent, comme il ne s'y crée pas (24.10.2026). */
    public function test_un_rendez_vous_ne_se_deplace_pas_sur_une_absence_validee(): void
    {
        Absence::factory()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => aujourdhui()->addDays(2),
            'fin_le' => aujourdhui()->addDays(4),
        ]);
        $autreLigne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $autreLigne->id,
            'starts_at' => aujourdhui()->addDays(3)->setTime(9, 0), 'ends_at' => aujourdhui()->addDays(3)->setTime(9, 30),
        ]);

        $this->patchJson("/api/v1/rendez-vous/{$rendezVous->id}", ['ligne_planning_id' => $this->ligne->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['ligne_planning_id' => 'Colonne bloquée par une absence validée du praticien.']);

        $surLaLigne = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDays(6)->setTime(9, 0), 'ends_at' => aujourdhui()->addDays(6)->setTime(9, 30),
        ]);
        $this->patchJson("/api/v1/rendez-vous/{$surLaLigne->id}", ['starts_at' => aujourdhui()->addDays(3)->setTime(10, 0)->toIso8601String()], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('ligne_planning_id');
        // Une note sur un rendez-vous déjà là ne déclenche rien
        $this->patchJson("/api/v1/rendez-vous/{$surLaLigne->id}", ['reason' => 'Contrôle'], $this->enTetes($this->jeton))->assertOk();
    }

    /** Une note seule ne relance rien, même un rendez-vous prévu mis depuis ; un type changé relance ; en cours, en salle ou noté absent ne s'annule jamais (24.10.2026). */
    public function test_seuls_la_periode_et_le_type_relancent_et_seulement_les_prevus(): void
    {
        $absence = Absence::factory()->create([
            'employe_id' => $this->ligne->employe_id,
            'type' => 'conge',
            'debut_le' => aujourdhui()->addDays(2),
            'fin_le' => aujourdhui()->addDays(4),
        ]);
        $creer = fn (string $statut, int $heure) => RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id, 'status' => $statut,
            'starts_at' => aujourdhui()->addDays(3)->setTime($heure, 0), 'ends_at' => aujourdhui()->addDays(3)->setTime($heure, 30),
        ]);
        $prevu = $creer('prevu', 8);
        $enCours = $creer('cours', 9);
        $enSalle = $creer('salle', 10);
        $absent = $creer('noshow', 11);
        $entetes = $this->enTetes($this->jeton);

        $this->patchJson("/api/v1/absences/{$absence->id}", ['note' => 'Certificat reçu'], $entetes)->assertOk()->assertJsonPath('meta.rendez_vous_annules', 0);
        $this->assertSame('prevu', $prevu->refresh()->status->value);

        $this->patchJson("/api/v1/absences/{$absence->id}", ['type' => 'maladie'], $entetes)->assertOk()->assertJsonPath('meta.rendez_vous_annules', 1);
        $this->assertSame('annule', $prevu->refresh()->status->value);
        $this->assertSame('cours', $enCours->refresh()->status->value);
        $this->assertSame('salle', $enSalle->refresh()->status->value);
        $this->assertSame('noshow', $absent->refresh()->status->value);
    }

    /** Glissé sur la grille vers la colonne d'un praticien absent : refusé ; changer le motif d'un rendez-vous de cette colonne reste possible (24.10.2026). */
    public function test_le_glisser_de_la_grille_respecte_les_absences(): void
    {
        $autreLigne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $autreLigne->id,
            'starts_at' => aujourdhui()->addDays(3)->setTime(9, 0), 'ends_at' => aujourdhui()->addDays(3)->setTime(9, 30),
        ]);
        $dejaLa = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDays(3)->setTime(11, 0), 'ends_at' => aujourdhui()->addDays(3)->setTime(11, 30),
        ]);
        Absence::factory()->create(['employe_id' => $this->ligne->employe_id, 'debut_le' => aujourdhui()->addDays(2), 'fin_le' => aujourdhui()->addDays(4)]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/deplacer", ['ligne_planning_id' => $this->ligne->id, 'starts_at' => aujourdhui()->addDays(3)->setTime(9, 0)->toIso8601String()], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['ligne_planning_id' => 'Colonne bloquée par une absence validée du praticien.']);
        $this->assertSame($autreLigne->id, $rendezVous->refresh()->ligne_planning_id);

        $this->patchJson("/api/v1/rendez-vous/{$dejaLa->id}", ['reason' => 'Contrôle'], $entetes)->assertOk();
    }

    /** Prolongée (fin repoussée), une absence validée annule les rendez-vous prévus des nouveaux jours (24.10.2026). */
    public function test_une_absence_prolongee_vide_les_nouveaux_jours(): void
    {
        $absence = Absence::factory()->create(['employe_id' => $this->ligne->employe_id, 'debut_le' => aujourdhui()->addDays(2), 'fin_le' => aujourdhui()->addDays(3)]);
        $apres = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDays(5)->setTime(9, 0), 'ends_at' => aujourdhui()->addDays(5)->setTime(9, 30),
        ]);

        $this->patchJson("/api/v1/absences/{$absence->id}", ['fin_le' => aujourdhui()->addDays(6)->toDateString()], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('meta.rendez_vous_annules', 1);
        $this->assertSame('annule', $apres->refresh()->status->value);
    }

    /** Un praticien sur deux colonnes : ses rendez-vous sont annulés dans l'ordre de leur heure, toutes colonnes confondues (24.10.2026). */
    public function test_une_absence_traite_les_rendez_vous_de_toutes_les_colonnes_dans_l_ordre(): void
    {
        $seconde = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $this->ligne->employe_id]);
        $tard = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => aujourdhui()->addDays(2)->setTime(11, 0), 'ends_at' => aujourdhui()->addDays(2)->setTime(11, 30),
        ]);
        $tot = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $seconde->id,
            'starts_at' => aujourdhui()->addDays(2)->setTime(10, 0), 'ends_at' => aujourdhui()->addDays(2)->setTime(10, 30),
        ]);
        $ordre = [];
        RendezVous::saving(function (RendezVous $rendezVous) use (&$ordre): void {
            if ($rendezVous->isDirty('status') && $rendezVous->status === StatutRendezVous::Annule) {
                $ordre[] = $rendezVous->id;
            }
        });

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id, 'type' => 'maladie', 'statut' => 'ok',
            'debut_le' => aujourdhui()->addDays(2)->toDateString(), 'fin_le' => aujourdhui()->addDays(2)->toDateString(),
        ], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('meta.rendez_vous_annules', 2);

        $this->assertSame([$tot->id, $tard->id], $ordre);
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
            'starts_at' => aujourdhui()->addDay()->setTime(9, 0),
            'ends_at' => aujourdhui()->addDay()->setTime(9, 30),
        ]);
    }
}
