<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Events\AlertesModifiees;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Lot;
use App\Models\LotAllocation;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\GestionnaireStock;
use App\Services\Stocks\FefoLots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;
use Throwable;

/**
 * Vérification des lots décomptés à chaque sortie (06.11.2026) : une
 * ligne du registre par lot, rendus relus sous verrou, migrations qu'on
 * ne défait pas sur des données, un +1 qui ne fait pas revivre un lot
 * écarté, une diffusion par mouvement, un lot imposé vide refusé.
 */
class LotFefoVerificationTest extends TestCase
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

    private function article(int $stock, array $lots, array $attributs = []): Produit
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_sur_ordonnance' => false, 'est_stupefiant' => false, ...$attributs]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $stock]));
        foreach ($lots as [$numero, $peremption, $quantite]) {
            Lot::withoutEvents(fn () => Lot::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => $numero, 'peremption_le' => $peremption, 'quantite' => $quantite]));
        }

        return $produit;
    }

    private function substance(int $stock, array $lots, string $tableau = 'b'): Produit
    {
        $produit = $this->article($stock, $lots, ['est_stupefiant' => true, 'tableau_stupefiant' => $tableau, 'unite' => 'ml']);
        if ($stock > 0) {
            MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => $stock]);
        }

        return $produit;
    }

    /** @return array<string, int> */
    private function lots(Produit $produit): array
    {
        return Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->orderBy('id')->pluck('quantite', 'numero_lot')->map(fn ($q): int => (int) $q)->all();
    }

    private function lot(Produit $produit, string $numero): Lot
    {
        return Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->where('numero_lot', $numero)->sole();
    }

    private function inscrire(Produit $produit, array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, ...$donnees], $this->enTetes($this->jeton));
    }

    /** @return list<array{0: float, 1: string|null, 2: float}> les lignes de sortie du registre : quantité, lot, solde après */
    private function sortiesDuRegistre(Produit $produit): array
    {
        return MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->where('quantite', '<', 0)->orderBy('id')->get()
            ->map(fn (MouvementStupefiant $m): array => [(float) $m->quantite, $m->numero_lot, (float) $m->solde_apres])->all();
    }

    private function ajuster(Produit $produit, int $quantite, string $motif = 'inv'): void
    {
        $this->postJson("/api/v1/stocks/produits/{$produit->id}/ajuster", ['quantite' => $quantite, 'motif' => $motif], $this->enTetes($this->jeton))->assertOk();
    }

    // ————— M1 : une ligne du registre par lot —————

    public function test_une_sortie_du_registre_plus_grande_que_le_lot_s_inscrit_en_une_ligne_par_lot(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);

        $reponse = $this->inscrire($produit, ['type' => 'administration', 'quantite' => 3, 'patient_id' => $this->patient->id])->assertCreated();

        $this->assertSame([[-1.0, 'LOT A', 10.0], [-2.0, 'LOT B', 8.0]], $this->sortiesDuRegistre($produit));
        $this->assertSame(['LOT A' => 0, 'LOT B' => 8], $this->lots($produit));
        $this->assertSame(['Pilou', 'Pilou'], MouvementStupefiant::withoutGlobalScopes()->where('quantite', '<', 0)->orderBy('id')->pluck('patient_nom')->all());
        // La réponse : la dernière ligne (le stock après l'inscription), et toutes les lignes écrites
        $reponse->assertJsonPath('data.solde_apres', 8)->assertJsonPath('data.numero_lot', 'LOT B')
            ->assertJsonCount(2, 'data.lignes')->assertJsonPath('data.lignes.0.numero_lot', 'LOT A');
        $this->assertSame(8, (int) Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->value('quantite'));
    }

    public function test_un_lot_choisi_plus_petit_que_la_sortie_continue_en_une_autre_ligne(): void
    {
        $produit = $this->substance(12, [['LOT A', '2027-01-01', 10], ['LOT B', '2027-06-01', 2]]);

        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 3, 'motif' => 'Flacon cassé', 'lot_id' => $this->lot($produit, 'LOT B')->id])->assertCreated();

        $this->assertSame([[-2.0, 'LOT B', 10.0], [-1.0, 'LOT A', 9.0]], $this->sortiesDuRegistre($produit));
        $this->assertSame(['LOT A' => 9, 'LOT B' => 0], $this->lots($produit));
    }

    /** Les dixièmes de ml : le lot compte ce que le registre y a inscrit, pas l'arrondi du stock */
    public function test_les_doses_en_dixiemes_restent_au_lot_inscrit(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]]);

        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 1.5, 'patient_id' => $this->patient->id])->assertCreated();
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 0.5, 'patient_id' => $this->patient->id])->assertCreated();

        $this->assertSame([[-1.0, 'LOT A', 10.0], [-0.5, 'LOT B', 9.5], [-0.5, 'LOT B', 9.0]], $this->sortiesDuRegistre($produit));
        $this->assertSame(['LOT A' => 0, 'LOT B' => 9], $this->lots($produit));
    }

    /** Une remise sur formulaire officiel en deux lignes : une seule remise, rétablie après correction sans refus */
    public function test_une_remise_en_deux_lignes_reste_une_seule_remise_du_formulaire(): void
    {
        $produit = $this->substance(11, [['LOT A', '2027-01-01', 1], ['LOT B', '2027-06-01', 10]], 'a');
        $remise = ['type' => 'remise', 'quantite' => 3, 'patient_id' => $this->patient->id, 'ordonnance_officielle' => '7012345', 'ordonnance_officielle_le' => '2026-11-05'];

        $this->inscrire($produit, $remise)->assertCreated();
        $this->assertSame([[-1.0, 'LOT A', 10.0], [-2.0, 'LOT B', 8.0]], $this->sortiesDuRegistre($produit));
        $premiere = MouvementStupefiant::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole();

        // Annulée puis rétablie, la ligne du lot A : sa sœur n'est pas une autre remise du formulaire
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $premiere->id, 'quantite' => 1, 'motif' => 'Erreur'])->assertCreated();
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $premiere->id, 'quantite' => -1, 'motif' => 'Rétablie'])->assertCreated();
        // Le formulaire a servi : une autre remise est refusée
        $this->inscrire($produit, $remise)->assertUnprocessable()->assertJsonValidationErrors('ordonnance_officielle');
    }

    // ————— M2 : ce qui a déjà été rendu se relit sous verrou —————

    public function test_ce_qui_a_deja_ete_rendu_se_relit_sous_verrou(): void
    {
        // Sonde : sous SQLite, un verrou ne s'écrit pas ; on le marque d'un commentaire pour le voir passer
        $connexion = DB::connection();
        $connexion->setQueryGrammar(new class($connexion) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? ' /* verrou */' : '';
            }
        });
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });
        $produit = $this->article(7, [['LOT A', '2027-01-01', 3], ['LOT B', '2027-06-01', 4]]);
        $this->ajuster($produit, -5);
        $this->ajuster($produit, 1);
        $this->ajuster($produit, 1);

        $rendus = collect($requetes)->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'lot_allocations') && str_contains($sql, 'returns_allocation_id'));
        $this->assertNotEmpty($rendus);
        foreach ($rendus as $sql) {
            $this->assertStringContainsString('/* verrou */', $sql, 'Lecture des rendus sans verrou : '.$sql);
            $this->assertStringNotContainsString('(select', $sql, 'Somme des rendus lue dans une sous-requête sans verrou : '.$sql);
        }
        $this->assertSame(['LOT A' => 0, 'LOT B' => 4], $this->lots($produit));
    }

    // ————— M3 : on ne défait pas la traçabilité ni le registre —————

    public function test_les_migrations_ne_se_defont_pas_sur_des_donnees(): void
    {
        $produit = $this->substance(5, [['LOT A', '2027-01-01', 5]]);
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 1, 'patient_id' => $this->patient->id])->assertCreated();

        foreach (['2026_11_06_000500_create_lot_allocations_table.php', '2026_11_06_000501_narcotic_register_lot.php'] as $fichier) {
            try {
                (require database_path('migrations/'.$fichier))->down();
                $this->fail('La migration '.$fichier.' s’est défaite sur des données.');
            } catch (Throwable $e) {
                $this->assertStringContainsString('ne se défait pas', $e->getMessage());
            }
        }
        $this->assertSame(1, LotAllocation::query()->count());
        $this->assertSame('LOT A', MouvementStupefiant::withoutGlobalScopes()->where('quantite', '<', 0)->value('numero_lot'));
    }

    // ————— L1 : un +1 ne fait pas revivre un lot écarté pour péremption —————

    public function test_un_plus_un_ne_fait_pas_revivre_un_lot_ecarte(): void
    {
        $produit = $this->article(5, [['LOT A', '2027-01-01', 2], ['LOT B', '2027-06-01', 3]]);
        $this->ajuster($produit, -1);
        $this->postJson('/api/v1/stocks/lots/'.$this->lot($produit, 'LOT A')->id.'/ecarter', [], $this->enTetes($this->jeton))->assertNoContent();

        $this->ajuster($produit, 1);

        $this->assertSame(['LOT B' => 3], $this->lots($produit));

        // Un −1 « Périmé : écarté » ne se rend pas non plus à son lot périmé
        $perime = $this->article(5, [['PERIME', '2026-09-01', 2], ['VALIDE', '2027-06-01', 3]]);
        $this->ajuster($perime, -1, 'peremp');
        $this->ajuster($perime, 1);
        $this->assertSame(['PERIME' => 1, 'VALIDE' => 3], $this->lots($perime));
    }

    // ————— L2 et L3 : un seul décompte par requête, une seule diffusion par mouvement —————

    public function test_le_decompte_sert_une_seule_fois_par_requete_et_diffuse_une_fois_par_mouvement(): void
    {
        $this->assertSame(app(FefoLots::class), app(FefoLots::class));

        $produit = $this->article(7, [['LOT A', '2027-01-01', 3], ['LOT B', '2027-06-01', 4]]);
        Event::fake([AlertesModifiees::class]);

        $this->ajuster($produit, -5);

        $this->assertSame(['LOT A' => 0, 'LOT B' => 2], $this->lots($produit));
        Event::assertDispatchedTimes(AlertesModifiees::class, 1);
    }

    // ————— L4 : un lot imposé vide est refusé —————

    public function test_une_correction_sur_un_lot_vide_est_refusee(): void
    {
        $produit = $this->substance(10, [], 'b');
        $produit->forceFill(['peremption_le' => '2027-09-01'])->save();
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 5]);
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", ['bulletin' => 'BL-1', 'bulletin_le' => '2026-11-05'], $this->enTetes($this->jeton))->assertOk();
        $recu = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->inscrire($produit, ['type' => 'perte', 'quantite' => 5, 'motif' => 'Flacons cassés', 'lot_id' => $recu->id])->assertCreated();

        $reception = MouvementStupefiant::withoutGlobalScopes()->where('type', 'reception')->sole();
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $reception->id, 'quantite' => -1, 'motif' => 'Un flacon de moins'])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantite' => 'Le lot '.$recu->numero_lot.' est vide : il n’en reste rien à sortir.']);
    }

    public function test_une_sortie_imposee_sur_un_lot_vide_est_refusee(): void
    {
        $produit = $this->article(3, [['VIDE', '2027-01-01', 0], ['PLEIN', '2027-06-01', 3]]);
        app(ContexteClinique::class)->definir($this->clinique);

        try {
            app(GestionnaireStock::class)->appliquer($produit, -1, 'Sortie', lot: $this->lot($produit, 'VIDE'));
            $this->fail('Une sortie imposée sur un lot vide est passée en silence.');
        } catch (ValidationException $e) {
            $this->assertSame(['Le lot VIDE est vide : il n’en reste rien à sortir.'], $e->errors()['quantite']);
        }
        $this->assertSame(['VIDE' => 0, 'PLEIN' => 3], $this->lots($produit));
    }
}
