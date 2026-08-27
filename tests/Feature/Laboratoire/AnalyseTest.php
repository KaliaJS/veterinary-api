<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutAnalyse;
use App\Models\Analyse;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\PanelLabo;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Demandes d'analyses (docs/data-model.md §9) : numérotation A-YY-NNNN
 * servie par le Numeroteur, panels et bornes instantanés du
 * référentiel, cycle attente → cours → valid → ok un cran à la fois,
 * saisie des résultats avec drapeau H/L dérivé, validation signée qui
 * fige tout. Jamais de suppression.
 */
class AnalyseTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    /** @return array<string, string> */
    private function jeton(): array
    {
        return $this->enTetes($this->jeton);
    }

    private function analyse(array $attributs = []): Analyse
    {
        return Analyse::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            ...$attributs,
        ]);
    }

    public function test_le_referentiel_de_panels_nait_avec_l_organisation(): void
    {
        $this->getJson('/api/v1/laboratoire/panels', $this->jeton())
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.code', 'nfs')
            ->assertJsonPath('data.0.tube', 'EDTA')
            ->assertJsonCount(7, 'data.0.parametres')
            ->assertJsonPath('data.0.parametres.0.nom', 'Hématocrite');
    }

    public function test_la_demande_nait_en_attente_avec_panels_et_resultats_vides_instantanes(): void
    {
        $reponse = $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $this->patient->id,
            'panels' => ['nfs', 'bioch'],
            'priorite' => 'urg',
        ], $this->jeton())->assertCreated();

        $annee = now()->format('y');
        $reponse->assertJsonPath('data.numero', "A-{$annee}-0001")
            ->assertJsonPath('data.statut', 'attente')
            ->assertJsonPath('data.priorite', 'urg')
            ->assertJsonPath('data.prescripteur.id', $this->employe->id)
            ->assertJsonCount(2, 'data.panels')
            ->assertJsonPath('data.panels.0.tube', 'EDTA')
            // 7 paramètres NFS + 7 biochimie, valeurs vides.
            ->assertJsonCount(14, 'data.resultats')
            ->assertJsonPath('data.resultats.0.valeur', null)
            ->assertJsonPath('data.resultats.0.panel', 'nfs');

        // Le numéro suit la séquence annuelle de l'organisation.
        $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $this->patient->id,
            'panels' => ['urine'],
        ], $this->jeton())->assertCreated()->assertJsonPath('data.numero', "A-{$annee}-0002");
    }

    public function test_les_bornes_sont_un_instantane_du_referentiel(): void
    {
        $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $this->patient->id,
            'panels' => ['iono'],
        ], $this->jeton())->assertCreated();

        // Modifier le référentiel après coup ne réécrit pas la demande.
        PanelLabo::withoutGlobalScope('organisation')
            ->where('code', 'iono')
            ->sole()
            ->parametres()
            ->where('nom', 'Sodium')
            ->sole()
            ->update(['borne_haute' => 170]);

        $analyse = Analyse::withoutGlobalScopes()->sole();
        $this->assertSame('160.00', $analyse->resultats()->where('parametre', 'Sodium')->sole()->borne_haute);
    }

    public function test_un_panel_inconnu_du_referentiel_est_refuse(): void
    {
        $this->postJson('/api/v1/laboratoire/analyses', [
            'patient_id' => $this->patient->id,
            'panels' => ['nfs', 'inconnu'],
        ], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('panels.1');
    }

    public function test_le_cycle_avance_un_cran_a_la_fois(): void
    {
        $analyse = $this->analyse();

        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'cours');

        $this->assertSame(StatutAnalyse::Cours, $analyse->refresh()->statut);
    }

    public function test_la_saisie_attend_la_reception_et_derive_le_drapeau(): void
    {
        $analyse = $this->analyse();
        $resultats = $analyse->resultats()->orderBy('id')->get();

        // Pas encore réceptionnée : rien ne se saisit.
        $this->putJson("/api/v1/laboratoire/analyses/{$analyse->id}/resultats", [
            'resultats' => [['id' => $resultats[0]->id, 'valeur' => 5.0]],
        ], $this->jeton())->assertUnprocessable();

        $analyse->update(['statut' => StatutAnalyse::Cours]);

        // Urée (bornes 2.5–9.6) : haute, basse, dans les bornes.
        $this->putJson("/api/v1/laboratoire/analyses/{$analyse->id}/resultats", [
            'resultats' => [
                ['id' => $resultats[0]->id, 'valeur' => 14.2],
                ['id' => $resultats[1]->id, 'valeur' => 12],
                ['id' => $resultats[2]->id, 'valeur' => 60],
            ],
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.resultats.0.drapeau', 'H')
            ->assertJsonPath('data.resultats.1.drapeau', 'L')
            ->assertJsonPath('data.resultats.2.drapeau', null);
    }

    public function test_le_passage_a_valider_exige_la_saisie_complete(): void
    {
        $analyse = $this->analyse(['statut' => StatutAnalyse::Cours]);

        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->jeton())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resultats');

        $complete = Analyse::factory()->resultatsSaisis()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
        ]);

        $this->postJson("/api/v1/laboratoire/analyses/{$complete->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'valid');
    }

    public function test_la_validation_est_signee_et_fige_les_resultats(): void
    {
        $analyse = Analyse::factory()->resultatsSaisis()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'statut' => StatutAnalyse::AValider,
        ]);

        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.statut', 'ok')
            ->assertJsonPath('data.valide_par.id', $this->employe->id);

        $this->assertNotNull($analyse->refresh()->valide_le);

        // Plus un geste : ni statut, ni résultats.
        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->jeton())
            ->assertUnprocessable();
        $this->putJson("/api/v1/laboratoire/analyses/{$analyse->id}/resultats", [
            'resultats' => [['id' => $analyse->resultats()->first()->id, 'valeur' => 1]],
        ], $this->jeton())->assertUnprocessable();
    }

    public function test_seule_la_note_se_modifie(): void
    {
        $analyse = $this->analyse();

        $this->patchJson("/api/v1/laboratoire/analyses/{$analyse->id}", [
            'note' => 'Hémolyse légère signalée par l\'automate.',
        ], $this->jeton())
            ->assertOk()
            ->assertJsonPath('data.note', 'Hémolyse légère signalée par l\'automate.');

        $this->patchJson("/api/v1/laboratoire/analyses/{$analyse->id}", [
            'priorite' => 'urg',
        ], $this->jeton())->assertUnprocessable();
    }

    public function test_la_liste_filtre_par_statut_et_cherche_par_numero(): void
    {
        $this->analyse(['numero' => 'A-26-0201']);
        $this->analyse(['numero' => 'A-26-0202', 'statut' => StatutAnalyse::Valide]);

        $entetes = $this->jeton();

        $this->getJson('/api/v1/laboratoire/analyses?statut=attente', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', 'A-26-0201');

        $this->getJson('/api/v1/laboratoire/analyses?q=0202', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', 'A-26-0202');
    }

    public function test_aucune_route_de_suppression_d_analyse(): void
    {
        $analyse = $this->analyse();

        $this->deleteJson("/api/v1/laboratoire/analyses/{$analyse->id}", [], $this->jeton())
            ->assertStatus(405);
    }
}
