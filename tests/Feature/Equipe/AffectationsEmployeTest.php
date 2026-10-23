<?php

namespace Tests\Feature\Equipe;

use App\Enums\StatutRendezVous;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Une fiche par personne, des cliniques choisies par l'administrateur :
 * ajoutées et retirées sur la fiche, jamais automatiquement. On ne voit
 * et n'agit que dans les cliniques où l'on est affecté ; une personne de
 * plusieurs cliniques a un seul solde de congés, et son absence vide ses
 * colonnes partout.
 */
class AffectationsEmployeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_une_clinique_s_ajoute_puis_se_retire_et_l_acces_suit(): void
    {
        ['employe' => $collegue, 'jeton' => $jetonCollegue] = $this->employeAvecJeton($this->clinique);
        $entetes = $this->enTetes($this->jeton);

        // Rien d'automatique : la clinique sœur lui est fermée
        $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->soeur->id], $this->enTetes($jetonCollegue))
            ->assertUnprocessable();

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", [
            'cliniques' => [$this->clinique->id, $this->soeur->id],
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.cliniques', [$this->clinique->id, $this->soeur->id]);

        // Même fiche dans les deux cliniques
        $jetonSoeur = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->soeur->id], $this->enTetes($jetonCollegue))
            ->assertOk()
            ->assertJsonPath('data.employe.id', $collegue->id)
            ->assertJsonCount(2, 'data.cliniques')
            ->json('data.token');

        $this->assertContains($collegue->id, collect($this->getJson('/api/v1/equipe/employes', $this->enTetes($jetonSoeur))->json('data'))->pluck('id'));

        // Retirée : la clinique sœur se ferme dès la requête suivante
        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", [
            'cliniques' => [$this->clinique->id],
        ], $entetes)->assertOk();

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonSoeur))->assertForbidden();
    }

    public function test_toutes_les_cliniques_d_un_coup(): void
    {
        $collegue = Employe::factory()->dans($this->clinique)->create();

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", [
            'toutes_les_cliniques' => true,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.cliniques', [$this->clinique->id, $this->soeur->id]);
    }

    public function test_retirer_une_clinique_attend_que_ses_rendez_vous_a_venir_soient_deplaces(): void
    {
        $collegue = Employe::factory()->dans($this->clinique, $this->soeur)->create();
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->soeur->id, 'employe_id' => $collegue->id]);
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->soeur->id,
            'ligne_planning_id' => $ligne->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(9, 30),
        ]);

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", [
            'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');

        $this->assertTrue($collegue->cliniques()->whereKey($this->soeur->id)->exists());

        $rendezVous->update(['status' => StatutRendezVous::Annule]);

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", [
            'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_au_moins_une_clinique_et_jamais_la_sienne_pendant_qu_on_y_est_connecte(): void
    {
        $collegue = Employe::factory()->dans($this->clinique)->create();
        $moi = $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->json('data.employe.id');

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", ['cliniques' => []], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');

        $this->putJson("/api/v1/equipe/employes/{$moi}/cliniques", ['cliniques' => [$this->soeur->id]], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');
    }

    public function test_une_personne_de_deux_cliniques_a_un_seul_solde_et_son_absence_vide_ses_deux_colonnes(): void
    {
        $collegue = Employe::factory()->dans($this->clinique, $this->soeur)->create(['conges_droit' => 25, 'conges_pris' => 5]);
        $demain = CarbonImmutable::tomorrow();
        $rendezVous = collect([$this->clinique, $this->soeur])->map(function (Clinique $clinique) use ($collegue, $demain): RendezVous {
            $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id, 'employe_id' => $collegue->id]);

            return RendezVous::factory()->create([
                'clinique_id' => $clinique->id,
                'ligne_planning_id' => $ligne->id,
                'starts_at' => $demain->setTime(10, 0),
                'ends_at' => $demain->setTime(10, 30),
            ]);
        });

        $absence = $this->postJson('/api/v1/absences', [
            'employe_id' => $collegue->id,
            'type' => 'conge',
            'debut_le' => $demain->toDateString(),
            'fin_le' => $demain->toDateString(),
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/absences/{$absence}/valider", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.rendez_vous_annules', 2);

        $rendezVous->each(fn (RendezVous $unRendezVous) => $this->assertSame(StatutRendezVous::Annule, $unRendezVous->refresh()->status));

        $this->getJson("/api/v1/equipe/employes/{$collegue->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.conges', ['droit' => 25, 'pris' => 5, 'planifies' => 1, 'solde' => 19]);
    }

    public function test_la_colonne_d_un_employe_retire_de_la_clinique_ne_s_affiche_plus_et_n_offre_plus_de_creneaux(): void
    {
        $collegue = Employe::factory()->dans($this->clinique, $this->soeur)->create();
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $collegue->id]);
        $lundi = CarbonImmutable::parse('next monday');
        $entetes = $this->enTetes($this->jeton);

        $this->assertContains($ligne->id, collect($this->getJson('/api/v1/lignes-planning', $entetes)->json('data'))->pluck('id'));
        $this->assertContains($ligne->id, collect($this->getJson('/api/v1/planning/disponibilites?date='.$lundi->toDateString(), $entetes)->json('data.creneaux'))->pluck('ligne_planning_id'));

        $this->putJson("/api/v1/equipe/employes/{$collegue->id}/cliniques", ['cliniques' => [$this->soeur->id]], $entetes)->assertOk();

        $this->assertNotContains($ligne->id, collect($this->getJson('/api/v1/lignes-planning', $entetes)->json('data'))->pluck('id'));
        $this->assertNotContains($ligne->id, collect($this->getJson('/api/v1/planning/disponibilites?date='.$lundi->toDateString(), $entetes)->json('data.creneaux'))->pluck('ligne_planning_id'));
    }
}
