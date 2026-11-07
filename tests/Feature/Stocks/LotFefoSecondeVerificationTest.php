<?php

namespace Tests\Feature\Stocks;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Lot;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;
use Throwable;

/**
 * Seconde vérification des lots décomptés (07.11.2026) : l'écart
 * d'inventaire pris dans les lots, une correction qui retrouve son lot
 * écarté une seule fois, un lot à zéro unité qui contient encore des ml,
 * des lectures verrouillées par substance, la correction lue sous le verrou.
 */
class LotFefoSecondeVerificationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation, 'civilite' => 'M.', 'nom' => 'Meunier']);
        $this->patient = Patient::factory()->for($client)->create(['organisation_id' => $organisation, 'nom' => 'Pilou']);
    }

    private function substance(int $stock, array $lots, string $tableau = 'b'): Produit
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_stupefiant' => true, 'tableau_stupefiant' => $tableau, 'unite' => 'ml']);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $stock]));
        foreach ($lots as [$numero, $peremption, $quantite]) {
            Lot::withoutEvents(fn () => Lot::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => $numero, 'peremption_le' => $peremption, 'quantite' => $quantite]));
        }
        MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => $stock]);

        return $produit;
    }

    private function inscrire(Produit $produit, array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, ...$donnees], $this->enTetes($this->jeton));
    }

    private function lot(Produit $produit, string $numero): Lot
    {
        return Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->where('numero_lot', $numero)->sole();
    }

    /** @return list<array{0: float, 1: string|null}> les lignes d'un type : quantité et lot */
    private function lignes(Produit $produit, string $type): array
    {
        return MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->where('type', $type)->orderBy('id')->get()
            ->map(fn (MouvementStupefiant $m): array => [(float) $m->quantite, $m->numero_lot])->all();
    }

    /** Sonde : sous SQLite, un verrou ne s'écrit pas ; on le marque d'un commentaire pour le voir passer */
    private function sonderLesVerrous(): \ArrayObject
    {
        $connexion = DB::connection();
        $connexion->setQueryGrammar(new class($connexion) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? ' /* verrou */' : '';
            }
        });
        $requetes = new \ArrayObject;
        DB::listen(fn ($requete) => $requetes->append($requete->sql));

        return $requetes;
    }

    // ————— M-A : l'écart d'inventaire négatif est pris dans les lots, une ligne par lot —————

    public function test_un_ecart_d_inventaire_negatif_est_pris_dans_les_lots_une_ligne_par_lot(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);

        $this->inscrire($produit, ['type' => 'inventaire', 'quantite_comptee' => 8, 'motif' => 'Flacon renversé'])->assertCreated()
            ->assertJsonPath('data.solde_apres', 8)->assertJsonCount(2, 'data.lignes');

        $this->assertSame([[-1.0, 'LOT A'], [-2.0, 'LOT B']], $this->lignes($produit, 'inventaire'));
        // Le compte est sur la ligne où le stock est celui compté
        $this->assertSame([null, '8.000'], MouvementStupefiant::withoutGlobalScopes()->where('type', 'inventaire')->orderBy('id')->pluck('quantite_comptee')->all());
        $this->assertSame([0, 8], [(int) $this->lot($produit, 'LOT A')->quantite, (int) $this->lot($produit, 'LOT B')->quantite]);
    }

    /** L'exemple de la vérification : sans lot, l'écart laissait 2 ml de trop au lot A, éliminés à tort */
    public function test_apres_un_ecart_d_inventaire_le_lot_ecarte_n_elimine_que_ce_qu_il_contient(): void
    {
        $produit = $this->substance(20, [['LOT A', '2027-01-01', 10], ['LOT B', '2027-06-01', 10]]);
        $this->inscrire($produit, ['type' => 'inventaire', 'quantite_comptee' => 18, 'motif' => 'Écart compté'])->assertCreated();
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 7, 'patient_id' => $this->patient->id, 'lot_id' => $this->lot($produit, 'LOT A')->id])->assertCreated();

        $this->postJson('/api/v1/stocks/lots/'.$this->lot($produit, 'LOT A')->id.'/ecarter', ['elimination' => 'Remis au pharmacien cantonal'], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame([[-1.0, 'LOT A']], $this->lignes($produit, 'elimination'));
        $this->assertSame(10.0, (float) MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->sum('quantite'));
    }

    public function test_l_ecart_d_inventaire_prend_les_lots_perimes_en_dernier_et_un_ecart_positif_reste_sans_lot(): void
    {
        $produit = $this->substance(10, [['PERIME', '2026-09-01', 5], ['VALIDE', '2027-06-01', 5]]);

        $this->inscrire($produit, ['type' => 'inventaire', 'quantite_comptee' => 7, 'motif' => 'Écart compté'])->assertCreated();
        $this->assertSame([[-3.0, 'VALIDE']], $this->lignes($produit, 'inventaire'));

        $this->inscrire($produit, ['type' => 'inventaire', 'quantite_comptee' => 8, 'motif' => 'Retrouvé un flacon'])->assertCreated();
        $this->assertSame([[-3.0, 'VALIDE'], [1.0, null]], $this->lignes($produit, 'inventaire'));
    }

    // ————— Léger 1 : une correction retrouve son lot écarté, une seule fois —————

    public function test_une_correction_d_une_ligne_au_lot_ecarte_le_retrouve_une_seule_fois(): void
    {
        $produit = $this->substance(5, [['LOT A', '2027-01-01', 5]]);
        $perte = $this->inscrire($produit, ['type' => 'perte', 'quantite' => 2, 'motif' => 'Flacon cassé'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/stocks/lots/'.$this->lot($produit, 'LOT A')->id.'/ecarter', ['elimination' => 'Remis au pharmacien cantonal'], $this->enTetes($this->jeton))->assertNoContent();

        $correction = $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $perte, 'quantite' => 2, 'motif' => 'Le flacon était entier'])->assertCreated()->json('data');

        $lot = $this->lot($produit, 'LOT A');
        $this->assertSame([$lot->id, 2, '2027-01-01'], [$correction['lot_id'], (int) $lot->quantite, $lot->peremption_le->toDateString()]);
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 1, 'patient_id' => $this->patient->id])->assertCreated()->assertJsonPath('data.numero_lot', 'LOT A');
    }

    // ————— Léger 2 : un lot à zéro unité qui contient encore des ml —————

    public function test_un_lot_sans_unite_entiere_qui_contient_encore_des_ml_se_voit_se_choisit_et_s_ecarte(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 0.3, 'patient_id' => $this->patient->id])->assertCreated();
        $lotA = $this->lot($produit, 'LOT A');
        $this->assertSame(0, (int) $lotA->quantite);

        $liste = collect($this->getJson('/api/v1/stocks/lots', $this->enTetes($this->jeton))->assertOk()->json('data'));
        $this->assertEqualsWithDelta(0.7, $liste->firstWhere('id', $lotA->id)['contenu'] ?? null, 0.0001);

        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 0.5, 'motif' => 'Renversé', 'lot_id' => $lotA->id])->assertCreated()->assertJsonPath('data.numero_lot', 'LOT A');
        $this->postJson("/api/v1/stocks/lots/{$lotA->id}/ecarter", ['elimination' => 'Remis au pharmacien cantonal'], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame([[-0.2, 'LOT A']], $this->lignes($produit, 'elimination'));
    }

    // ————— Léger 3 : le contenu des lots se relit par substance —————

    public function test_le_contenu_des_lots_se_relit_par_substance_et_non_par_liste_de_lots(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);
        $requetes = $this->sonderLesVerrous();

        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 3, 'patient_id' => $this->patient->id])->assertCreated();

        $parListe = collect($requetes)->filter(fn (string $sql): bool => str_contains($sql, '/* verrou */')
            && (str_contains($sql, 'from "mouvements_stupefiants"') || str_contains($sql, 'from "lot_allocations"'))
            && str_contains($sql, '"lot_id" in ('));
        $this->assertSame([], $parListe->values()->all());
    }

    // ————— Léger 4 : une correction se lit sous le verrou de la substance —————

    public function test_une_correction_lit_la_ligne_et_ce_qui_est_deja_corrige_sous_le_verrou(): void
    {
        $produit = $this->substance(10, [['LOT A', '2027-01-01', 10]]);
        $perte = $this->inscrire($produit, ['type' => 'perte', 'quantite' => 4, 'motif' => 'Flacon cassé'])->assertCreated()->json('data.id');
        $requetes = $this->sonderLesVerrous();

        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $perte, 'quantite' => 2, 'motif' => 'Un flacon entier'])->assertCreated();

        $sql = collect($requetes)->values();
        $verrou = $sql->search(fn (string $s): bool => str_contains($s, 'from "produits"') && str_contains($s, '/* verrou */'));
        $lectures = $sql->filter(fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, 'from "mouvements_stupefiants"') && (str_contains($s, '"corrige_id" =') || str_contains($s, '"mouvements_stupefiants"."id" =')));
        $this->assertNotFalse($verrou);
        $this->assertNotEmpty($lectures);
        foreach ($lectures as $i => $s) {
            $this->assertGreaterThan($verrou, $i, 'Lue avant le verrou de la substance : '.$s);
            $this->assertStringContainsString('/* verrou */', $s, 'Lue sans verrou : '.$s);
        }
    }

    // ————— Léger 5 : une remise en plusieurs lignes se corrige ligne par ligne —————

    public function test_corriger_au_dela_d_une_ligne_d_une_inscription_en_plusieurs_lignes_l_explique(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);
        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 3, 'motif' => 'Coffre renversé'])->assertCreated();
        $ligneA = MouvementStupefiant::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole();

        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $ligneA->id, 'quantite' => 3, 'motif' => 'Rien perdu'])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantite' => 'Une correction ne peut pas dépasser la ligne corrigée (-1). Elle s’est inscrite en 2 lignes, une par lot : corrigez chaque ligne pour son lot.']);
    }

    // ————— M3 : la troisième migration ne se défait pas non plus sur des données —————

    public function test_la_migration_des_lignes_reliees_ne_se_defait_pas_sur_des_donnees(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);
        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 3, 'motif' => 'Coffre renversé'])->assertCreated();

        try {
            (require database_path('migrations/2026_11_06_000503_narcotic_register_split_lines.php'))->down();
            $this->fail('La migration 000503 s’est défaite sur des données.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('ne se défait pas', $e->getMessage());
        }
        $this->assertSame(1, MouvementStupefiant::withoutGlobalScopes()->whereNotNull('premiere_ligne_id')->count());
    }
}
