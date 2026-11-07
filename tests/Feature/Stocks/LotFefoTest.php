<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\CommandeBoutiqueLigne;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Lot;
use App\Models\LotAllocation;
use App\Models\MouvementStock;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\Stock;
use App\Services\GestionnaireStock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Lots décomptés à chaque sortie, FEFO (décision de Jim, 04.11.2026) :
 * chaque sortie de stock prend dans le lot qui périme le premier, dans la
 * clinique concernée, et continue dans le suivant ; le lot pris est gardé
 * avec la sortie (animal, client) ; une sortie annulée rend la quantité au
 * lot d'où elle venait ; les lots existants sont remis d'accord une fois
 * avec le stock, la règle de secours de la surveillance est retirée ; un
 * stupéfiant suit son registre.
 */
class LotFefoTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin']);
        $this->patient = Patient::factory()->for($client)->create(['organisation_id' => $organisation, 'nom' => 'Pilou']);
    }

    /** Un article, son stock par clinique, et ses lots : [clinique, numéro, péremption ou null, quantité] */
    private function article(array $stocks, array $lots = [], array $attributs = []): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_sur_ordonnance' => false,
            'est_stupefiant' => false,
            ...$attributs,
        ]);
        foreach ($stocks as $cliniqueId => $quantite) {
            Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId, 'quantite' => $quantite]));
        }
        foreach ($lots as [$cliniqueId, $numero, $peremption, $quantite]) {
            Lot::withoutEvents(fn () => Lot::create(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId, 'numero_lot' => $numero, 'peremption_le' => $peremption, 'quantite' => $quantite]));
        }

        return $produit;
    }

    /** @return array<string, int> les lots de l'article dans une clinique, par numéro */
    private function lots(Produit $produit, ?int $cliniqueId = null): array
    {
        return Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $cliniqueId ?? $this->clinique->id)
            ->orderBy('id')->pluck('quantite', 'numero_lot')->map(fn ($q): int => (int) $q)->all();
    }

    private function stockDe(Produit $produit, ?int $cliniqueId = null): int
    {
        return (int) Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $cliniqueId ?? $this->clinique->id)->value('quantite');
    }

    private function ajuster(Produit $produit, int $quantite, string $motif = 'inv'): void
    {
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", ['quantite' => $quantite, 'motif' => $motif], $this->enTetes($this->jeton))->assertOk();
    }

    // ————— Case 1 : chaque sortie prend dans le lot qui périme le premier, dans la clinique concernée —————

    public function test_un_retrait_moins_un_prend_dans_le_lot_qui_perime_le_premier_de_la_clinique(): void
    {
        $ici = $this->clinique->id;
        // Chez la sœur, un lot plus proche encore : il n'est pas de cette clinique, il ne bouge pas
        $produit = $this->article([$ici => 10, $this->soeur->id => 3], [
            [$ici, 'LOT B', '2027-06-01', 5], [$ici, 'LOT A', '2027-01-01', 5], [$this->soeur->id, 'LOT S', '2026-12-01', 3],
        ]);

        $this->ajuster($produit, -1);

        $this->assertSame(['LOT B' => 5, 'LOT A' => 4], $this->lots($produit));
        $this->assertSame(['LOT S' => 3], $this->lots($produit, $this->soeur->id));
    }

    public function test_une_vente_en_caisse_prend_dans_le_lot_qui_perime_le_premier(): void
    {
        $produit = $this->article([$this->clinique->id => 10], [[$this->clinique->id, 'LOT B', '2027-06-01', 5], [$this->clinique->id, 'LOT A', '2027-01-01', 5]], ['categorie' => 'med']);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => $produit->nom, 'type' => 'med', 'produit_id' => $produit->id, 'prix_chf' => 20, 'quantite' => 2]);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'tpe'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(['LOT B' => 5, 'LOT A' => 3], $this->lots($produit));
    }

    public function test_la_boutique_vente_comptoir_et_commande_en_ligne_prennent_dans_le_lot_qui_perime_le_premier(): void
    {
        $produit = $this->article([$this->clinique->id => 10], [[$this->clinique->id, 'LOT B', '2027-06-01', 5], [$this->clinique->id, 'LOT A', '2027-01-01', 5]], ['est_boutique' => true, 'prix' => 30, 'promo_pct' => 0]);

        $this->postJson('/api/v1/boutique/vente', ['client_nom' => 'Client de passage', 'moyen_paiement' => 'cash', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]]], $this->enTetes($this->jeton))->assertCreated();
        $this->assertSame(['LOT B' => 5, 'LOT A' => 3], $this->lots($produit));

        $commande = CommandeBoutique::factory()->create(['clinique_id' => $this->clinique->id]);
        CommandeBoutiqueLigne::factory()->create(['commande_boutique_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]);
        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/preparer", [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/boutique/commandes/{$commande->id}/retirer", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['LOT B' => 5, 'LOT A' => 2], $this->lots($produit));
    }

    public function test_un_soin_ou_un_traitement_donne_prend_dans_le_lot_qui_perime_le_premier(): void
    {
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $pansement = $this->article([$this->clinique->id => 4], [[$this->clinique->id, 'LOT B', '2027-06-01', 2], [$this->clinique->id, 'LOT A', '2027-01-01', 2]]);
        $soin = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", ['prevu_a' => 630, 'acte' => 'Pansement', 'produit_id' => $pansement->id], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin}/faire", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('stock.sorti', true);
        $this->assertSame(['LOT B' => 2, 'LOT A' => 1], $this->lots($pansement));

        // Une dose qui ouvre un flacon : le flacon ouvert sort du lot qui périme le premier
        $flacon = $this->article([$this->clinique->id => 3], [[$this->clinique->id, 'LOT D', '2027-08-01', 2], [$this->clinique->id, 'LOT C', '2027-02-01', 1]], ['dose_unit' => 'ml', 'content_per_unit' => 20]);
        app(ContexteClinique::class)->definir($this->clinique);
        app(GestionnaireStock::class)->takeDose($flacon, 2, 'Soin');
        $this->assertSame(['LOT D' => 2, 'LOT C' => 0], $this->lots($flacon));
    }

    public function test_une_ordonnance_remise_prend_dans_le_lot_qui_perime_le_premier(): void
    {
        $produit = $this->article([$this->clinique->id => 10], [[$this->clinique->id, 'LOT B', '2027-06-01', 5], [$this->clinique->id, 'LOT A', '2027-01-01', 5]], ['categorie' => 'med', 'est_sur_ordonnance' => true]);

        $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 2, 'posologie' => '1 par jour']]], $this->enTetes($this->jeton))->assertCreated();

        $this->assertSame(['LOT B' => 5, 'LOT A' => 3], $this->lots($produit));
    }

    /**
     * La règle sûre : les lots périmés ne sont jamais supposés donnés tant
     * qu'il reste autre chose (ils restent « Périmé » jusqu'à être écartés) ;
     * les lots sans péremption passent après ceux qui en ont une ; le stock
     * sans lot connu passe ensuite ; « Périmé : écarté » prend d'abord les
     * périmés.
     */
    public function test_l_ordre_des_lots_perimes_sans_peremption_et_du_stock_sans_lot(): void
    {
        $ici = $this->clinique->id;
        // 8 en stock : 2 périmés, 2 sans péremption, 2 datés, 2 sans lot connu
        $produit = $this->article([$ici => 8], [[$ici, 'PERIME', '2026-09-01', 2], [$ici, 'SANS DATE', null, 2], [$ici, 'DATE', '2027-03-01', 2]]);

        $this->ajuster($produit, -2);
        $this->assertSame(['PERIME' => 2, 'SANS DATE' => 2, 'DATE' => 0], $this->lots($produit));
        $this->ajuster($produit, -2);
        $this->assertSame(['PERIME' => 2, 'SANS DATE' => 0, 'DATE' => 0], $this->lots($produit));
        $this->ajuster($produit, -2);
        $this->assertSame(['PERIME' => 2, 'SANS DATE' => 0, 'DATE' => 0], $this->lots($produit));
        $this->ajuster($produit, -1);
        $this->assertSame(['PERIME' => 1, 'SANS DATE' => 0, 'DATE' => 0], $this->lots($produit));

        $autre = $this->article([$ici => 4], [[$ici, 'PERIME', '2026-09-01', 2], [$ici, 'DATE', '2027-03-01', 2]]);
        $this->ajuster($autre, -1, 'peremp');
        $this->assertSame(['PERIME' => 1, 'DATE' => 2], $this->lots($autre));
    }

    // ————— Case 2 : une sortie plus grande que le lot le plus proche continue dans le suivant —————

    public function test_une_sortie_plus_grande_que_le_lot_le_plus_proche_continue_dans_le_suivant(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 7], [[$ici, 'LOT B', '2027-06-01', 4], [$ici, 'LOT A', '2027-01-01', 3]]);

        $this->ajuster($produit, -5);

        $this->assertSame(['LOT B' => 2, 'LOT A' => 0], $this->lots($produit));
        $sortie = MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->assertSame([['LOT A', -3], ['LOT B', -2]], LotAllocation::query()->where('mouvement_stock_id', $sortie->id)->orderBy('id')->get()->map(fn (LotAllocation $a): array => [$a->lot_number, $a->quantity])->all());
    }

    // ————— Case 3 : le lot pris est gardé avec la sortie (animal, client) —————

    public function test_le_lot_pris_est_garde_avec_la_sortie_l_animal_et_le_client(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 10], [[$ici, 'LOT B', '2027-06-01', 5], [$ici, 'LOT A', '2027-01-01', 2]], ['categorie' => 'med', 'est_sur_ordonnance' => true]);

        $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 3, 'posologie' => '1 par jour']]], $this->enTetes($this->jeton))->assertCreated();

        $prises = LotAllocation::query()->orderBy('id')->get();
        $this->assertSame([['LOT A', '2027-01-01', -2], ['LOT B', '2027-06-01', -1]], $prises->map(fn (LotAllocation $a): array => [$a->lot_number, $a->expires_on->toDateString(), $a->quantity])->all());
        $this->assertSame([$this->patient->id], $prises->pluck('patient_id')->unique()->values()->all());
        $this->assertSame([$this->patient->client_id], $prises->pluck('client_id')->unique()->values()->all());

        // Le journal de l'article le dit
        $this->getJson("/api/v1/stocks/produits/{$produit->id}/mouvements", $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.0.lots.0.numero_lot', 'LOT A')
            ->assertJsonPath('data.0.lots.0.peremption', '01.2027')
            ->assertJsonPath('data.0.lots.0.quantite', -2)
            ->assertJsonPath('data.0.lots.1.numero_lot', 'LOT B');

        // Et le lot dit à quel animal, et à quel client, il a été donné
        $lotA = Lot::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole();
        $this->getJson("/api/v1/stocks/lots/{$lotA->id}/sorties", $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.0.quantite', 2)
            ->assertJsonPath('data.0.patient.nom', 'Pilou')
            ->assertJsonPath('data.0.client.nom', $this->patient->client->nom_affiche)
            ->assertJsonPath('data.0.libelle', fn (string $libelle): bool => str_starts_with($libelle, 'Sortie : ordonnance RX-'));
    }

    public function test_une_vente_garde_le_client_et_le_journal_se_lit_sans_requete_par_ligne(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 10], [[$ici, 'LOT A', '2027-01-01', 10]], ['categorie' => 'med']);
        $facture = Facture::factory()->create(['clinique_id' => $ici, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => $produit->nom, 'type' => 'med', 'produit_id' => $produit->id, 'prix_chf' => 20, 'quantite' => 1]);
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'tpe'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([$this->patient->client_id], LotAllocation::query()->pluck('client_id')->all());

        for ($i = 0; $i < 4; $i++) {
            $this->ajuster($produit, -1);
        }
        DB::enableQueryLog();
        $this->getJson("/api/v1/stocks/produits/{$produit->id}/mouvements", $this->enTetes($this->jeton))->assertOk()->assertJsonCount(5, 'data');
        $lectures = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'lot_allocations'))->count();
        $this->assertSame(1, $lectures);
    }

    // ————— Case 4 : une sortie annulée rend la quantité au lot d'où elle venait —————

    public function test_une_vente_annulee_rend_la_quantite_aux_lots_d_ou_elle_venait(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 7], [[$ici, 'LOT B', '2027-06-01', 4], [$ici, 'LOT A', '2027-01-01', 3]], ['categorie' => 'med']);
        $id = $this->postJson('/api/v1/facturation/factures', ['patient_name' => 'Rex', 'lignes' => [['libelle' => 'Article', 'prix_chf' => 1, 'type' => 'acte', 'produit_id' => $produit->id, 'quantite' => 5]]], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/facturation/factures/{$id}/payer-plus-tard", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['LOT B' => 2, 'LOT A' => 0], $this->lots($produit));

        $this->deleteJson("/api/v1/facturation/factures/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame(['LOT B' => 4, 'LOT A' => 3], $this->lots($produit));
        $this->assertSame(7, $this->stockDe($produit));
    }

    public function test_une_ordonnance_annulee_rend_au_lot_meme_ecarte_entre_temps(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 6], [[$ici, 'LOT B', '2027-06-01', 4], [$ici, 'LOT A', '2027-01-01', 2]], ['categorie' => 'med', 'est_sur_ordonnance' => true]);
        $id = $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'posologie' => '1 par jour']]], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->assertSame(['LOT B' => 4, 'LOT A' => 1], $this->lots($produit));

        // Le lot A est écarté entre-temps : la quantité rendue est de ce lot, il revient avec elle
        $lotA = Lot::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole();
        $this->postJson("/api/v1/stocks/lots/{$lotA->id}/ecarter", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame(['LOT B' => 4], $this->lots($produit));

        $this->deleteJson("/api/v1/ordonnances/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame(['LOT B' => 4, 'LOT A' => 1], $this->lots($produit));
        $this->assertSame('2027-01-01', Lot::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole()->peremption_le->toDateString());
        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_un_soin_retire_rend_au_lot_d_ou_il_venait(): void
    {
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $produit = $this->article([$this->clinique->id => 4], [[$this->clinique->id, 'LOT B', '2027-06-01', 3], [$this->clinique->id, 'LOT A', '2027-01-01', 1]]);
        $soin = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins", ['prevu_a' => 630, 'acte' => 'Pansement', 'produit_id' => $produit->id], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin}/faire", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['LOT B' => 3, 'LOT A' => 0], $this->lots($produit));
        $this->assertSame([$this->patient->id], LotAllocation::query()->pluck('patient_id')->all());

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$soin}/annuler", [], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(['LOT B' => 3, 'LOT A' => 1], $this->lots($produit));
    }

    public function test_un_plus_un_rend_au_lot_d_ou_venait_le_moins_un_et_une_reception_manuelle_reste_sans_lot(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->article([$ici => 6], [[$ici, 'LOT B', '2027-06-01', 5], [$ici, 'LOT A', '2027-01-01', 1]]);
        $this->ajuster($produit, -2);
        $this->assertSame(['LOT B' => 4, 'LOT A' => 0], $this->lots($produit));

        $this->ajuster($produit, 1);
        // Le dernier pris revient d'abord : celui de B
        $this->assertSame(['LOT B' => 5, 'LOT A' => 0], $this->lots($produit));
        $this->ajuster($produit, 1);
        $this->assertSame(['LOT B' => 5, 'LOT A' => 1], $this->lots($produit));

        // Plus rien à rendre : le +1 est du stock sans lot connu ; une réception manuelle aussi
        $this->ajuster($produit, 1);
        $this->ajuster($produit, 3, 'recep');
        $this->assertSame(['LOT B' => 5, 'LOT A' => 1], $this->lots($produit));
        $this->assertSame(10, $this->stockDe($produit));
    }

    // ————— Case 5 : les lots existants remis d'accord une fois, la règle de secours retirée —————

    private function remettreDAccord(): void
    {
        (require database_path('migrations/2026_11_06_000502_reconcile_lots_with_stock.php'))->up();
    }

    public function test_les_lots_existants_sont_remis_d_accord_une_fois_avec_le_stock_les_plus_anciens_supposes_partis(): void
    {
        $ici = $this->clinique->id;
        // 5 en stock pour 17 en lots : le périmé et une partie du lot de 2027-06 sont partis
        $vendu = $this->article([$ici => 5], [[$ici, 'L2028', '2028-01-01', 4], [$ici, 'L2027', '2027-06-01', 3], [$ici, 'L2026', '2026-10-01', 10]]);
        // Sans péremption : supposés partis après ceux qui en ont une
        $mixte = $this->article([$ici => 6], [[$ici, 'SANS DATE', null, 4], [$ici, 'DATE', '2027-01-01', 5]]);
        // Stock à zéro : plus aucun lot ; stock qui couvre ses lots : rien ne change ; la sœur à part
        $epuise = $this->article([$ici => 0, $this->soeur->id => 3], [[$ici, 'VIDE', '2026-10-01', 6], [$this->soeur->id, 'SOEUR', '2027-01-01', 3]]);
        $couvert = $this->article([$ici => 12], [[$ici, 'COUVERT', '2027-01-01', 8]]);

        $this->remettreDAccord();

        $this->assertSame(['L2028' => 4, 'L2027' => 1, 'L2026' => 0], $this->lots($vendu));
        $this->assertSame(['SANS DATE' => 4, 'DATE' => 2], $this->lots($mixte));
        $this->assertSame(['VIDE' => 0], $this->lots($epuise));
        $this->assertSame(['SOEUR' => 3], $this->lots($epuise, $this->soeur->id));
        $this->assertSame(['COUVERT' => 8], $this->lots($couvert));
        // Chaque lot retouché garde la trace de ce qui lui a été retiré
        $this->assertSame(
            [['L2026', -10], ['L2027', -2], ['DATE', -3], ['VIDE', -6]],
            LotAllocation::query()->where('kind', 'reconciliation')->orderBy('id')->get()->map(fn (LotAllocation $a): array => [$a->lot_number, $a->quantity])->all(),
        );

        // Une seule fois : relancée, elle ne change plus rien
        $this->remettreDAccord();
        $this->assertSame(['L2028' => 4, 'L2027' => 1, 'L2026' => 0], $this->lots($vendu));
        $this->assertSame(4, LotAllocation::query()->where('kind', 'reconciliation')->count());
    }

    /** La règle de secours est retirée : la surveillance lit les lots tels qu'ils sont, décomptés */
    public function test_la_surveillance_lit_les_lots_tels_qu_ils_sont_sans_regle_de_secours(): void
    {
        $ici = $this->clinique->id;
        // 2 en stock, un lot de 2028 (4) et un périmé (3) : la règle de secours supposait le périmé parti
        $produit = $this->article([$ici => 2], [[$ici, 'L2028', '2028-01-01', 4], [$ici, 'L2026', '2026-10-01', 3]]);
        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['date' => '10.2026', 'etat' => 'perime'], collect($reponse->json('data.produits'))->firstWhere('id', $produit->id)['peremption'][(string) $ici]);

        // Vendu jusqu'au dernier : plus « Périmé »
        $dernier = $this->article([$ici => 3], [[$ici, 'L2026', '2026-10-01', 3]]);
        $this->ajuster($dernier, -3);
        $reponse = $this->getJson('/api/v1/stocks/surveillance', $this->enTetes($this->jeton))->assertOk();
        $this->assertNull(collect($reponse->json('data.produits'))->firstWhere('id', $dernier->id)['peremption'][(string) $ici]);
        // Un lot vide ne s'affiche plus dans la liste des lots
        $this->assertNotContains($dernier->id, collect($this->getJson('/api/v1/stocks/lots', $this->enTetes($this->jeton))->assertOk()->json('data'))->pluck('produit_id')->all());
    }

    // ————— Case 6 : un stupéfiant suit son registre, son lot est celui inscrit au registre —————

    private function substance(int $stock, array $lots): Produit
    {
        $produit = $this->article([$this->clinique->id => $stock], $lots, ['est_stupefiant' => true, 'tableau_stupefiant' => 'b', 'unite' => 'ml']);
        MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => $stock]);

        return $produit;
    }

    private function inscrire(Produit $produit, array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, ...$donnees], $this->enTetes($this->jeton));
    }

    public function test_un_stupefiant_suit_son_registre_son_lot_est_celui_inscrit(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->substance(20, [[$ici, 'LOT B', '2027-06-01', 10], [$ici, 'LOT A', '2027-01-01', 10]]);

        // Sans lot choisi : celui qui périme le premier est inscrit, et c'est de lui que le stock sort
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 3, 'patient_id' => $this->patient->id])->assertCreated()
            ->assertJsonPath('data.numero_lot', 'LOT A');
        $this->assertSame(['LOT B' => 10, 'LOT A' => 7], $this->lots($produit));

        // Un lot choisi au registre : c'est lui qui sort
        $lotB = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->where('numero_lot', 'LOT B')->sole();
        $ligne = $this->inscrire($produit, ['type' => 'perte', 'quantite' => 2, 'motif' => 'Flacon cassé', 'lot_id' => $lotB->id])->assertCreated()
            ->assertJsonPath('data.numero_lot', 'LOT B')->assertJsonPath('data.lot_id', $lotB->id)->json('data');
        $this->assertSame(['LOT B' => 8, 'LOT A' => 7], $this->lots($produit));
        $this->assertSame(['LOT B'], LotAllocation::query()->whereHas('mouvement', fn ($m) => $m->where('source_type', (new MouvementStupefiant)->getMorphClass())->where('source_id', $ligne['id']))->pluck('lot_number')->all());

        // Corrigée, la ligne rend à son lot
        $this->inscrire($produit, ['type' => 'correction', 'quantite' => 2, 'corrige_id' => $ligne['id'], 'motif' => 'Erreur de saisie'])->assertCreated()
            ->assertJsonPath('data.numero_lot', 'LOT B');
        $this->assertSame(['LOT B' => 10, 'LOT A' => 7], $this->lots($produit));

        // Un lot d'un autre article est refusé
        $autre = $this->article([$ici => 1], [[$ici, 'AUTRE', '2027-01-01', 1]]);
        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 1, 'motif' => 'Perte', 'lot_id' => Lot::withoutGlobalScopes()->where('produit_id', $autre->id)->value('id')])
            ->assertUnprocessable()->assertJsonValidationErrors('lot_id');
    }

    public function test_un_stupefiant_ne_sort_de_ses_lots_que_par_son_registre(): void
    {
        $ici = $this->clinique->id;
        $produit = $this->substance(10, [[$ici, 'LOT A', '2027-01-01', 10]]);
        app(ContexteClinique::class)->definir($this->clinique);

        $this->assertSame(0, app(GestionnaireStock::class)->sortir($produit, 2, 'Vente'));

        $this->assertSame(['LOT A' => 10], $this->lots($produit));
        $this->assertSame(0, LotAllocation::query()->count());
    }

    public function test_le_lot_d_un_stupefiant_recu_ou_ecarte_est_inscrit_au_registre(): void
    {
        $produit = $this->article([], [], ['est_stupefiant' => true, 'tableau_stupefiant' => 'b', 'peremption_le' => '2027-09-01']);
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 10]);

        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", ['bulletin' => 'BL-1', 'bulletin_le' => now()->subDay()->toDateString()], $this->enTetes($this->jeton))->assertOk();

        $lot = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $reception = MouvementStupefiant::withoutGlobalScopes()->where('type', 'reception')->sole();
        $this->assertSame([$lot->id, $lot->numero_lot], [$reception->lot_id, $reception->numero_lot]);
        $this->assertSame(10, (int) $lot->quantite);
        $this->assertSame(10, $this->stockDe($produit));

        $this->postJson("/api/v1/stocks/lots/{$lot->id}/ecarter", ['elimination' => 'Remis au pharmacien cantonal'], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame($lot->numero_lot, MouvementStupefiant::withoutGlobalScopes()->where('type', 'elimination')->sole()->numero_lot);
        $this->assertSame(0, $this->stockDe($produit));
    }
}
