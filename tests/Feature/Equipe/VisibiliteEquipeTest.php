<?php

namespace Tests\Feature\Equipe;

use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Ce que chaque droit montre de l'équipe (audit C29, décision de Jim du
 * 14.11.2026, nLPD : une maladie est une donnée de santé) :
 * - « Voir l'équipe » (tous les rôles) : nom, rôle, présence, « absent »
 *   sans la raison, téléphone et e-mail ;
 * - « Gérer les absences » : en plus la raison et la note des absences, les
 *   demandes des autres et les soldes de congés ;
 * - « Gérer l'équipe » : en plus le contrat, le taux, le salaire et le
 *   licenciement.
 * Sa propre fiche se lit en entier, sauf le salaire et le motif d'un
 * licenciement, réservés à « Gérer l'équipe ».
 */
class VisibiliteEquipeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const array CHAMPS_DU_CONTRAT = ['taux', 'contrat', 'contrat_statut', 'entree_le', 'salaire_mensuel', 'licenciement'];

    private const array CHAMPS_DES_CONGES = ['conges', 'conges_report_arrivee'];

    private Clinique $clinique;

    private Employe $collegue;

    private Absence $maladie;

    private Absence $demande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        $this->collegue = Employe::factory()->dans($this->clinique)->create([
            'nom' => 'Lena Weber',
            'tel' => '+41 79 123 45 67',
            'email' => 'lena@exemple.ch',
            'salaire_mensuel' => 6200,
            'licenciement_notifie_le' => '2026-10-30',
            'fin_contrat_le' => '2026-12-31',
            'licenciement_motif' => 'Réorganisation',
        ]);
        $this->maladie = Absence::factory()->create(['employe_id' => $this->collegue->id, 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-16', 'fin_le' => '2026-11-18', 'note' => 'Burn-out, arrêt médical', 'valide_par_user_id' => User::factory()->create()->id]);
        $this->demande = Absence::factory()->create(['employe_id' => $this->collegue->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::EnAttente, 'debut_le' => '2026-11-23', 'fin_le' => '2026-11-27', 'note' => 'Voyage']);
    }

    public function test_voir_l_equipe_montre_l_absence_d_un_collegue_sans_sa_raison_ni_ses_demandes(): void
    {
        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $planning = $this->getJson('/api/v1/equipe/planning?du=2026-11-09&au=2026-12-06', $this->enTetes($jeton))->assertOk()->json('data');
            $this->assertFalse($planning['peut_gerer']);
            $this->assertSame([$this->maladie->id], array_column($planning['absences'], 'id'), $role ?? 'veterinaire');
            $this->assertNull($planning['absences'][0]['type']);
            $this->assertNull($planning['absences'][0]['note']);

            $liste = $this->getJson('/api/v1/absences', $this->enTetes($jeton))->assertOk()->json('data');
            $this->assertSame([$this->maladie->id], array_column($liste, 'id'));
            $this->assertNull($liste[0]['type']);
            $this->assertNull($liste[0]['note']);
            $this->assertNull($liste[0]['valide_par_user_id']);

            $this->getJson("/api/v1/absences/{$this->maladie->id}", $this->enTetes($jeton))->assertOk()
                ->assertJsonPath('data.type', null)
                ->assertJsonPath('data.note', null)
                ->assertJsonPath('data.valide_par_user_id', null);
            $this->getJson("/api/v1/absences/{$this->demande->id}", $this->enTetes($jeton))->assertForbidden();
        }
    }

    public function test_gerer_les_absences_montre_la_raison_la_note_et_les_demandes(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');

        $planning = $this->getJson('/api/v1/equipe/planning?du=2026-11-09&au=2026-12-06', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertTrue($planning['peut_gerer']);
        $absences = collect($planning['absences'])->keyBy('id');
        $this->assertSame('maladie', $absences[$this->maladie->id]['type']);
        $this->assertSame('Burn-out, arrêt médical', $absences[$this->maladie->id]['note']);
        $this->assertSame('att', $absences[$this->demande->id]['statut']);

        $this->getJson("/api/v1/absences/{$this->demande->id}", $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.type', 'conge')
            ->assertJsonPath('data.note', 'Voyage');
    }

    public function test_voir_l_equipe_montre_les_coordonnees_sans_le_contrat_ni_les_conges(): void
    {
        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $fiche = $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}", $this->enTetes($jeton))->assertOk()->json('data');
            $this->assertSame(['Lena Weber', '+41 79 123 45 67', 'lena@exemple.ch'], [$fiche['nom'], $fiche['tel'], $fiche['email']]);
            $this->assertArrayHasKey('role_libelle', $fiche);
            foreach ([...self::CHAMPS_DU_CONTRAT, ...self::CHAMPS_DES_CONGES] as $champ) {
                $this->assertArrayNotHasKey($champ, $fiche, $champ);
            }

            $liste = collect($this->getJson('/api/v1/equipe/employes', $this->enTetes($jeton))->assertOk()->json('data'))->keyBy('id');
            foreach ([...self::CHAMPS_DU_CONTRAT, ...self::CHAMPS_DES_CONGES] as $champ) {
                $this->assertArrayNotHasKey($champ, $liste[$this->collegue->id], $champ);
            }

            $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}/conges", $this->enTetes($jeton))->assertForbidden();
        }
    }

    public function test_gerer_les_absences_montre_les_conges_sans_le_contrat(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');

        $fiche = $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}", $this->enTetes($jeton))->assertOk()->json('data');
        foreach (self::CHAMPS_DES_CONGES as $champ) {
            $this->assertArrayHasKey($champ, $fiche, $champ);
        }
        foreach (self::CHAMPS_DU_CONTRAT as $champ) {
            $this->assertArrayNotHasKey($champ, $fiche, $champ);
        }
        $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}/conges", $this->enTetes($jeton))->assertOk();
    }

    public function test_gerer_l_equipe_montre_le_contrat_et_le_licenciement(): void
    {
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);

        $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}", $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.contrat', 'CDI')
            ->assertJsonPath('data.salaire_mensuel', 6200)
            ->assertJsonPath('data.licenciement.fin_contrat_le', '2026-12-31')
            ->assertJsonPath('data.licenciement.motif', 'Réorganisation')
            ->assertJsonStructure(['data' => ['taux', 'entree_le', 'conges', 'conges_report_arrivee']]);
    }

    public function test_sa_propre_fiche_se_lit_en_entier_sauf_le_salaire_et_le_motif(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [
            'salaire_mensuel' => 5100,
            'licenciement_notifie_le' => '2026-10-30',
            'fin_contrat_le' => '2026-12-31',
            'licenciement_motif' => 'Réorganisation',
        ], 'assistant');

        $fiche = $this->getJson("/api/v1/equipe/employes/{$moi->id}", $this->enTetes($jeton))->assertOk()->json('data');
        foreach (['taux', 'contrat', 'contrat_statut', 'entree_le', ...self::CHAMPS_DES_CONGES] as $champ) {
            $this->assertArrayHasKey($champ, $fiche, $champ);
        }
        $this->assertArrayNotHasKey('salaire_mensuel', $fiche);
        $this->assertSame('2026-12-31', $fiche['licenciement']['fin_contrat_le']);
        $this->assertNull($fiche['licenciement']['motif']);
        $this->getJson("/api/v1/equipe/employes/{$moi->id}/conges", $this->enTetes($jeton))->assertOk();
    }

    public function test_le_planning_dit_qui_a_valide_ses_propres_absences_pas_celles_des_autres(): void
    {
        $this->clinique->societe->organisation->forceFill(['conges_sans_validation' => true])->save();
        ['employe' => $moi, 'user' => $user, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');
        $this->maladie->forceFill(['valide_par_user_id' => $user->id])->save();
        $this->postJson('/api/v1/equipe/demandes-conge', ['debut_le' => '2026-11-30', 'fin_le' => '2026-12-01'], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'ok');

        $absences = collect($this->getJson('/api/v1/equipe/planning?du=2026-11-09&au=2026-12-06', $this->enTetes($jeton))->assertOk()->json('data.absences'));

        $this->assertSame($user->id, $absences->firstWhere('employe_id', $moi->id)['valide_par_user_id']);
        $this->assertNull($absences->firstWhere('id', $this->maladie->id)['valide_par_user_id']);
    }
}
