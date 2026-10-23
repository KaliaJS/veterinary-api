<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use App\Models\ReleveOphtalmologique;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Dossier médical sous le patient : pesées (ajout du jour, correction,
 * suppression tolérée), carnet de vaccination (injection du jour qui
 * écrase la ligne du même vaccin), visites, documents, relevés
 * ophtalmologiques (CRUD) et odontogramme Triadan (map remplacée).
 */
class DossierMedicalTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_pesee_du_jour_remplace_celle_du_meme_jour(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/patients/{$this->patient->id}/pesees";

        $this->postJson($uri, ['poids_kg' => 12.4], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.pesee_le', today()->toDateString());

        $this->postJson($uri, ['poids_kg' => 12.6], $entetes)->assertOk();

        $pesees = $this->patient->pesees()->get();
        $this->assertCount(1, $pesees);
        $this->assertSame(12.6, (float) $pesees->first()->poids_kg);
    }

    public function test_un_poids_hors_bornes_est_refuse(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/patients/{$this->patient->id}/pesees";

        $this->postJson($uri, ['poids_kg' => 0], $entetes)->assertUnprocessable();
        $this->postJson($uri, ['poids_kg' => 180], $entetes)->assertUnprocessable();
    }

    public function test_une_pesee_erronee_se_corrige_et_se_supprime(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $pesee = $this->patient->pesees()->create(['poids_kg' => 9.9, 'pesee_le' => today()->subMonth()]);

        $this->patchJson("/api/v1/patients/{$this->patient->id}/pesees/{$pesee->id}", ['poids_kg' => 10.1], $entetes)
            ->assertOk()
            ->assertJsonPath('data.poids_kg', 10.1);

        $this->deleteJson("/api/v1/patients/{$this->patient->id}/pesees/{$pesee->id}", [], $entetes)
            ->assertNoContent();

        $this->assertDatabaseMissing('pesees', ['id' => $pesee->id]);
    }

    public function test_l_injection_du_jour_s_ajoute_a_l_historique_du_meme_vaccin(): void
    {
        $entetes = $this->enTetes($this->jeton);
        Vaccination::factory()->for($this->patient)->create([
            'nom' => 'Rage',
            'marque' => 'Rabisin',
            'injectee_le' => today()->subYears(3),
            'echeance_le' => today()->subDays(10),
        ]);

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations", [
            'nom' => 'Rage',
            'marque' => 'Rabisin',
            'lot' => 'L204-B37',
            'echeance_le' => today()->addYears(3)->toDateString(),
        ], $entetes)->assertCreated();

        // Historique complet (16.10.2026) : la nouvelle injection s ajoute,
        // l ancienne reste ; l état par valence ne retient que la dernière.
        $vaccinations = $this->patient->vaccinations()->orderByDesc('injectee_le')->get();
        $this->assertCount(2, $vaccinations);
        $this->assertSame(today()->toDateString(), $vaccinations->first()->injectee_le->toDateString());
        $this->assertSame('L204-B37', $vaccinations->first()->lot);
        $this->assertSame($this->employe->id, $vaccinations->first()->employe_id);

        $etat = $this->getJson("/api/v1/patients/{$this->patient->id}/vaccinations", $entetes)->assertOk()->json('etat');
        $this->assertCount(1, $etat);
        $this->assertSame('ok', $etat[0]['statut']);
        $this->assertSame(2, $etat[0]['injections']);
    }

    public function test_un_nouveau_vaccin_ajoute_une_ligne_au_carnet(): void
    {
        Vaccination::factory()->for($this->patient)->create(['nom' => 'Rage', 'marque' => 'Rabisin']);

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations", [
            'nom' => 'Leptospirose',
            'marque' => 'Versican Plus L4',
            'echeance_le' => today()->addYear()->toDateString(),
        ], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame(2, $this->patient->vaccinations()->count());
    }

    public function test_le_signataire_d_une_visite_est_toujours_la_personne_connectee(): void
    {
        $cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $collegue = Employe::factory()->dans($cliniqueSoeur)->create();

        // On n'inscrit jamais une visite au nom d'un collègue (23.10.2026)
        $this->postJson("/api/v1/patients/{$this->patient->id}/visites", [
            'motif' => 'Consultation',
            'employe_id' => $collegue->id,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.employe_id', $this->employe->id);
    }

    public function test_une_visite_se_journalise_avec_le_signataire_du_jeton(): void
    {
        $this->postJson("/api/v1/patients/{$this->patient->id}/visites", [
            'motif' => 'Contrôle post-opératoire',
            'note' => 'Cicatrisation propre.',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.visite_le', today()->toDateString())
            ->assertJsonPath('data.employe_id', $this->employe->id);
    }

    public function test_un_document_s_archive_au_dossier(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/patients/{$this->patient->id}/documents", [
            'nom' => 'Certificat de bonne santé.pdf',
            'type' => 'certificat',
            'signataire' => 'Dr Meyer',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.etabli_le', today()->toDateString());

        $this->getJson("/api/v1/patients/{$this->patient->id}/documents", $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.nom', 'Certificat de bonne santé.pdf');
    }

    public function test_un_releve_ophtalmologique_vit_en_crud_complet(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/patients/{$this->patient->id}/releves-ophtalmologiques";

        $id = $this->postJson($uri, [
            'schirmer_od' => 18,
            'schirmer_og' => 17,
            'pio_od' => 14,
            'pio_og' => 15,
            'fluoresceine' => 'neg',
            'notes' => 'RAS.',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.releve_le', today()->toDateString())
            ->json('data.id');

        $this->patchJson("{$uri}/{$id}", ['fluoresceine' => 'pos', 'notes' => 'Ulcère cornéen superficiel.'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.fluoresceine', 'pos');

        $this->deleteJson("{$uri}/{$id}", [], $entetes)->assertNoContent();

        $this->assertSame(0, ReleveOphtalmologique::where('patient_id', $this->patient->id)->count());
    }

    public function test_l_odontogramme_se_remplace_d_un_bloc(): void
    {
        $entetes = $this->enTetes($this->jeton);
        ReleveDentaire::factory()->for($this->patient)->create(['dent' => 104, 'etat' => 'tartre']);

        $this->putJson("/api/v1/patients/{$this->patient->id}/dents", [
            'dents' => ['108' => 'fracture', '204' => 'gingivite'],
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.dents.108', 'fracture')
            ->assertJsonPath('data.dents.204', 'gingivite');

        // La dent 104, absente de la map, redevient saine.
        $this->assertSame(
            [108, 204],
            $this->patient->relevesDentaires()->orderBy('dent')->pluck('dent')->all(),
        );

        $this->getJson("/api/v1/patients/{$this->patient->id}/dents", $entetes)
            ->assertOk()
            ->assertJsonPath('data.dents.108', 'fracture');
    }

    public function test_un_numero_de_dent_ou_un_etat_invalide_est_refuse(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/patients/{$this->patient->id}/dents";

        $this->putJson($uri, ['dents' => ['999' => 'tartre']], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dents');

        $this->putJson($uri, ['dents' => ['104' => 'carie']], $entetes)
            ->assertUnprocessable();
    }
}
