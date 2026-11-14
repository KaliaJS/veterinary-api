<?php

namespace Tests\Feature\Facturation;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * TVA par ligne, suite de la vérification indépendante (03.11.2026) :
 * l'article donné pendant le soin se dit en caisse et en fin de
 * consultation, la déduction d'un acompte reprend son taux, une remise se
 * répartit au prorata des taux, l'espèce sans fiche ne compte pas, une
 * facture d'avant reste à son taux unique, le devis distingue ce qui part
 * à la maison, et le journal sert ses taux.
 */
class LineVatFollowUpTest extends TestCase
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

    private function article(string $categorie, string $nom, array $plus = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->organisationId(), 'categorie' => $categorie, 'nom' => $nom, 'prix' => 100,
            'est_sur_ordonnance' => false, 'est_stupefiant' => false, 'dose_unit' => null, 'content_per_unit' => null, ...$plus,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);

        return $produit;
    }

    /** Metacam, sorti à la dose : contenance et unité de dose */
    private function metacam(): Produit
    {
        return $this->article('med', 'Metacam 5 mg/ml', ['dose_unit' => 'ml', 'content_per_unit' => 20]);
    }

    private function finDeConsultation(Patient $patient, array $corps = []): array
    {
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Consultation, 'status' => StatutRendezVous::Cours,
            'patient_id' => $patient->id, 'client_id' => $patient->client_id, 'patient_name' => $patient->nom, 'species' => $patient->espece,
        ]);
        $consult = Prestation::withoutGlobalScopes()->where('organisation_id', $this->organisationId())->where('code', 'consult')->sole();

        return $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", ['prestations' => [$consult->id], ...$corps], $this->enTetes($this->jeton))
            ->assertOk()->json('facture');
    }

    private function ligne(array $facture, string $libelle): array
    {
        return collect($facture['lignes'])->firstWhere('libelle', $libelle);
    }

    // MOYEN 1 : l'article donné pendant le soin, en caisse et en fin de consultation

    public function test_en_caisse_l_article_d_une_facture_de_soin_se_dit_donne_pendant_le_soin_ou_remis(): void
    {
        $facture = $this->finDeConsultation($this->patient(Espece::Chien));
        $this->assertTrue($facture['has_care']);
        $metacam = $this->metacam();
        $pommade = $this->article('med', 'Pommade cicatrisante');

        // Sorti à la dose : donné pendant le soin par défaut ; le reste : remis au propriétaire
        $this->postJson("/api/v1/facturation/factures/{$facture['id']}/lignes", ['produit_id' => $metacam->id], $this->enTetes($this->jeton))->assertOk();
        $servie = $this->postJson("/api/v1/facturation/factures/{$facture['id']}/lignes", ['produit_id' => $pommade->id], $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(['care', 8.1], [$this->ligne($servie, 'Metacam 5 mg/ml')['vat_context'], $this->ligne($servie, 'Metacam 5 mg/ml')['taux_tva']]);
        $this->assertSame(['sale', 2.6], [$this->ligne($servie, 'Pommade cicatrisante')['vat_context'], $this->ligne($servie, 'Pommade cicatrisante')['taux_tva']]);

        // Le contexte se change, le taux suit, calculé par le serveur ; même stock déjà sorti
        Facture::withoutGlobalScopes()->whereKey($facture['id'])->update(['stock_sorti' => true]);
        $id = $this->ligne($servie, 'Pommade cicatrisante')['id'];
        $apres = $this->patchJson("/api/v1/facturation/factures/{$facture['id']}/lignes/{$id}", ['vat_context' => 'care', 'taux_tva' => 0], $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(['care', 8.1], [$this->ligne($apres, 'Pommade cicatrisante')['vat_context'], $this->ligne($apres, 'Pommade cicatrisante')['taux_tva']]);
        $retour = $this->patchJson("/api/v1/facturation/factures/{$facture['id']}/lignes/{$id}", ['vat_context' => 'sale'], $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(2.6, $this->ligne($retour, 'Pommade cicatrisante')['taux_tva']);

        // Une facture sans soin : rien n'y est donné pendant un soin
        $vente = $this->postJson('/api/v1/facturation/factures', ['lignes' => [['libelle' => 'Pommade', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $pommade->id]]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertFalse($vente['has_care']);
        $this->patchJson("/api/v1/facturation/factures/{$vente['id']}/lignes/{$vente['lignes'][0]['id']}", ['vat_context' => 'care'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('vat_context');
    }

    public function test_en_fin_de_consultation_un_medicament_se_dit_donne_pendant_le_soin_ou_remis(): void
    {
        $metacam = $this->metacam();
        $pommade = $this->article('med', 'Pommade cicatrisante');
        $antibio = $this->article('med', 'Amoxicilline 250 mg');

        $facture = $this->finDeConsultation($this->patient(Espece::Chien), ['medicaments' => [
            ['produit_id' => $metacam->id],
            ['produit_id' => $pommade->id],
            ['produit_id' => $antibio->id, 'vat_context' => 'care'],
        ]]);

        $this->assertSame(8.1, $this->ligne($facture, 'Metacam 5 mg/ml')['taux_tva']);
        $this->assertSame(2.6, $this->ligne($facture, 'Pommade cicatrisante')['taux_tva']);
        $this->assertSame(8.1, $this->ligne($facture, 'Amoxicilline 250 mg')['taux_tva']);
    }

    // MOYEN 2 : la déduction d'un acompte reprend le taux de l'acompte

    public function test_la_deduction_de_l_acompte_reprend_le_taux_de_la_facture_d_acompte(): void
    {
        // Un cheval ; l'acompte de 600 émis à 8,1 % (facture d'avant, taux unique figé)
        $cheval = $this->patient(Espece::Cheval);
        $acompte = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $cheval->id, 'species' => Espece::Cheval, 'taux_tva' => 8.1]);
        DB::table('facture_lignes')->insert(['facture_id' => $acompte->id, 'libelle' => 'Acompte 60 %', 'quantite' => 1, 'prix_chf' => 600, 'type' => 'acte', 'created_at' => now(), 'updated_at' => now()]);
        $devis = Devis::factory()->create(['organisation_id' => $this->organisationId(), 'clinique_id' => $this->clinique->id, 'patient_id' => $cheval->id, 'statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now(), 'acompte_pct' => 60, 'acompte_facture_id' => $acompte->id]);
        $devis->lignes()->create(['libelle' => 'Chirurgie : forfait bloc', 'quantite' => 1, 'prix_chf' => 1000, 'type' => 'acte']);

        $finale = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $deduction = collect($finale['lignes'])->first(fn (array $l) => str_starts_with($l['libelle'], 'Acompte déjà facturé'));
        $this->assertSame(8.1, $deduction['taux_tva']);
        // 1000 à 2,6 % (25.34 de TVA) moins 600 à 8,1 % (44.96) : -19.62, et non 10.14
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 974.66, 'vat' => 25.34, 'incl_vat' => 1000],
            ['rate' => 8.1, 'excl_vat' => -555.04, 'vat' => -44.96, 'incl_vat' => -600],
        ], $finale['vat_breakdown']);
        $this->assertSame(-19.62, $finale['montant_tva']);
    }

    // MOYEN 3 : une remise se répartit au prorata des taux

    public function test_une_remise_se_repartit_au_prorata_des_bases_de_chaque_taux(): void
    {
        $chien = $this->patient(Espece::Chien);
        $croquettes = $this->article('ali', 'Croquettes rénales');

        $seule = $this->postJson('/api/v1/facturation/factures', ['patient_id' => $chien->id, 'lignes' => [
            ['libelle' => 'Croquettes rénales', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $croquettes->id],
            ['libelle' => 'Remise fidélité', 'prix_chf' => -10, 'type' => 'acte'],
        ]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame([['rate' => 2.6, 'excl_vat' => 87.72, 'vat' => 2.28, 'incl_vat' => 90]], $seule['vat_breakdown']);
        $this->assertSame(['discount', null], [$this->ligne($seule, 'Remise fidélité')['vat_context'], $this->ligne($seule, 'Remise fidélité')['taux_tva']]);
        $this->assertSame(2.6, $seule['taux_tva']);

        $melee = $this->postJson('/api/v1/facturation/factures', ['patient_id' => $chien->id, 'lignes' => [
            ['libelle' => 'Consultation', 'prix_chf' => 100, 'type' => 'acte'],
            ['libelle' => 'Croquettes rénales', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $croquettes->id],
            ['libelle' => 'Remise fidélité', 'prix_chf' => -20, 'type' => 'acte'],
        ]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 87.72, 'vat' => 2.28, 'incl_vat' => 90],
            ['rate' => 8.1, 'excl_vat' => 83.26, 'vat' => 6.74, 'incl_vat' => 90],
        ], $melee['vat_breakdown']);
        $this->assertSame(9.02, $melee['montant_tva']);
    }

    // Faibles

    public function test_sans_fiche_patient_l_espece_envoyee_ne_compte_pas_pour_le_taux(): void
    {
        $facture = $this->postJson('/api/v1/facturation/factures', ['species' => 'CV', 'lignes' => [['libelle' => 'Consultation', 'prix_chf' => 100, 'type' => 'acte']]], $this->enTetes($this->jeton))
            ->assertCreated()->json('data');

        $this->assertSame(8.1, $facture['lignes'][0]['taux_tva']);
    }

    public function test_une_facture_d_avant_encore_due_reste_a_son_taux_unique_pour_une_ligne_ajoutee(): void
    {
        $ancienne = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'species' => Espece::Chien, 'taux_tva' => 8.1]);
        DB::table('facture_lignes')->insert(['facture_id' => $ancienne->id, 'libelle' => 'Consultation', 'quantite' => 1, 'prix_chf' => 108.10, 'type' => 'acte', 'created_at' => now(), 'updated_at' => now()]);
        $croquettes = $this->article('ali', 'Croquettes rénales');

        $servie = $this->postJson("/api/v1/facturation/factures/{$ancienne->id}/lignes", ['produit_id' => $croquettes->id], $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame(8.1, $this->ligne($servie, 'Croquettes rénales')['taux_tva']);
        $this->assertSame([['rate' => 8.1, 'excl_vat' => 192.51, 'vat' => 15.59, 'incl_vat' => 208.1]], $servie['vat_breakdown']);
    }

    public function test_dans_un_devis_un_medicament_remis_pour_la_maison_suit_le_taux_de_vente(): void
    {
        $chien = $this->patient(Espece::Chien);
        $collerette = $this->article('mat', 'Collerette T45');
        $metacam = $this->metacam();

        $devis = $this->postJson('/api/v1/facturation/devis', ['patient_id' => $chien->id, 'lignes' => [
            ['libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 400, 'type' => 'acte'],
            ['libelle' => 'Collerette T45', 'prix_chf' => 20, 'type' => 'med', 'produit_id' => $collerette->id],
            ['libelle' => 'Metacam 5 mg/ml', 'prix_chf' => 51.30, 'type' => 'med', 'produit_id' => $metacam->id, 'vat_context' => 'sale'],
        ]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame([2.6, 8.1], array_column($devis['vat_breakdown'], 'rate'));
        $this->assertSame('sale', collect($devis['lignes'])->firstWhere('libelle', 'Metacam 5 mg/ml')['vat_context']);

        Devis::withoutGlobalScopes()->whereKey($devis['id'])->update(['statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now()]);
        $finale = $this->postJson("/api/v1/facturation/devis/{$devis['id']}/facturer-finale", [], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame(8.1, $this->ligne($finale, 'Chirurgie : forfait bloc')['taux_tva']);
        $this->assertSame(8.1, $this->ligne($finale, 'Collerette T45')['taux_tva']);
        $this->assertSame(2.6, $this->ligne($finale, 'Metacam 5 mg/ml')['taux_tva']);
    }

    public function test_le_journal_sert_les_taux_en_vigueur_pour_un_import_stable(): void
    {
        // Le journal du mois : « Voir les chiffres » (Jim, 14.11.2026)
        $this->getJson('/api/v1/facturation/factures/journal?mois=2026-11', $this->enTetes($this->employeAvecJeton($this->clinique, [], 'manager')['jeton']))
            ->assertOk()->assertJsonPath('data.vat_rates', [2.6, 8.1]);
    }
}
