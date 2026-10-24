<?php

namespace Tests\Feature\Auth;

use App\Enums\StatutAnalyse;
use App\Models\Analyse;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Droits fins par défaut (23.10.2026) : l'ASV prépare, le vétérinaire
 * signe (ordonnance, analyse, compte rendu, stupéfiants), l'administrateur
 * clôture la caisse ; la fin de consultation, où l'on choisit ce qui est
 * facturé, exige la caisse. Chaque défaut se change par organisation.
 */
class DroitsFinsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();
    }

    public function test_l_asv_ne_signe_pas_d_ordonnance_le_veterinaire_si(): void
    {
        $medicament = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $ordonnance = ['patient_id' => $this->patient->id, 'lignes' => [['produit_id' => $medicament->id, 'posologie' => '1 cp']]];

        $this->postJson('/api/v1/ordonnances', $ordonnance, $this->enTetes($this->jeton('asv')))->assertForbidden();
        $this->postJson('/api/v1/ordonnances', $ordonnance, $this->enTetes($this->jeton()))->assertCreated();
    }

    public function test_l_asv_ne_valide_pas_une_analyse_le_veterinaire_si(): void
    {
        $analyse = Analyse::factory()->resultatsSaisis()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'statut' => StatutAnalyse::AValider,
        ]);

        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->enTetes($this->jeton('asv')))->assertForbidden();
        $this->postJson("/api/v1/laboratoire/analyses/{$analyse->id}/avancer", [], $this->enTetes($this->jeton()))
            ->assertOk()->assertJsonPath('data.statut', 'ok');
    }

    public function test_l_asv_ne_signe_pas_un_compte_rendu_d_imagerie(): void
    {
        ['employe' => $veterinaire, 'jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique);
        $examen = ExamenImagerie::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $veterinaire->id,
        ]);
        $compteRendu = ['constatations' => 'Fracture du radius', 'conclusion' => 'Plâtre', 'sans_facture' => true];

        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", $compteRendu, $this->enTetes($this->jeton('asv')))->assertForbidden();
        $this->postJson("/api/v1/imagerie/examens/{$examen->id}/compte-rendu", $compteRendu, $this->enTetes($jetonVeterinaire))->assertOk();
    }

    public function test_l_asv_n_inscrit_rien_au_registre_des_stupefiants(): void
    {
        $substance = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_stupefiant' => true]);

        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $substance->id, 'quantite' => 10], $this->enTetes($this->jeton('asv')))
            ->assertForbidden();
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $substance->id, 'quantite' => 10], $this->enTetes($this->jeton()))
            ->assertCreated();
    }

    public function test_le_veterinaire_ne_cloture_pas_la_caisse_l_administrateur_si(): void
    {
        $cloture = ['fond' => 200, 'especes_comptees' => 200, 'nouveau_fond' => 200];

        $this->postJson('/api/v1/facturation/clotures-caisse', $cloture, $this->enTetes($this->jeton()))->assertForbidden();
        // Autorisé : la clôture ne bute plus que sur l'absence d'encaissements
        $this->postJson('/api/v1/facturation/clotures-caisse', $cloture, $this->enTetes($this->jeton('administrateur')))
            ->assertUnprocessable()->assertJsonValidationErrors('factures');
    }

    public function test_la_fin_de_consultation_exige_la_caisse(): void
    {
        $rendezVous = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id]);
        $consultation = Prestation::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'code' => 'ESSAI-FIN']);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", ['prestations' => [$consultation->id]], $this->enTetes($this->jeton('assistant')))
            ->assertForbidden();
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", ['prestations' => [$consultation->id]], $this->enTetes($this->jeton('asv')))
            ->assertOk();
    }

    /** À la caisse : une ordonnance (pharmacie ou produit sur ordonnance) se prescrit avec le droit ; un stupéfiant n'y passe pas. */
    public function test_l_asv_ne_prescrit_pas_depuis_la_caisse(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $surOrdonnance = Produit::factory()->medicament()->create(['organisation_id' => $organisation, 'est_sur_ordonnance' => true]);
        $stupefiant = Produit::factory()->medicament()->create(['organisation_id' => $organisation, 'est_stupefiant' => true]);
        foreach ([$surOrdonnance, $stupefiant] as $produit) {
            Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 5]);
        }
        [$asv, $veto] = [$this->enTetes($this->jeton('asv')), $this->enTetes($this->jeton())];

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/prescriptions", ['produit_id' => $surOrdonnance->id], $asv)->assertForbidden();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $surOrdonnance->id], $asv)->assertForbidden();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/prescriptions", ['produit_id' => $surOrdonnance->id], $veto)->assertOk();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $surOrdonnance->id], $veto)->assertOk();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $stupefiant->id], $veto)
            ->assertUnprocessable()->assertJsonValidationErrors('produit_id');

        // La ligne prescrite : l'ASV n'en change ni la quantité ni ne la retire (24.10.2026)
        $ligne = $facture->lignes()->where('produit_id', $surOrdonnance->id)->firstOrFail();
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 3], $asv)->assertForbidden();
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $asv)->assertForbidden();
        $this->assertSame(1, (int) $ligne->refresh()->quantite);
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 3], $veto)->assertOk();
        $this->assertSame(3, (int) $ligne->refresh()->quantite);
    }

    /** Une ligne de stupéfiant (posée par la fin de consultation) : l'ASV n'en change ni la quantité ni ne la retire (24.10.2026). */
    public function test_la_ligne_d_un_stupefiant_demande_le_droit_de_prescrire(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        // Stupéfiant sans la marque « sur ordonnance » : c'est la branche stupéfiant qui est éprouvée
        $stupefiant = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_stupefiant' => true, 'est_sur_ordonnance' => false]);
        $ligne = $facture->lignes()->create(['libelle' => $stupefiant->nom, 'quantite' => 1, 'prix_chf' => 30, 'type' => 'med', 'produit_id' => $stupefiant->id]);
        $facture->lignes()->create(['libelle' => 'Consultation', 'quantite' => 1, 'prix_chf' => 80, 'type' => 'acte']);
        [$asv, $veto] = [$this->enTetes($this->jeton('asv')), $this->enTetes($this->jeton())];

        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 2], $asv)->assertForbidden();
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $asv)->assertForbidden();
        $this->assertSame(1, (int) $ligne->refresh()->quantite);
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 2], $veto)->assertOk();
    }

    private function jeton(?string $etat = null): string
    {
        return $this->employeAvecJeton($this->clinique, [], $etat)['jeton'];
    }
}
