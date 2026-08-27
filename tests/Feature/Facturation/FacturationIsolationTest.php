<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tranches de scope du domaine : factures et devis suivent le patient —
 * NIVEAU ORGANISATION, lisibles de toutes les cliniques du jeton,
 * invisibles d'une organisation étrangère ; les clôtures de caisse sont
 * le tiroir d'UNE clinique.
 */
class FacturationIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_une_facture_d_une_autre_organisation_est_invisible(): void
    {
        $etrangere = Facture::factory()->create(['clinique_id' => $this->cliniqueEtrangere->id]);
        Facture::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->getJson('/api/v1/facturation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/facturation/factures/{$etrangere->id}", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_une_facture_d_une_clinique_soeur_se_lit_mais_ne_s_encaisse_pas_ici(): void
    {
        $soeur = Facture::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);

        $this->getJson("/api/v1/facturation/factures/{$soeur->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $soeur->id);

        $this->postJson("/api/v1/facturation/factures/{$soeur->id}/encaisser", [
            'moyen_paiement' => 'tpe',
        ], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_les_clotures_d_une_clinique_soeur_sont_invisibles(): void
    {
        ClotureCaisse::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);
        $notre = ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->getJson('/api/v1/facturation/clotures-caisse', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notre->id);
    }

    public function test_un_devis_d_une_autre_organisation_est_invisible(): void
    {
        $patientEtranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();
        $etranger = Devis::factory()->create([
            'organisation_id' => $this->cliniqueEtrangere->societe->organisation_id,
            'patient_id' => $patientEtranger->id,
        ]);

        $this->getJson('/api/v1/facturation/devis', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/facturation/devis/{$etranger->id}", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_un_devis_ne_reference_pas_un_patient_d_une_autre_organisation(): void
    {
        $patientEtranger = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->cliniqueEtrangere->societe->organisation_id]))
            ->create();

        $this->postJson('/api/v1/facturation/devis', [
            'patient_id' => $patientEtranger->id,
            'lignes' => [['libelle' => 'Refusé', 'prix_chf' => 85, 'type' => 'acte']],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_une_facture_creee_est_rattachee_a_l_organisation_et_a_la_clinique_du_jeton(): void
    {
        $reponse = $this->postJson('/api/v1/facturation/factures', [
            'patient_name' => 'Rex',
            'lignes' => [['libelle' => 'Forfait hospitalisation — 2 jours', 'prix_chf' => 130, 'type' => 'acte']],
        ], $this->enTetes($this->jeton))->assertCreated();

        $facture = Facture::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($this->clinique->societe->organisation_id, $facture->organisation_id);
        $this->assertSame($this->clinique->id, $facture->clinique_id);
        $this->assertStringStartsWith('FC-', $facture->numero);
    }
}
