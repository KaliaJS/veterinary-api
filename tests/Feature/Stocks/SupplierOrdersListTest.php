<?php

namespace Tests\Feature\Stocks;

use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Organisation;
use App\Models\Produit;
use App\Models\Societe;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'onglet Commandes en « courrier » (proposition A, 03.11.2026) : la liste
 * filtrée par plusieurs statuts et cherchée par le serveur (numéro,
 * fournisseur, article), les reçues triées par date de réception, les
 * totaux servis, les titres de mois, l'aperçu du stock bas et la
 * préparation des brouillons choisis.
 */
class SupplierOrdersListTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function produit(array $attributs = []): Produit
    {
        return Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            ...$attributs,
        ]);
    }

    private function commande(StatutCommandeFournisseur $statut, array $attributs = [], ?Clinique $clinique = null): CommandeFournisseur
    {
        return CommandeFournisseur::factory()->create([
            'clinique_id' => ($clinique ?? $this->clinique)->id,
            'statut' => $statut,
            'envoyee_le' => $statut === StatutCommandeFournisseur::Brouillon ? null : now()->subDays(5),
            ...$attributs,
        ]);
    }

    private function ligne(CommandeFournisseur $commande, Produit $produit, int $quantite, float $cout): void
    {
        CommandeFournisseurLigne::factory()->create([
            'commande_fournisseur_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => $quantite,
            'cout' => $cout,
        ]);
    }

    /** @return list<int> */
    private function ids(string $requete): array
    {
        return collect($this->getJson('/api/v1/stocks/commandes'.$requete, $this->enTetes($this->jeton))->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_la_liste_accepte_plusieurs_statuts(): void
    {
        $brouillon = $this->commande(StatutCommandeFournisseur::Brouillon);
        $envoyee = $this->commande(StatutCommandeFournisseur::Envoyee);
        $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()->subDay()]);

        $this->assertEqualsCanonicalizing([$brouillon->id, $envoyee->id], $this->ids('?statut=draft,sent'));
        // Les noms en français aussi : brouillon, envoyée, reçue
        $this->assertEqualsCanonicalizing([$brouillon->id, $envoyee->id], $this->ids('?statut=brouillon,envoyee'));
        $this->assertCount(3, $this->ids(''));

        $this->getJson('/api/v1/stocks/commandes?statut=perdue', $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_les_recues_sont_triees_par_reception_les_autres_par_numero_d_ordre(): void
    {
        // Reçue il y a longtemps mais créée après : l'ordre des id la mettrait devant
        $recente = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()->subDays(2)]);
        $ancienne = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()->subDays(40)]);
        $brouillon1 = $this->commande(StatutCommandeFournisseur::Brouillon);
        $brouillon2 = $this->commande(StatutCommandeFournisseur::Brouillon);

        $this->assertSame([$recente->id, $ancienne->id], $this->ids('?statut=rec'));
        $this->assertSame([$brouillon2->id, $brouillon1->id], $this->ids('?statut=draft'));
        // Tout ensemble : les ouvertes d'abord, puis les reçues de la plus récente à la plus ancienne
        $this->assertSame([$brouillon2->id, $brouillon1->id, $recente->id, $ancienne->id], $this->ids(''));
    }

    public function test_la_pagination_reste(): void
    {
        foreach (range(1, 25) as $jour) {
            $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()->subDays($jour)]);
        }

        $page = $this->getJson('/api/v1/stocks/commandes?statut=rec&per_page=20&page=2', $this->enTetes($this->jeton))->assertOk();

        $page->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 2);
    }

    public function test_la_recherche_trouve_par_numero_fournisseur_et_article_meme_ancienne(): void
    {
        $methadone = $this->produit(['nom' => 'Méthadone 10 mg/ml', 'reference' => 'MED-0910']);
        $gants = $this->produit(['nom' => 'Gants nitrile M', 'reference' => 'MAT-0233']);

        // Reçue il y a trois ans : elle se trouve quand même
        $vieille = $this->commande(StatutCommandeFournisseur::Receptionnee, ['numero' => 'BC-2023-777', 'fournisseur' => 'Streuli Pharma', 'receptionnee_le' => now()->subYears(3)]);
        $this->ligne($vieille, $methadone, 2, 18.6);
        $autre = $this->commande(StatutCommandeFournisseur::Brouillon, ['numero' => 'BC-2026-901', 'fournisseur' => 'Vetopharm SA']);
        $this->ligne($autre, $gants, 1, 8.9);

        $this->assertSame([$vieille->id], $this->ids('?q=777'));
        $this->assertSame([$vieille->id], $this->ids('?q=streuli'));
        $this->assertSame([$vieille->id], $this->ids('?q=Méthadone'));
        $this->assertSame([$vieille->id], $this->ids('?q=med-0910'));
        $this->assertSame([$autre->id], $this->ids('?q=nitrile'));
        $this->assertSame([], $this->ids('?q=introuvable'));
        // Avec un statut : les deux filtres ensemble
        $this->assertSame([], $this->ids('?q=777&statut=draft'));
    }

    public function test_la_recherche_ne_sort_pas_de_la_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->commande(StatutCommandeFournisseur::Brouillon, ['fournisseur' => 'Streuli Pharma'], $soeur);

        $this->assertSame([], $this->ids('?q=streuli'));
    }

    public function test_la_commande_sert_son_total_son_nombre_de_lignes_et_son_mois(): void
    {
        // Reçue le 31 octobre à 23 h 30 UTC : déjà le 1er novembre à Zurich
        $commande = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => CarbonImmutable::parse('2026-10-31 23:30:00', 'UTC')]);
        $this->ligne($commande, $this->produit(), 2, 21.5);
        $this->ligne($commande, $this->produit(), 3, 7.9);
        $this->ligne($commande, $this->produit(), 3, 0.1);

        $donnees = $this->getJson('/api/v1/stocks/commandes', $this->enTetes($this->jeton))->assertOk()->json('data.0');

        // 2 × 21,50 + 3 × 7,90 + 3 × 0,10 = 67,00 (au centime, jamais 66.99999)
        $this->assertSame(67, $donnees['total_chf']);
        $this->assertSame(3, $donnees['nombre_lignes']);
        $this->assertSame('2026-11', $donnees['mois']);
        $this->assertEquals(43, $donnees['lignes'][0]['total_chf']);
        $this->assertEqualsWithDelta(23.7, $donnees['lignes'][1]['total_chf'], 0.0001);
        $this->assertNotNull($donnees['creee_le']);

        $brouillon = $this->commande(StatutCommandeFournisseur::Brouillon);
        $vide = collect($this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data'))->firstWhere('id', $brouillon->id);
        $this->assertSame(0, $vide['total_chf']);
        $this->assertSame(0, $vide['nombre_lignes']);
        $this->assertNull($vide['mois']);
    }

    public function test_les_titres_de_mois_donnent_le_nombre_et_le_total_des_recues(): void
    {
        $produit = $this->produit();
        $octobre1 = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC')]);
        $this->ligne($octobre1, $produit, 2, 10.05);
        $octobre2 = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => CarbonImmutable::parse('2026-10-31 23:30:00', 'UTC')]);
        $this->ligne($octobre2, $produit, 1, 5);
        // Le 30 septembre à 23 h 30 UTC : le 1er octobre à Zurich
        $septembre = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC')]);
        $this->ligne($septembre, $produit, 3, 0.1);
        $aout = $this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => CarbonImmutable::parse('2026-08-12 08:00:00', 'UTC')]);
        $this->ligne($aout, $produit, 4, 2.4);
        // Ni les envoyées, ni les reçues d'une clinique sœur
        $this->ligne($this->commande(StatutCommandeFournisseur::Envoyee), $produit, 9, 100);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->ligne($this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()], $soeur), $produit, 9, 100);

        $mois = $this->getJson('/api/v1/stocks/commandes/mois', $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([
            ['mois' => '2026-11', 'nombre' => 1, 'total_chf' => 5],
            ['mois' => '2026-10', 'nombre' => 2, 'total_chf' => 20.4],
            ['mois' => '2026-08', 'nombre' => 1, 'total_chf' => 9.6],
        ], $mois);
    }

    public function test_le_stock_bas_se_montre_par_fournisseur_sans_rien_creer(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $croquettes = $this->produit(['nom' => 'Croquettes rénales', 'reference' => 'ALI-0311', 'fournisseur' => 'Vetpoint', 'seuil_alerte' => 4, 'cout' => 21.5]);
        Stock::factory()->create(['produit_id' => $croquettes->id, 'clinique_id' => $this->clinique->id, 'quantite' => 1]);
        $meloxicam = $this->produit(['nom' => 'Meloxicam', 'reference' => 'MED-0114', 'fournisseur' => 'Vetopharm SA', 'seuil_alerte' => 5, 'cout' => 13.4]);
        Stock::factory()->create(['produit_id' => $meloxicam->id, 'clinique_id' => $this->clinique->id, 'quantite' => 9]);
        Stock::factory()->create(['produit_id' => $meloxicam->id, 'clinique_id' => $soeur->id, 'quantite' => 0]);
        $seringues = $this->produit(['nom' => 'Seringues 2 ml', 'reference' => 'MAT-0201', 'fournisseur' => null, 'seuil_alerte' => 3, 'cout' => 11.5]);
        Stock::factory()->create(['produit_id' => $seringues->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);
        // Couvert par une commande en cours : pas proposé
        $couvert = $this->produit(['fournisseur' => 'Vetpoint', 'seuil_alerte' => 4]);
        Stock::factory()->create(['produit_id' => $couvert->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);
        $this->ligne($this->commande(StatutCommandeFournisseur::Envoyee), $couvert, 4, 1);

        $groupes = $this->getJson('/api/v1/stocks/commandes/stock-bas', $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([
            ['fournisseur' => 'Interne', 'articles' => [
                ['produit_id' => $seringues->id, 'nom' => 'Seringues 2 ml', 'reference' => 'MAT-0201', 'en_stock' => 2, 'seuil' => 3, 'quantite_proposee' => 4, 'cout' => 11.5],
            ]],
            ['fournisseur' => 'Vetpoint', 'articles' => [
                // max(2 × 4 − 1, 4) = 7
                ['produit_id' => $croquettes->id, 'nom' => 'Croquettes rénales', 'reference' => 'ALI-0311', 'en_stock' => 1, 'seuil' => 4, 'quantite_proposee' => 7, 'cout' => 21.5],
            ]],
        ], $groupes);
        $this->assertSame(1, CommandeFournisseur::query()->count());
    }

    public function test_le_stock_bas_vide_rend_une_liste_vide(): void
    {
        $this->getJson('/api/v1/stocks/commandes/stock-bas', $this->enTetes($this->jeton))->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_generer_avec_les_lignes_choisies_seulement_aux_quantites_donnees(): void
    {
        $croquettes = $this->produit(['fournisseur' => 'Vetpoint', 'seuil_alerte' => 4, 'cout' => 21.5]);
        Stock::factory()->create(['produit_id' => $croquettes->id, 'clinique_id' => $this->clinique->id, 'quantite' => 1]);
        $bandes = $this->produit(['fournisseur' => 'Covetrus', 'seuil_alerte' => 10, 'cout' => 2.4]);
        Stock::factory()->create(['produit_id' => $bandes->id, 'clinique_id' => $this->clinique->id, 'quantite' => 4]);
        $chlorhexidine = $this->produit(['fournisseur' => 'Covetrus', 'seuil_alerte' => 3, 'cout' => 7.9]);
        Stock::factory()->create(['produit_id' => $chlorhexidine->id, 'clinique_id' => $this->clinique->id, 'quantite' => 1]);
        // Écarté par la case : il reste sous le seuil, sans brouillon
        $ecarte = $this->produit(['fournisseur' => 'Covetrus', 'seuil_alerte' => 3]);
        Stock::factory()->create(['produit_id' => $ecarte->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);

        $reponse = $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [
            ['produit_id' => $croquettes->id, 'quantite' => 3],
            ['produit_id' => $bandes->id, 'quantite' => 16],
            ['produit_id' => $chlorhexidine->id, 'quantite' => 5],
        ]], $this->enTetes($this->jeton));

        $reponse->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.fournisseur', 'Covetrus')
            ->assertJsonPath('data.0.statut', 'draft')
            ->assertJsonPath('data.0.nombre_lignes', 2)
            ->assertJsonPath('data.1.fournisseur', 'Vetpoint');
        $covetrus = CommandeFournisseur::query()->where('fournisseur', 'Covetrus')->sole();
        $this->assertSame([$bandes->id => 16, $chlorhexidine->id => 5], $covetrus->lignes()->orderBy('produit_id')->pluck('quantite', 'produit_id')->all());
        $this->assertSame(3, CommandeFournisseur::query()->where('fournisseur', 'Vetpoint')->sole()->lignes()->sole()->quantite);
        $this->assertSame('21.50', CommandeFournisseur::query()->where('fournisseur', 'Vetpoint')->sole()->lignes()->sole()->cout);
        $this->assertFalse(CommandeFournisseurLigne::query()->where('produit_id', $ecarte->id)->exists());
    }

    public function test_generer_avec_lignes_refuse_un_article_d_ailleurs_ou_une_quantite_hors_bornes(): void
    {
        $ici = $this->produit(['fournisseur' => 'Vetpoint']);
        $ailleurs = Produit::factory()->create(['organisation_id' => Organisation::factory()->create()->id]);

        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [['produit_id' => $ailleurs->id, 'quantite' => 1]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');
        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [['produit_id' => $ici->id, 'quantite' => 0]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');
        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [['produit_id' => $ici->id, 'quantite' => 100000]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');
        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => []], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');

        $this->assertSame(0, CommandeFournisseur::query()->count());
    }

    /** Deux clics sur « Préparer » ne doublent rien : un article déjà sur une commande en cours est refusé */
    public function test_generer_avec_lignes_refuse_un_article_deja_en_commande(): void
    {
        $produit = $this->produit(['nom' => 'Bandes cohésives', 'fournisseur' => 'Covetrus']);
        $this->ligne($this->commande(StatutCommandeFournisseur::Brouillon), $produit, 1, 2.4);

        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [['produit_id' => $produit->id, 'quantite' => 4]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.produit_id');

        $this->assertSame(1, CommandeFournisseur::query()->count());
    }

    public function test_une_autre_organisation_ne_voit_ni_les_mois_ni_le_stock_bas_d_ici(): void
    {
        $autre = Clinique::factory()->create(['societe_id' => Societe::factory()->create()->id]);
        ['jeton' => $jetonAutre] = $this->employeAvecJeton($autre);
        $this->ligne($this->commande(StatutCommandeFournisseur::Receptionnee, ['receptionnee_le' => now()]), $this->produit(), 1, 10);
        $bas = $this->produit(['seuil_alerte' => 4]);
        Stock::factory()->create(['produit_id' => $bas->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);

        $this->getJson('/api/v1/stocks/commandes/mois', $this->enTetes($jetonAutre))->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/stocks/commandes/stock-bas', $this->enTetes($jetonAutre))->assertOk()->assertExactJson(['data' => []]);
    }

    /** Vérification du 03.11.2026 : « % », « _ » et « \ » se cherchent tels quels, ils ne trouvent pas tout */
    public function test_la_recherche_protege_les_jokers_du_like(): void
    {
        $gants = $this->produit(['nom' => 'Gants nitrile M', 'reference' => 'MAT-0233']);
        $chlorhexidine = $this->produit(['nom' => 'Chlorhexidine 2 %', 'reference' => 'MAT-0102']);
        $ordinaire = $this->commande(StatutCommandeFournisseur::Brouillon, ['numero' => 'BC-2026-901', 'fournisseur' => 'Vetopharm SA']);
        $this->ligne($ordinaire, $gants, 1, 8.9);
        $avecPourcent = $this->commande(StatutCommandeFournisseur::Brouillon, ['numero' => 'BC-2026-902', 'fournisseur' => 'Covetrus']);
        $this->ligne($avecPourcent, $chlorhexidine, 1, 7.9);

        $this->assertSame([$avecPourcent->id], $this->ids('?q='.urlencode('%')));
        $this->assertSame([], $this->ids('?q=_'));
        $this->assertSame([], $this->ids('?q='.urlencode('\\')));
        $this->assertSame([], $this->ids('?q='.urlencode('BC-2026-9_1')));
    }

    /**
     * Deux « Préparer » au même instant ne doublent jamais les brouillons :
     * la clinique est verrouillée dans la transaction avant de lire ce qui est
     * déjà en commande.
     */
    public function test_generer_verifie_ce_qui_est_deja_en_commande_sous_verrou(): void
    {
        $produit = $this->produit(['fournisseur' => 'Covetrus', 'seuil_alerte' => 4]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 0]);

        $base = DB::transactionLevel();
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = ['sql' => $requete->sql, 'niveau' => DB::transactionLevel()];
        });

        $this->postJson('/api/v1/stocks/commandes/generer', ['lignes' => [['produit_id' => $produit->id, 'quantite' => 4]]], $this->enTetes($this->jeton))->assertOk();

        $verrou = collect($requetes)->search(fn (array $r): bool => str_contains($r['sql'], 'from "cliniques"') && $r['niveau'] > $base);
        $lecture = collect($requetes)->search(fn (array $r): bool => str_contains($r['sql'], 'from "commande_fournisseur_lignes"') && str_contains($r['sql'], 'distinct'));
        $this->assertNotFalse($verrou, 'La clinique se verrouille dans la transaction.');
        $this->assertNotFalse($lecture);
        $this->assertGreaterThan($base, $requetes[$lecture]['niveau'], 'Ce qui est en commande se lit dans la transaction.');
        $this->assertLessThan($lecture, $verrou, 'Le verrou vient avant la lecture.');
    }

    /** Deuxième vérification : le caractère d'échappement « ! » se cherche tel quel */
    public function test_la_recherche_trouve_le_point_d_exclamation_tel_quel(): void
    {
        $alerte = $this->produit(['nom' => 'Pansement urgence !', 'reference' => 'MAT-0901']);
        $ordinaire = $this->produit(['nom' => 'Gants nitrile M', 'reference' => 'MAT-0233']);
        $avec = $this->commande(StatutCommandeFournisseur::Brouillon, ['numero' => 'BC-2026-911', 'fournisseur' => 'Covetrus']);
        $this->ligne($avec, $alerte, 1, 4.5);
        $sans = $this->commande(StatutCommandeFournisseur::Brouillon, ['numero' => 'BC-2026-912', 'fournisseur' => 'Vetopharm SA']);
        $this->ligne($sans, $ordinaire, 1, 8.9);

        $this->assertSame([$avec->id], $this->ids('?q='.urlencode('!')));
        $this->assertSame([$avec->id], $this->ids('?q='.urlencode('urgence !')));
        $this->assertSame([], $this->ids('?q='.urlencode('!%')));
    }

    /**
     * Deuxième vérification : « Créer une commande » prend les verrous dans le
     * même ordre que « Préparer » (la clinique, puis la numérotation) : jamais
     * d'interblocage entre les deux.
     */
    public function test_creer_une_commande_verrouille_la_clinique_avant_la_numerotation(): void
    {
        $produit = $this->produit();
        $base = DB::transactionLevel();
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = ['sql' => $requete->sql, 'niveau' => DB::transactionLevel()];
        });

        $this->postJson('/api/v1/stocks/commandes', ['fournisseur' => 'Covetrus', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]]], $this->enTetes($this->jeton))->assertCreated();

        $verrou = collect($requetes)->search(fn (array $r): bool => str_contains($r['sql'], 'from "cliniques"') && $r['niveau'] > $base);
        $numero = collect($requetes)->search(fn (array $r): bool => str_contains($r['sql'], 'sequences_numerotation') || str_contains($r['sql'], 'sequence_numerotation'));
        $this->assertNotFalse($verrou, 'La clinique se verrouille dans la transaction.');
        $this->assertNotFalse($numero);
        $this->assertLessThan($numero, $verrou, 'La clinique avant la numérotation.');
    }
}
