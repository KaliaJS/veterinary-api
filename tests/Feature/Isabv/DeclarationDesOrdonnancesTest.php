<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\RendezVous;
use App\Models\Stock;
use App\Services\Isabv\DeclarationRules;
use App\Services\Isabv\PreparationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV (08.11.2026) : une ordonnance qui porte un antibiotique de la liste
 * de l'OSAV donne une déclaration (O-SI ABV, art. 2 et 4, annexe ch. 2),
 * prête si elle porte tout ce que l'annexe demande, sinon à compléter, sans
 * jamais bloquer la prescription. Une clinique en variante 2 ne déclare
 * rien par Veterinary.
 */
class DeclarationDesOrdonnancesTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create();
        $this->cliniqueQuiDeclare($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'npa' => '1700', 'rue' => 'Route de la Gruyère 2', 'localite' => 'Bulle']);
        $this->patient = Patient::factory()->for($client)->create(['espece' => 'CH', 'nom' => 'Rex']);
        $this->patient->pesees()->create(['poids_kg' => 32, 'pesee_le' => now()->subDay()->toDateString()]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function medicament(?string $preparation, string $nom = 'Amoxicilline 250 mg · 20 cp'): Produit
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => $nom, 'isabv_preparation_code' => $preparation]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 50]);

        return $produit;
    }

    /** @param array<string, mixed> $isabv  @param array<string, mixed> $ligne */
    private function signer(Produit $produit, array $isabv = [], array $ligne = [], ?Patient $patient = null): array
    {
        return $this->postJson('/api/v1/ordonnances', [
            'patient_id' => ($patient ?? $this->patient)->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'posologie' => '1 cp matin et soir', 'duree' => '7 jours', 'isabv' => $ligne]],
            'isabv' => $isabv,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');
    }

    private const DOSAGE = ['dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7];

    public function test_un_antibiotique_de_la_liste_donne_une_declaration_prete_qui_part_apres_la_fenetre_d_annulation(): void
    {
        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '3.7.1'], self::DOSAGE);

        $this->assertSame('ready', $data['antibiotic_declarations'][0]['status']);
        $this->assertSame('EH', $data['antibiotic_declarations'][0]['form']);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->sole();
        // Prérempli : l'espèce de la fiche (chien), la dernière pesée, la date de signature
        $this->assertSame('1.5', $declaration->species_code);
        $this->assertSame(32.0, $declaration->weight_kg);
        $this->assertSame(aujourdhui()->toDateString(), $declaration->visit_on->toDateString());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $declaration->recipe_number);
        // Facultatifs et absents de la liste des données de l'O-SI ABV : jamais remplis d'office
        $this->assertNull($declaration->postcode);
        $this->assertNull($declaration->identification);
        // 1 cp, 2 fois par jour, 7 jours : 14 comprimés (formule de l'OSAV), remis d'office comme nécessaires
        $this->assertSame(14.0, $declaration->lines[0]->total_quantity);
        $this->assertSame(14.0, $declaration->lines[0]->given_quantity);
        $this->assertSame('po', $declaration->lines[0]->route_code);
        Queue::assertPushed(SendAntibioticDeclaration::class, fn (SendAntibioticDeclaration $job) => $job->declarationId === $declaration->id && $job->delay !== null);
    }

    public function test_en_variante_2_veterinary_ne_declare_rien(): void
    {
        IsabvConnection::withoutGlobalScopes()->where('clinique_id', $this->clinique->id)->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);

        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '3.7.1'], self::DOSAGE);

        $this->assertSame([], $data['antibiotic_declarations']);
        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_un_produit_hors_de_la_liste_de_l_osav_n_est_pas_declare(): void
    {
        // Un topique (pas « transfert SI ABV ») et un médicament sans préparation
        $this->signer($this->medicament('33333-01', 'Pommade ophtalmique'));
        $this->signer($this->medicament(null, 'Méloxicam 1,5 mg/ml'));

        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
    }

    public function test_ce_qui_manque_laisse_la_declaration_a_completer_sans_bloquer_l_ordonnance(): void
    {
        $this->patient->pesees()->delete();
        $data = $this->signer($this->medicament('12345-01'));

        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
        $id = $data['antibiotic_declarations'][0]['id'];
        $missing = $this->getJson('/api/v1/isabv/declarations/'.$id, $this->enTetes($this->jeton))->assertOk()->json('data.missing');
        $this->assertContains('le poids de l’animal', $missing);
        $this->assertContains('l’indication (clé de diagnostic)', $missing);
        $this->assertContains('la quantité par administration', $missing);
        $this->assertContains('la fréquence d’administration', $missing);
        $this->assertContains('le nombre de jours de traitement', $missing);
        Queue::assertNothingPushed();

        // Complétée, elle part
        $this->putJson('/api/v1/isabv/declarations/'.$id, [
            'isabv' => ['weight_kg' => 30.5, 'diagnosis_key' => '3'],
            'lines' => [['id' => AntibioticDeclaration::withoutGlobalScopes()->find($id)->lines()->value('id'), 'isabv' => self::DOSAGE]],
        ], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.missing', []);
        Queue::assertPushed(SendAntibioticDeclaration::class);
    }

    public function test_la_cle_de_diagnostic_doit_etre_de_l_espece(): void
    {
        // « 2.1 » (mammite) est une clé des bovins, pas des chiens
        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '2.1'], self::DOSAGE);

        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
    }

    public function test_la_consommation_totale_suit_la_formule_de_l_osav(): void
    {
        $this->assertSame(14.0, DeclarationRules::totalQuantity(1, 7, 2.0));
        // Un jour sur deux pendant 7 jours : 4 administrations
        $this->assertSame(4.0, DeclarationRules::totalQuantity(1, 7, 0.5));
        $this->assertSame(10.0, DeclarationRules::totalQuantity(0.5, 10, 1.0, 2));
        $this->assertNull(DeclarationRules::totalQuantity(null, 7, 2.0));

        // Saisie par le vétérinaire, elle fait foi ; un 2e dosage s'ajoute sinon
        $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '3'], [...self::DOSAGE, 'dose_2' => 0.5, 'frequency_2' => '11', 'days_2' => 3, 'given_quantity' => 20]);
        $line = AntibioticDeclaration::withoutGlobalScopes()->sole()->lines()->sole();
        $this->assertSame(15.5, $line->total_quantity);
        $this->assertSame(20.0, $line->given_quantity);
    }

    public function test_un_2e_dosage_commence_doit_etre_complet(): void
    {
        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '3'], [...self::DOSAGE, 'dose_2' => 0.5]);

        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
    }

    public function test_une_substance_critique_demande_la_confirmation_du_veterinaire(): void
    {
        $sans = $this->signer($this->medicament('22222-01', 'Enrofloxacine 50 mg'), ['diagnosis_key' => '3'], self::DOSAGE);
        $avec = $this->signer($this->medicament('22222-01', 'Enrofloxacine 50 mg bis'), ['diagnosis_key' => '3'], [...self::DOSAGE, 'is_critical_confirmed' => true]);

        $this->assertSame('to_complete', $sans['antibiotic_declarations'][0]['status']);
        $this->assertSame('ready', $avec['antibiotic_declarations'][0]['status']);
    }

    public function test_l_annulation_immediate_de_l_ordonnance_efface_sa_declaration(): void
    {
        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '3'], self::DOSAGE);

        $this->deleteJson('/api/v1/ordonnances/'.$data['id'], [], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame(0, AntibioticDeclaration::withoutGlobalScopes()->count());
    }

    public function test_le_renouvellement_est_declare_sous_un_nouveau_numero(): void
    {
        $produit = $this->medicament('12345-01');
        $first = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $this->patient->id,
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp matin et soir', 'isabv' => self::DOSAGE]],
            'renouvellements' => 1,
            'isabv' => ['diagnosis_key' => '3.7.1'],
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->postJson('/api/v1/ordonnances/'.$first['id'].'/renouveler', [], $this->enTetes($this->jeton))->assertCreated();

        $declarations = AntibioticDeclaration::withoutGlobalScopes()->with('lines')->orderBy('id')->get();
        $this->assertCount(2, $declarations);
        $this->assertNotSame($declarations[0]->recipe_number, $declarations[1]->recipe_number);
        $this->assertSame(DeclarationStatus::Ready, $declarations[1]->status);
        $this->assertSame('3.7.1', $declarations[1]->diagnosis_key);
        $this->assertSame(14.0, $declarations[1]->lines[0]->total_quantity);
        $this->assertNotSame($declarations[0]->lines[0]->ordonnance_ligne_id, $declarations[1]->lines[0]->ordonnance_ligne_id);
    }

    public function test_la_fin_de_consultation_declare_l_antibiotique_donne_ou_remis(): void
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id, 'status' => 'cours', 'type' => 'consult']);
        $prestation = Prestation::withoutGlobalScopes()->where('organisation_id', $this->clinique->societe->organisation_id)->firstOrFail();
        $produit = $this->medicament('12345-01');

        $this->postJson('/api/v1/rendez-vous/'.$rendezVous->id.'/fin-de-consultation', [
            'prestations' => [$prestation->id],
            'medicaments' => [['produit_id' => $produit->id, 'posologie' => '1 cp matin et soir', 'vat_context' => 'care', 'isabv' => self::DOSAGE]],
            'isabv' => ['diagnosis_key' => '3.7.1'],
        ], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('ordonnance.antibiotic_declarations.0.status', 'ready');
    }

    public function test_animal_de_rente_un_traitement_individuel_en_porte_bdta_adresse_et_categorie(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'rue' => 'Ferme du Moulin', 'npa' => '1630', 'localite' => 'Bulle', 'holding_number' => '1234565']);
        $vache = Patient::factory()->for($client)->create(['espece' => 'BV', 'race' => null, 'nom' => 'Marguerite']);
        $produit = $this->medicament('44444-02', 'Pénicilline intramammaire');

        // L'espèce de rente seule (« 2.1 ») ne suffit pas : la catégorie d'utilisation se choisit
        $data = $this->signer($produit, ['diagnosis_key' => '2.1'], ['dose_1' => 1, 'frequency_1' => '6', 'days_1' => 3], $vache);
        $this->assertSame('EN', $data['antibiotic_declarations'][0]['form']);
        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->sole();
        $this->assertSame('1234565', $declaration->holding_number);
        $this->assertSame('Ferme du Moulin, 1630 Bulle', $declaration->holding_address);
        $this->assertNull($declaration->weight_kg);

        $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, ['isabv' => ['species_code' => '2.1.5']], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.status', 'ready');

        // Une clé de diagnostic d'un seul niveau ne suffit pas pour un animal de rente
        $data = $this->signer($produit, ['diagnosis_key' => '2', 'species_code' => '2.1.5'], ['dose_1' => 1, 'frequency_1' => '6', 'days_1' => 3], $vache);
        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
    }

    public function test_remise_a_titre_de_stocks_deux_des_trois_et_une_declaration_par_preparation(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'rue' => 'Ferme du Moulin', 'npa' => '1630', 'localite' => 'Bulle', 'holding_number' => '1234565']);
        $vache = Patient::factory()->for($client)->create(['espece' => 'BV', 'race' => null, 'isabv_species' => '2.1.5']);
        $a = $this->medicament('44444-02', 'Pénicilline intramammaire');
        $b = $this->medicament('12345-01', 'Amoxicilline');

        $data = $this->postJson('/api/v1/ordonnances', [
            'patient_id' => $vache->id,
            'lignes' => [['produit_id' => $a->id, 'posologie' => 'selon convention', 'isabv' => ['given_quantity' => 20]], ['produit_id' => $b->id, 'posologie' => 'selon convention', 'isabv' => ['given_quantity' => 10]]],
            'isabv' => ['is_stock_supply' => true, 'diagnosis_key' => '2.1', 'animals_count' => 4],
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertCount(2, $data['antibiotic_declarations']);
        $this->assertSame(['AV', 'AV'], array_column($data['antibiotic_declarations'], 'form'));
        // Une seule des trois (le nombre d'animaux) : à compléter
        $this->assertSame(['to_complete', 'to_complete'], array_column($data['antibiotic_declarations'], 'status'));
        $this->putJson('/api/v1/isabv/declarations/'.$data['antibiotic_declarations'][0]['id'], ['isabv' => ['av_treatment_days' => 5]], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.status', 'ready');
    }

    public function test_un_traitement_oral_d_un_groupe_de_rente_se_saisit_dans_l_application_web(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'rue' => 'Porcherie', 'npa' => '1630', 'localite' => 'Bulle', 'holding_number' => '7654329']);
        $porc = Patient::factory()->for($client)->create(['espece' => 'PO', 'race' => null, 'isabv_species' => '2.2.3']);

        // 21 porcs par voie orale : plus que le groupe de l'OMédV (20 porcs)
        $data = $this->signer($this->medicament('12345-01'), ['diagnosis_key' => '5.1', 'animals_count' => 21], ['dose_1' => 1, 'frequency_1' => '6', 'days_1' => 3], $porc);
        $this->assertSame('to_complete', $data['antibiotic_declarations'][0]['status']);
        $this->getJson('/api/v1/isabv/declarations/'.$data['antibiotic_declarations'][0]['id'], $this->enTetes($this->jeton))
            ->assertJsonPath('data.missing', [])
            ->assertJsonPath('data.web_only_reason', 'Traitement oral d’un groupe de plus de 20 animaux : il se saisit dans l’application web de l’OSAV, pas par Veterinary.');

        // 20 porcs : par Veterinary
        $data = $this->signer($this->medicament('12345-01', 'Amox bis'), ['diagnosis_key' => '5.1', 'animals_count' => 20], ['dose_1' => 1, 'frequency_1' => '6', 'days_1' => 3], $porc);
        $this->assertSame('ready', $data['antibiotic_declarations'][0]['status']);
        // 20 porcs × 1 × (3 jours, 1 fois par jour)
        $this->assertSame(60.0, AntibioticDeclaration::withoutGlobalScopes()->find($data['antibiotic_declarations'][0]['id'])->lines()->value('total_quantity'));
    }

    public function test_un_animal_de_zoo_se_declare_par_le_formulaire_fzl(): void
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'rue' => 'Zoo de la Garenne', 'npa' => '1261', 'localite' => 'Le Vaud']);
        $lynx = Patient::factory()->for($client)->create(['espece' => 'CT', 'isabv_species' => '5.2.1']);

        $data = $this->signer($this->medicament('12345-01'), [], ['given_quantity' => 14], $lynx);

        $this->assertSame('FZL', $data['antibiotic_declarations'][0]['form']);
        $this->assertSame('ready', $data['antibiotic_declarations'][0]['status']);
    }

    public function test_la_preparation_vient_du_code_barres_si_elle_n_est_pas_choisie_a_la_main(): void
    {
        $catalogue = ProduitVeterinaire::query()->create(['numero_autorisation' => '12345', 'nom' => 'Amoxicilline vétérinaire 250 mg', 'nom_court' => 'Amoxicilline', 'categorie' => 'medicament']);
        ProduitEmballage::query()->create(['produit_id' => $catalogue->id, 'numero_emballage' => '12345001', 'sequence' => '1', 'gtin' => '7680123450016']);
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'ean' => '7680123450016']);

        $this->assertSame('12345-01', app(PreparationResolver::class)->forProduct($produit)?->code);
        $this->assertSame('12345-01', app(PreparationResolver::class)->declarable($produit)?->code);
        // Choisie à la main, elle l'emporte
        $produit->update(['isabv_preparation_code' => '33333-01']);
        $this->assertSame('33333-01', app(PreparationResolver::class)->forProduct($produit->fresh())?->code);
        $this->assertNull(app(PreparationResolver::class)->declarable($produit->fresh()));
    }
}
