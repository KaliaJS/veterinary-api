<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les congés année par année (Jim, 11.11.2026, cases É11 et É12) : les
 * jours ouvrés (du lundi au vendredi) des congés validés, le droit au
 * prorata l'année d'entrée, et le reste de chaque année reporté sur la
 * suivante, toujours (CO 329d al. 2 et 341 : un congé non pris ne se
 * perd pas et ne se paie pas pendant le contrat).
 */
class CongesParAnneeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        // Un mercredi
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        // Lausanne : les jours fériés vaudois (É23)
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'npa' => '1003']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function conge(Employe $employe, string $debut, string $fin, StatutAbsence $statut = StatutAbsence::Validee): void
    {
        Absence::factory()->create(['employe_id' => $employe->id, 'type' => TypeAbsence::Conge, 'statut' => $statut, 'debut_le' => $debut, 'fin_le' => $fin]);
    }

    public function test_les_jours_comptent_du_lundi_au_vendredi(): void
    {
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'created_at' => '2026-01-05', 'conges_droit' => 25]);
        // Du lundi au vendredi en mars : 5 jours pris
        $this->conge($employe, '2026-03-02', '2026-03-06');
        // Du vendredi au lundi : 2 jours à venir, le week-end ne compte pas
        $this->conge($employe, '2026-11-20', '2026-11-23');
        // Une maladie et une demande en attente ne comptent pas
        Absence::factory()->create(['employe_id' => $employe->id, 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-04-06', 'fin_le' => '2026-04-07']);
        $this->conge($employe, '2026-12-07', '2026-12-11', StatutAbsence::EnAttente);

        $this->getJson("/api/v1/equipe/employes/{$employe->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.conges', ['annee' => 2026, 'droit' => 25, 'report' => 0, 'pris' => 5, 'planifies' => 2, 'solde' => 18]);
    }

    public function test_le_reste_de_chaque_annee_passe_sur_la_suivante(): void
    {
        // Entrée le 1er juillet 2025 : 184 jours sur 365, 12,5 jours de droit
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2025-07-01', 'created_at' => '2025-06-20', 'conges_droit' => 25]);
        $this->conge($employe, '2025-08-04', '2025-08-08');
        // À cheval sur deux années : 3 jours en 2025 ; en 2026, le 1er et le 2 janvier sont fériés dans le canton de Vaud
        $this->conge($employe, '2025-12-29', '2026-01-02');

        $annees = $this->getJson("/api/v1/equipe/employes/{$employe->id}/conges", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([
            ['annee' => 2026, 'droit' => 25, 'report' => 4.5, 'pris' => 0, 'planifies' => 0, 'solde' => 29.5],
            ['annee' => 2025, 'droit' => 12.5, 'report' => 0, 'pris' => 8, 'planifies' => 0, 'solde' => 4.5],
        ], $annees);
    }

    public function test_l_annee_de_la_fin_du_contrat_compte_jusqu_au_dernier_jour(): void
    {
        // Fin le 30 juin 2026 : 181 jours sur 365, 12,5 jours de droit ; 3 jours pris en mars
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'created_at' => '2026-01-05', 'conges_droit' => 25, 'fin_contrat_le' => '2026-06-30', 'licenciement_notifie_le' => '2026-03-20']);
        $this->conge($employe, '2026-03-02', '2026-03-04');

        $annees = $this->getJson("/api/v1/equipe/employes/{$employe->id}/conges", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([['annee' => 2026, 'droit' => 12.5, 'report' => 0, 'pris' => 3, 'planifies' => 0, 'solde' => 9.5]], $annees);
    }

    public function test_une_fin_de_contrat_l_an_prochain_compte_aussi_cette_annee_la(): void
    {
        // Fin le 31 janvier 2027 : 2026 entière (25 j, 5 pris), puis 31 jours sur 365 de 2027 (2 j)
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'created_at' => '2026-01-05', 'conges_droit' => 25, 'fin_contrat_le' => '2027-01-31', 'licenciement_notifie_le' => '2026-11-02']);
        $this->conge($employe, '2026-03-02', '2026-03-06');

        $annees = $this->getJson("/api/v1/equipe/employes/{$employe->id}/conges", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([
            ['annee' => 2027, 'droit' => 2, 'report' => 20, 'pris' => 0, 'planifies' => 0, 'solde' => 22],
            ['annee' => 2026, 'droit' => 25, 'report' => 0, 'pris' => 5, 'planifies' => 0, 'solde' => 20],
        ], $annees);
    }

    public function test_le_report_a_l_arrivee_s_ajoute_a_la_premiere_annee(): void
    {
        // É22 : Julie arrive dans Veterinary en 2026 avec 6 jours de 2025, posés une fois avec le droit RH
        ['jeton' => $rh] = $this->administrateurAvecJeton($this->clinique);
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2019-03-01', 'created_at' => '2026-11-01', 'conges_droit' => 25]);
        $this->conge($employe, '2026-03-02', '2026-03-20');

        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_report_arrivee' => 6], $this->enTetes($rh))
            ->assertOk()
            ->assertJsonPath('data.conges_report_arrivee', 6)
            ->assertJsonPath('data.conges', ['annee' => 2026, 'droit' => 25, 'report' => 6, 'pris' => 15, 'planifies' => 0, 'solde' => 16]);

        // Une demi-journée se pose ; au-delà de 60 jours, non
        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_report_arrivee' => 6.5], $this->enTetes($rh))->assertOk()->assertJsonPath('data.conges.solde', 16.5);
        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_report_arrivee' => 6.3], $this->enTetes($rh))->assertUnprocessable()->assertJsonValidationErrors('conges_report_arrivee');
        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_report_arrivee' => 61], $this->enTetes($rh))->assertUnprocessable()->assertJsonValidationErrors('conges_report_arrivee');
    }

    public function test_le_report_a_l_arrivee_demande_le_droit_rh(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => DomainePermission::Rh, 'est_accordee' => false]);
        $employe = Employe::factory()->dans($this->clinique)->create();

        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_report_arrivee' => 6], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_les_jours_pris_ne_se_saisissent_plus_a_la_main(): void
    {
        $this->assertFalse(Schema::hasColumn('employes', 'conges_pris'));
        ['jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        $employe = Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'conges_droit' => 25]);

        $this->patchJson("/api/v1/equipe/employes/{$employe->id}", ['conges_pris' => 10], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.conges.pris', 0)
            ->assertJsonPath('data.conges.solde', 25);
    }

    public function test_les_annees_d_un_collegue_demandent_le_droit_equipe(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);
        $collegue = Employe::factory()->dans($this->clinique)->create();

        $this->getJson("/api/v1/equipe/employes/{$collegue->id}/conges", $this->enTetes($jeton))->assertForbidden();
    }
}
