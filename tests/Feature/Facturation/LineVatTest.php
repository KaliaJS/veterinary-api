<?php

namespace Tests\Feature\Facturation;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * TVA par ligne, selon le contexte (décision de Jim, 03.11.2026, d'après
 * l'Info TVA 21 de l'AFC). Un soin suit l'espèce : 8,1 % pour un animal de
 * compagnie, 2,6 % pour un animal de rente (le cheval compris). Un article
 * donné pendant un soin suit le soin ; remis au propriétaire ou vendu, il
 * prend son propre taux de vente. Une case de notes/todo.md par test.
 */
class LineVatTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->client = Client::factory()->create(['organisation_id' => $this->organisationId()]);
    }

    private function organisationId(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    private function patient(Espece $espece): Patient
    {
        return Patient::factory()->for($this->client)->create(['espece' => $espece, 'race' => 'Croisé']);
    }

    private function article(string $categorie, array $plus = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->organisationId(),
            'categorie' => $categorie,
            'prix' => 52.60,
            'est_sur_ordonnance' => false,
            'est_stupefiant' => false,
            'est_boutique' => true,
            ...$plus,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);

        return $produit;
    }

    private function prestation(string $code): Prestation
    {
        return Prestation::withoutGlobalScopes()->where('organisation_id', $this->organisationId())->where('code', $code)->sole();
    }

    private function consultationEnCours(Patient $patient): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Cours,
            'patient_id' => $patient->id,
            'client_id' => $patient->client_id,
            'patient_name' => $patient->nom,
            'species' => $patient->espece,
        ]);
    }

    /** @return array<string, mixed> la facture servie */
    private function finDeConsultation(Patient $patient, array $corps): array
    {
        $rendezVous = $this->consultationEnCours($patient);

        return $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
            ...$corps,
        ], $this->enTetes($this->jeton))->assertOk()->json('facture');
    }

    /** @param  array<string, mixed>  $facture */
    private function ligne(array $facture, string $libelle): array
    {
        return collect($facture['lignes'])->firstWhere('libelle', $libelle);
    }

    // Case 1 : chaque ligne porte son taux, calculé seul ; personne ne le choisit

    public function test_chaque_ligne_porte_son_taux_calcule_seul_et_personne_ne_le_choisit(): void
    {
        $chien = $this->patient(Espece::Chien);
        $croquettes = $this->article('ali', ['nom' => 'Croquettes rénales']);

        // Un taux et un contexte envoyés par l'écran sont ignorés : le serveur décide
        $facture = $this->postJson('/api/v1/facturation/factures', [
            'patient_id' => $chien->id,
            'lignes' => [
                ['libelle' => 'Consultation', 'prix_chf' => 85, 'type' => 'acte', 'taux_tva' => 0, 'vat_context' => 'sale'],
                ['libelle' => 'Croquettes rénales', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $croquettes->id, 'taux_tva' => 8.1, 'vat_context' => 'care'],
            ],
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertSame(8.1, $this->ligne($facture, 'Consultation')['taux_tva']);
        $this->assertSame(2.6, $this->ligne($facture, 'Croquettes rénales')['taux_tva']);

        // Figé en base, ligne par ligne
        $this->assertEqualsCanonicalizing(
            [8.1, 2.6],
            FactureLigne::query()->where('facture_id', $facture['id'])->pluck('taux_tva')->map(fn ($t) => (float) $t)->all(),
        );
    }

    // Case 2 : une ligne de soin prend le taux de l'espèce de l'animal

    public function test_une_ligne_de_soin_prend_le_taux_de_l_espece_de_l_animal(): void
    {
        $chien = $this->finDeConsultation($this->patient(Espece::Chien), []);
        $cheval = $this->finDeConsultation($this->patient(Espece::Cheval), []);
        $vache = $this->finDeConsultation($this->patient(Espece::Bovin), []);

        $nom = $this->prestation('consult')->nom;
        $this->assertSame(8.1, $this->ligne($chien, $nom)['taux_tva']);
        $this->assertSame(2.6, $this->ligne($cheval, $nom)['taux_tva']);
        $this->assertSame(2.6, $this->ligne($vache, $nom)['taux_tva']);
        $this->assertSame('care', $this->ligne($cheval, $nom)['vat_context']);
    }

    // Case 3 : un article donné pendant un soin (consultation, hospitalisation, bloc) prend le taux du soin

    public function test_un_article_donne_pendant_un_soin_prend_le_taux_du_soin(): void
    {
        // Consultation : le vaccin injecté, un médicament (2,6 % à la vente), suit le soin du chien
        $vaccin = $this->article('med', ['nom' => 'Nobivac DHPPi']);
        $consultation = $this->finDeConsultation($this->patient(Espece::Chien), [
            'vaccination' => ['nom' => 'CHPPi', 'produit_id' => $vaccin->id, 'echeance_le' => '2027-11-03'],
        ]);
        $this->assertSame(8.1, $this->ligne($consultation, 'Nobivac DHPPi')['taux_tva']);
        $this->assertSame('care', $this->ligne($consultation, 'Nobivac DHPPi')['vat_context']);

        // Hospitalisation : le médicament donné à la dose suit le séjour du chien
        $chien = $this->patient(Espece::Chien);
        $cerenia = $this->article('med', ['nom' => 'Cerenia 10 mg/ml', 'prix' => 60, 'dose_unit' => 'ml', 'content_per_unit' => 20]);
        $sejour = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $chien->id, 'client_id' => $this->client->id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null,
            'admis_le' => CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Zurich')->utc(),
        ]);
        $traitement = StayTreatment::create(['sejour_id' => $sejour->id, 'label' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'product_id' => $cerenia->id, 'times' => [600], 'starts_at' => $sejour->admis_le]);
        SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement->id, 'jour' => '2026-11-03', 'prevu_a' => 600, 'acte' => 'Maropitant', 'dose' => '1 mg/kg IV', 'dose_amount' => 2, 'produit_id' => $cerenia->id, 'fait' => true, 'fait_le' => now()]);
        $sortie = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $this->enTetes($this->jeton))->assertOk()->json('facture');
        $medicament = collect($sortie['lignes'])->firstWhere('type', 'med');
        $this->assertSame(8.1, $medicament['taux_tva']);

        // Bloc : le devis de chirurgie facturé, ses articles suivent l'intervention (ici un cheval, 2,6 %)
        $cheval = $this->patient(Espece::Cheval);
        $collerette = $this->article('mat', ['nom' => 'Collerette T45']);
        $devis = Devis::factory()->create(['organisation_id' => $this->organisationId(), 'clinique_id' => $this->clinique->id, 'patient_id' => $cheval->id, 'statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now()]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 400]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Collerette T45', 'prix_chf' => 20, 'type' => 'med', 'produit_id' => $collerette->id]);
        $bloc = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame(2.6, $this->ligne($bloc, 'Chirurgie : forfait bloc')['taux_tva']);
        $this->assertSame(2.6, $this->ligne($bloc, 'Collerette T45')['taux_tva']);
    }

    // Case 4 : un article remis au propriétaire ou vendu (caisse, boutique, ordonnance remise) prend le taux de l'article

    public function test_un_article_remis_ou_vendu_prend_le_taux_de_l_article(): void
    {
        // L'exemple de Jim : la même pommade, appliquée au chien pendant la consultation (8,1 %)…
        $pommade = $this->article('med', ['nom' => 'Pommade cicatrisante']);
        $this->assertSame('reduced', $pommade->fresh()->vat_rate->value);

        // … et remise au propriétaire sur ordonnance à la fin de la consultation : 2,6 %
        $remise = $this->finDeConsultation($this->patient(Espece::Chien), [
            'medicaments' => [['produit_id' => $pommade->id, 'posologie' => '2 fois par jour', 'duree' => '7 jours']],
        ]);
        $this->assertSame(2.6, $this->ligne($remise, 'Pommade cicatrisante')['taux_tva']);
        $this->assertSame('sale', $this->ligne($remise, 'Pommade cicatrisante')['vat_context']);

        // Caisse : un article ajouté à la facture d'un cheval garde son taux de vente (8,1 % pour une laisse)
        $laisse = $this->article('mat', ['nom' => 'Longe de cheval']);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'species' => Espece::Cheval]);
        FactureLigne::factory()->create(['facture_id' => $facture->id]);
        $caisse = $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['produit_id' => $laisse->id], $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(8.1, $this->ligne($caisse, 'Longe de cheval')['taux_tva']);

        // Boutique : au comptoir, chaque article à son taux ; le ticket le dit d'avance
        $croquettes = $this->article('ali', ['nom' => 'Croquettes rénales']);
        $corps = ['moyen_paiement' => 'cash', 'lignes' => [['produit_id' => $croquettes->id, 'quantite' => 1], ['produit_id' => $laisse->id, 'quantite' => 1]]];
        $ticket = $this->postJson('/api/v1/boutique/vente/apercu', $corps, $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame([2.6, 8.1], array_column($ticket['vat_breakdown'], 'rate'));
        $vente = $this->postJson('/api/v1/boutique/vente', $corps, $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame(2.6, $this->ligne($vente, 'Croquettes rénales')['taux_tva']);
        $this->assertSame(8.1, $this->ligne($vente, 'Longe de cheval')['taux_tva']);

        // Le taux de vente d'un article se corrige : la ligne suivante le suit
        $this->patchJson("/api/v1/stocks/produits/{$laisse->id}/vat-rate", ['vat_rate' => 'reduced'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.vat_rate', 'reduced')->assertJsonPath('data.vat_rate_percent', 2.6);
        $apres = $this->postJson('/api/v1/boutique/vente', ['moyen_paiement' => 'cash', 'lignes' => [['produit_id' => $laisse->id, 'quantite' => 1]]], $this->enTetes($this->jeton))->json('data');
        $this->assertSame(2.6, $this->ligne($apres, 'Longe de cheval')['taux_tva']);
    }

    public function test_le_taux_de_vente_d_un_article_est_propose_selon_la_famille_et_accepte_a_la_creation(): void
    {
        $creer = fn (array $corps) => $this->postJson('/api/v1/stocks/produits', ['nom' => 'Article', 'prix' => 10, ...$corps], $this->enTetes($this->jeton))->assertCreated();

        foreach (['med' => 'reduced', 'apara' => 'reduced', 'phyto' => 'reduced', 'ali' => 'reduced', 'soin' => 'normal', 'mat' => 'normal'] as $famille => $taux) {
            $creer(['categorie' => $famille])->assertJsonPath('data.vat_rate', $taux);
        }
        $creer(['categorie' => 'mat', 'vat_rate' => 'reduced'])->assertJsonPath('data.vat_rate', 'reduced')->assertJsonPath('data.vat_rate_percent', 2.6);
        $this->postJson('/api/v1/stocks/produits', ['nom' => 'Article', 'prix' => 10, 'categorie' => 'mat', 'vat_rate' => 'zero'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('vat_rate');

        // Les taux et la proposition par famille, servis pour la fenêtre de l'article
        $this->getJson('/api/v1/stocks/vat-rates', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.rates.normal', 8.1)
            ->assertJsonPath('data.rates.reduced', 2.6)
            ->assertJsonPath('data.category_defaults.ali', 'reduced')
            ->assertJsonPath('data.category_defaults.mat', 'normal');
    }

    // Case 5 : la facture montre la TVA par taux ; une société non assujettie n'en montre aucune

    public function test_la_facture_montre_la_tva_par_taux_et_une_societe_non_assujettie_aucune(): void
    {
        $chien = $this->patient(Espece::Chien);
        $croquettes = $this->article('ali', ['nom' => 'Croquettes rénales', 'prix' => 52.60]);
        $corps = ['patient_id' => $chien->id, 'lignes' => [
            ['libelle' => 'Consultation', 'prix_chf' => 108.10, 'type' => 'acte'],
            ['libelle' => 'Croquettes rénales', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $croquettes->id],
        ]];

        $facture = $this->postJson('/api/v1/facturation/factures', $corps, $this->enTetes($this->jeton))->assertCreated()->json('data');
        // 52.60 TTC à 2,6 % : 51.27 + 1.33 ; 108.10 à 8,1 % : 100.00 + 8.10
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 51.27, 'vat' => 1.33, 'incl_vat' => 52.6],
            ['rate' => 8.1, 'excl_vat' => 100, 'vat' => 8.1, 'incl_vat' => 108.1],
        ], $facture['vat_breakdown']);
        $this->assertSame(9.43, $facture['montant_tva']);
        $this->assertSame(151.27, $facture['total_ht']);
        $this->assertNull($facture['taux_tva']);

        // L'espace du propriétaire lit le même détail pour son PDF
        $this->clinique->societe->update(['assujettie_tva' => false]);
        $sans = $this->postJson('/api/v1/facturation/factures', $corps, $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame([], $sans['vat_breakdown']);
        $this->assertSame(0, $sans['montant_tva']);
        $this->assertSame(0, $this->ligne($sans, 'Croquettes rénales')['taux_tva']);
    }

    public function test_les_documents_du_proprietaire_et_le_journal_servent_la_tva_par_taux(): void
    {
        ['jeton' => $jetonProprietaire, 'user' => $user] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        $chien = $this->patient(Espece::Chien);
        $croquettes = $this->article('ali', ['nom' => 'Croquettes rénales', 'prix' => 52.60]);
        $facture = $this->postJson('/api/v1/facturation/factures', ['patient_id' => $chien->id, 'lignes' => [
            ['libelle' => 'Consultation', 'prix_chf' => 108.10, 'type' => 'acte'],
            ['libelle' => 'Croquettes rénales', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $croquettes->id],
        ]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->postJson("/api/v1/facturation/factures/{$facture['id']}/encaisser", ['moyen_paiement' => 'cash'], $this->enTetes($this->jeton))->assertOk();

        $documents = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonProprietaire))->assertOk()->json('data'));
        $pdf = $documents->firstWhere('type', 'FACTURE')['pdf'];
        $this->assertSame([2.6, 8.1], array_column($pdf['vat_breakdown'], 'rate'));
        $this->assertSame([8.1, 2.6], array_column($pdf['lignes'], 'taux_tva'));

        $journal = $this->getJson('/api/v1/facturation/factures/journal?mois=2026-11', $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 51.27, 'vat' => 1.33, 'incl_vat' => 52.6],
            ['rate' => 8.1, 'excl_vat' => 100, 'vat' => 8.1, 'incl_vat' => 108.1],
        ], $journal['encaissements']['vat_breakdown']);
    }

    // Case 6 : les factures déjà émises gardent leur taux unique, figé

    public function test_les_factures_deja_emises_gardent_leur_taux_unique_fige(): void
    {
        // Une facture d'avant : un seul taux sur la facture, aucun sur ses lignes ; même pour un cheval
        $ancienne = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'species' => Espece::Cheval, 'taux_tva' => 8.1]);
        $croquettes = $this->article('ali', ['nom' => 'Croquettes rénales']);
        DB::table('facture_lignes')->insert([
            ['facture_id' => $ancienne->id, 'libelle' => 'Consultation', 'quantite' => 1, 'prix_chf' => 108.10, 'type' => 'acte', 'produit_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['facture_id' => $ancienne->id, 'libelle' => 'Croquettes rénales', 'quantite' => 1, 'prix_chf' => 54.05, 'type' => 'med', 'produit_id' => $croquettes->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame(2, FactureLigne::query()->where('facture_id', $ancienne->id)->whereNull('taux_tva')->count());

        $servie = $this->getJson("/api/v1/facturation/factures/{$ancienne->id}", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(8.1, $servie['taux_tva']);
        $this->assertSame([['rate' => 8.1, 'excl_vat' => 150, 'vat' => 12.15, 'incl_vat' => 162.15]], $servie['vat_breakdown']);
        $this->assertSame([8.1, 8.1], array_column($servie['lignes'], 'taux_tva'));
        $this->assertSame(12.15, $servie['montant_tva']);
    }
}
