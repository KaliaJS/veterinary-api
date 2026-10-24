<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\VaccinSchema;
use App\Models\Valence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Carnet de vaccination avec catalogue (16.10.2026) : injection depuis un
 * produit (échéance calculée du schéma : prochaine dose de primo, puis
 * rappel), depuis un code-barres, saisie libre ; historique complet ;
 * état par valence (primo en cours, à jour, en retard) ; catalogue filtré
 * par espèce et recherche, GTIN inconnu refusé.
 */
class VaccinationsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private ProduitVeterinaire $dhppi;

    private ProduitVeterinaire $rage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        foreach (['carre' => 'Maladie de Carré', 'parvovirose' => 'Parvovirose canine', 'rage' => 'Rage'] as $code => $nom) {
            Valence::create(['code' => $code, 'nom' => $nom, 'nom_court' => ucfirst($code)]);
        }
        $this->dhppi = ProduitVeterinaire::create(['numero_autorisation' => '1631', 'nom' => 'Nobivac DHPPi ad us. vet.', 'nom_court' => 'Nobivac DHPPi', 'categorie' => 'vaccin', 'atc_code' => 'QI07AD04', 'especes' => ['CH'], 'titulaire' => 'MSD']);
        $this->dhppi->valences()->attach(Valence::whereIn('code', ['carre', 'parvovirose'])->pluck('id'));
        VaccinSchema::create(['produit_id' => $this->dhppi->id, 'age_min_semaines' => 8, 'primo_injections' => 2, 'primo_intervalle_semaines' => 4, 'rappel_mois' => 12, 'voie' => 'sous-cutanée']);
        ProduitEmballage::create(['produit_id' => $this->dhppi->id, 'numero_emballage' => '01631001', 'gtin' => '8713184095282']);

        $this->rage = ProduitVeterinaire::create(['numero_autorisation' => '1337', 'nom' => 'Nobivac Rabies ad us. vet.', 'nom_court' => 'Nobivac Rabies', 'categorie' => 'vaccin', 'atc_code' => 'QI07AA02', 'especes' => ['CH', 'CT', 'CV']]);
        $this->rage->valences()->attach(Valence::where('code', 'rage')->value('id'));
        VaccinSchema::create(['produit_id' => $this->rage->id, 'primo_injections' => 1, 'rappel_mois' => 36]);
    }

    public function test_une_injection_depuis_le_catalogue_prend_les_valences_et_l_echeance_du_schema(): void
    {
        $patient = $this->patient();

        $premiere = $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", [
            'produit_id' => $this->dhppi->id, 'lot' => 'A123', 'peremption_le' => '2027-06-30',
        ], $this->enTetes($this->jeton))->assertCreated();
        $premiere->assertJsonPath('data.marque', 'Nobivac DHPPi')
            ->assertJsonPath('data.voie', 'sous-cutanée')
            ->assertJsonPath('data.echeance_le', aujourdhui()->addWeeks(4)->toDateString())
            ->assertJsonCount(2, 'data.valences');

        // Deuxième dose de la primo : le rappel annuel s applique
        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", [
            'produit_id' => $this->dhppi->id, 'injectee_le' => aujourdhui()->toDateString(),
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.echeance_le', aujourdhui()->addMonths(12)->toDateString());

        $this->assertSame(2, $patient->vaccinations()->count(), 'chaque injection est une ligne');
    }

    public function test_l_etat_par_valence_signale_la_primo_en_cours_puis_a_jour(): void
    {
        $patient = $this->patient();
        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", ['produit_id' => $this->dhppi->id], $this->enTetes($this->jeton))->assertCreated();

        $etat = $this->getJson("/api/v1/patients/{$patient->id}/vaccinations", $this->enTetes($this->jeton))->assertOk()->json('etat');
        $this->assertCount(2, $etat);
        $this->assertSame('primo', $etat[0]['statut']);
        $this->assertSame(['faites' => 1, 'attendues' => 2], $etat[0]['primo']);

        // (le jeton expire avec le voyage dans le temps : on en émet un autre)
        $this->travel(4)->weeks();
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", ['produit_id' => $this->dhppi->id], $this->enTetes($jeton))->assertCreated();
        $etat = $this->getJson("/api/v1/patients/{$patient->id}/vaccinations", $this->enTetes($jeton))->json('etat');
        $this->assertSame('ok', $etat[0]['statut']);
        $this->assertSame(2, $etat[0]['injections']);

        $this->travel(13)->months();
        // L'abonnement de la clinique a été renouvelé entre-temps
        $this->clinique->societe->organisation->forceFill(['periode_fin' => now()->addMonth()])->save();
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $etat = $this->getJson("/api/v1/patients/{$patient->id}/vaccinations", $this->enTetes($jeton))->json('etat');
        $this->assertSame('retard', $etat[0]['statut']);
    }

    public function test_le_code_barres_retrouve_le_produit_et_un_code_inconnu_est_refuse(): void
    {
        $patient = $this->patient();
        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", ['gtin' => '87131840 95282', 'lot' => 'B2'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.produit_id', $this->dhppi->id)
            ->assertJsonPath('data.gtin', '8713184095282');

        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", ['gtin' => '7680000000000'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gtin');

        $this->getJson('/api/v1/vaccins/gtin/8713184095282', $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.nom', 'Nobivac DHPPi')->assertJsonPath('emballage.gtin', '8713184095282');
    }

    public function test_un_produit_sans_valence_au_catalogue_prend_son_nom_court(): void
    {
        // Les valences ne se déduisent pas toujours à l'import (description ATCvet muette)
        $sansValence = ProduitVeterinaire::create(['numero_autorisation' => '9001', 'nom' => 'Vaccin X ad us. vet.', 'nom_court' => 'Vaccin X', 'categorie' => 'immunologique', 'especes' => ['CH']]);
        $patient = $this->patient();

        $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", ['produit_id' => $sansValence->id], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.nom', 'Vaccin X')
            ->assertJsonPath('data.marque', 'Vaccin X');
    }

    public function test_une_saisie_libre_reste_possible_et_se_corrige(): void
    {
        $patient = $this->patient();
        $id = $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", [
            'nom' => 'Leishmaniose', 'marque' => 'CaniLeish', 'echeance_le' => aujourdhui()->addYear()->toDateString(),
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/patients/{$patient->id}/vaccinations/{$id}", ['nom' => 'Leishmaniose', 'lot' => 'L9', 'echeance_le' => aujourdhui()->addYear()->toDateString()], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.lot', 'L9');
        $this->deleteJson("/api/v1/patients/{$patient->id}/vaccinations/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame(0, $patient->vaccinations()->count());
    }

    /** Signée par qui l'a faite : un collègue ne la réécrit ni ne l'efface sous son nom. */
    public function test_seule_la_personne_qui_a_signe_l_injection_la_corrige(): void
    {
        $patient = $this->patient();
        $id = $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", [
            'nom' => 'Leishmaniose', 'echeance_le' => aujourdhui()->addYear()->toDateString(),
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);

        $this->putJson("/api/v1/patients/{$patient->id}/vaccinations/{$id}", ['nom' => 'Rage', 'lot' => 'X1'], $this->enTetes($collegue))->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/vaccinations/{$id}", [], $this->enTetes($collegue))->assertForbidden();
        $this->assertSame('Leishmaniose', $patient->vaccinations()->sole()->nom);
    }

    /** Corrigée par la personne qui l'a signée : la signature d'origine reste (24.10.2026). */
    public function test_une_correction_garde_la_signature_d_origine(): void
    {
        $patient = $this->patient();
        ['employe' => $signataire, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $id = $this->postJson("/api/v1/patients/{$patient->id}/vaccinations", [
            'nom' => 'Leishmaniose', 'echeance_le' => aujourdhui()->addYear()->toDateString(),
        ], $this->enTetes($jeton))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/patients/{$patient->id}/vaccinations/{$id}", ['nom' => 'Leishmaniose', 'lot' => 'L-2', 'employe_id' => 999999], $this->enTetes($jeton))->assertOk();

        $this->assertSame($signataire->id, $patient->vaccinations()->sole()->employe_id);
    }

    /** Sans signataire (ancien carnet) : l'administration seule la corrige ou la retire (24.10.2026). */
    public function test_une_injection_sans_signataire_ne_se_corrige_que_par_l_administration(): void
    {
        $patient = $this->patient();
        $vaccination = $patient->vaccinations()->create(['nom' => 'Rage', 'injectee_le' => aujourdhui()->subYear()->toDateString(), 'echeance_le' => aujourdhui()->toDateString(), 'employe_id' => null]);
        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);

        $this->putJson("/api/v1/patients/{$patient->id}/vaccinations/{$vaccination->id}", ['nom' => 'Rage', 'lot' => 'X'], $this->enTetes($collegue))->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/vaccinations/{$vaccination->id}", [], $this->enTetes($collegue))->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/vaccinations/{$vaccination->id}", [], $this->enTetes($admin))->assertNoContent();
    }

    /** Corrigée par l'administration, une injection sans signataire le reste : la correction ne la signe pas à sa place (24.10.2026). */
    public function test_l_administration_corrige_une_injection_sans_la_signer(): void
    {
        $patient = $this->patient();
        $vaccination = $patient->vaccinations()->create(['nom' => 'Rage', 'injectee_le' => aujourdhui()->subYear()->toDateString(), 'echeance_le' => aujourdhui()->toDateString(), 'employe_id' => null]);
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);

        $this->putJson("/api/v1/patients/{$patient->id}/vaccinations/{$vaccination->id}", ['nom' => 'Rage', 'lot' => 'R-7'], $this->enTetes($admin))->assertOk();

        $vaccination->refresh();
        $this->assertSame('R-7', $vaccination->lot);
        $this->assertNull($vaccination->employe_id);
    }

    public function test_le_catalogue_se_filtre_par_espece_et_recherche(): void
    {
        $this->getJson('/api/v1/vaccins?espece=CT', $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nom', 'Nobivac Rabies');
        $this->getJson('/api/v1/vaccins?espece=CH&q=parvo', $this->enTetes($this->jeton))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nom', 'Nobivac DHPPi');
        $this->getJson('/api/v1/vaccins?espece=CH', $this->enTetes($this->jeton))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.schema.primo_injections', 2)->assertJsonPath('data.0.emballages.0.gtin', '8713184095282');
    }

    private function patient(): Patient
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);

        return Patient::factory()->for($client)->create(['organisation_id' => $this->clinique->societe->organisation_id, 'espece' => 'CH']);
    }
}
