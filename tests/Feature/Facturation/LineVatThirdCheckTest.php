<?php

namespace Tests\Feature\Facturation;

use App\Enums\Espece;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * TVA par ligne, vérification ciblée (04.11.2026) : l'acompte d'un devis
 * à deux taux se répartit sur ses taux, et la facture finale déduit
 * chaque part à son taux ; un acte offert est un soin ; sans soin, un
 * article n'est plus « donné pendant le soin ».
 */
class LineVatThirdCheckTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
    }

    private function article(string $categorie, string $nom, array $plus = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'categorie' => $categorie, 'nom' => $nom, 'prix' => 100,
            'est_sur_ordonnance' => false, 'est_stupefiant' => false, 'dose_unit' => null, 'content_per_unit' => null, ...$plus,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);

        return $produit;
    }

    /**
     * Le devis accepté, son acompte puis sa facture finale.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function devisAcompteEtFinale(Espece $espece, array $lignes, int $remise, int $acompte): array
    {
        $patient = Patient::factory()->for($this->client)->create(['espece' => $espece, 'race' => 'Croisé']);
        $devis = $this->postJson('/api/v1/facturation/devis', ['patient_id' => $patient->id, 'remise_pct' => $remise, 'acompte_pct' => $acompte, 'lignes' => $lignes], $this->enTetes($this->jeton))
            ->assertCreated()->json('data');
        Devis::withoutGlobalScopes()->whereKey($devis['id'])->update(['statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now()]);

        $facturesAcompte = $this->postJson("/api/v1/facturation/devis/{$devis['id']}/facturer-acompte", [], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $finale = $this->postJson("/api/v1/facturation/devis/{$devis['id']}/facturer-finale", [], $this->enTetes($this->jeton))->assertCreated()->json('data');

        return [$devis, $facturesAcompte, $finale];
    }

    public function test_l_acompte_d_un_devis_a_deux_taux_se_repartit_et_la_finale_deduit_chaque_part_a_son_taux(): void
    {
        // Un chien : chirurgie 100 (8,1 %), croquettes remises 900 (2,6 %), remise 10 %, acompte 50 %
        $croquettes = $this->article('ali', 'Croquettes rénales');
        [$devis, $acompte, $finale] = $this->devisAcompteEtFinale(Espece::Chien, [
            ['libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 100, 'type' => 'acte'],
            ['libelle' => 'Croquettes rénales', 'prix_chf' => 900, 'type' => 'med', 'produit_id' => $croquettes->id, 'vat_context' => 'sale'],
        ], 10, 50);

        // Le devis : 90 à 8,1 % (6.74) et 810 à 2,6 % (20.53), remise répartie
        $this->assertSame(27.27, $devis['montant_tva']);

        // L'acompte de 450 : 405 à 2,6 % et 45 à 8,1 %, une ligne par taux
        $this->assertSame(450, $acompte['total_ttc']);
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 394.74, 'vat' => 10.26, 'incl_vat' => 405],
            ['rate' => 8.1, 'excl_vat' => 41.63, 'vat' => 3.37, 'incl_vat' => 45],
        ], $acompte['vat_breakdown']);
        $this->assertSame([2.6, 8.1], array_column($acompte['lignes'], 'taux_tva'));
        $this->assertSame(13.63, $acompte['montant_tva']);

        // La finale déduit chaque part à son taux : sa TVA est positive, la somme est celle du devis
        $deductions = collect($finale['lignes'])->filter(fn (array $l) => str_starts_with($l['libelle'], 'Acompte déjà facturé'));
        $this->assertSame([[-405, 2.6], [-45, 8.1]], $deductions->map(fn (array $l) => [$l['prix_chf'], $l['taux_tva']])->values()->all());
        $this->assertSame([
            ['rate' => 2.6, 'excl_vat' => 394.73, 'vat' => 10.27, 'incl_vat' => 405],
            ['rate' => 8.1, 'excl_vat' => 41.63, 'vat' => 3.37, 'incl_vat' => 45],
        ], $finale['vat_breakdown']);
        $this->assertSame(13.64, $finale['montant_tva']);
        $this->assertSame($devis['montant_tva'], round($acompte['montant_tva'] + $finale['montant_tva'], 2));
    }

    public function test_le_cas_inverse_un_cheval_avec_un_article_au_taux_normal(): void
    {
        // Un cheval : chirurgie 1000 (2,6 %), collerette remise 100 (8,1 %), acompte 30 %
        $collerette = $this->article('mat', 'Collerette T45');
        [$devis, $acompte, $finale] = $this->devisAcompteEtFinale(Espece::Cheval, [
            ['libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 1000, 'type' => 'acte'],
            ['libelle' => 'Collerette T45', 'prix_chf' => 100, 'type' => 'med', 'produit_id' => $collerette->id, 'vat_context' => 'sale'],
        ], 0, 30);

        $this->assertSame(32.83, $devis['montant_tva']);
        $this->assertSame([[2.6, 300, 7.6], [8.1, 30, 2.25]], array_map(fn (array $r) => [$r['rate'], $r['incl_vat'], $r['vat']], $acompte['vat_breakdown']));
        $this->assertSame([[2.6, 700, 17.74], [8.1, 70, 5.24]], array_map(fn (array $r) => [$r['rate'], $r['incl_vat'], $r['vat']], $finale['vat_breakdown']));
        $this->assertGreaterThan(0, $acompte['montant_tva']);
        $this->assertGreaterThan(0, $finale['montant_tva']);
        $this->assertSame($devis['montant_tva'], round($acompte['montant_tva'] + $finale['montant_tva'], 2));
    }

    public function test_un_acte_offert_est_un_soin(): void
    {
        $pommade = $this->article('med', 'Pommade cicatrisante');
        $facture = $this->postJson('/api/v1/facturation/factures', ['lignes' => [
            ['libelle' => 'Contrôle offert', 'prix_chf' => 0, 'type' => 'acte'],
            ['libelle' => 'Pommade cicatrisante', 'prix_chf' => 1, 'type' => 'med', 'produit_id' => $pommade->id],
        ]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertTrue($facture['has_care']);

        $ligne = collect($facture['lignes'])->firstWhere('libelle', 'Pommade cicatrisante');
        $this->patchJson("/api/v1/facturation/factures/{$facture['id']}/lignes/{$ligne['id']}", ['vat_context' => 'care'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.lignes.1.taux_tva', 8.1);
    }

    public function test_sans_plus_aucun_soin_l_article_donne_repasse_en_remis(): void
    {
        $chien = Patient::factory()->for($this->client)->create(['espece' => Espece::Chien, 'race' => 'Croisé']);
        $rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Consultation, 'status' => StatutRendezVous::Cours,
            'patient_id' => $chien->id, 'client_id' => $chien->client_id, 'patient_name' => $chien->nom, 'species' => $chien->espece,
        ]);
        $consult = Prestation::withoutGlobalScopes()->where('organisation_id', $this->clinique->societe->organisation_id)->where('code', 'consult')->sole();
        $facture = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", ['prestations' => [$consult->id]], $this->enTetes($this->jeton))->assertOk()->json('facture');
        $metacam = $this->article('med', 'Metacam 5 mg/ml', ['dose_unit' => 'ml', 'content_per_unit' => 20]);
        $servie = $this->postJson("/api/v1/facturation/factures/{$facture['id']}/lignes", ['produit_id' => $metacam->id], $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(['care', 8.1], [$servie['lignes'][1]['vat_context'], $servie['lignes'][1]['taux_tva']]);

        $apres = $this->deleteJson("/api/v1/facturation/factures/{$facture['id']}/lignes/{$servie['lignes'][0]['id']}", [], $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertFalse($apres['has_care']);
        $this->assertSame(['sale', 2.6], [$apres['lignes'][0]['vat_context'], $apres['lignes'][0]['taux_tva']]);
    }
}
