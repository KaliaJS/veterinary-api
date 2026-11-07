<?php

namespace Tests\Feature\Stocks;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Lot;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\Stocks\FefoLots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Quatrième vérification des lots décomptés (07.11.2026), points légers :
 * un lot se reconnaît par son numéro et son mois ; une péremption passée
 * se confirme ; un lot vide se dit sur le champ du lot ; à qui un lot de
 * stupéfiant a été donné se lit dans son registre.
 */
class LotFefoQuatriemeVerificationTest extends TestCase
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

    private function substance(int $stock = 0, array $lots = [], array $attributs = []): Produit
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_stupefiant' => true, 'tableau_stupefiant' => 'b', 'unite' => 'ml', ...$attributs]);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $stock]));
        foreach ($lots as [$numero, $peremption, $quantite]) {
            Lot::withoutEvents(fn () => Lot::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => $numero, 'peremption_le' => $peremption, 'quantite' => $quantite]));
        }
        if ($stock > 0) {
            MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => $stock]);
        }

        return $produit;
    }

    private function inscrire(Produit $produit, array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, ...$donnees], $this->enTetes($this->jeton));
    }

    private function reception(string $numero, string $peremption, array $autres = []): array
    {
        return ['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Grossiste', 'bulletin' => 'BL-1', 'bulletin_le' => '2026-11-05', 'numero_lot' => $numero, 'lot_peremption' => $peremption, ...$autres];
    }

    // ————— 1. Un lot se reconnaît par son numéro et son mois —————

    public function test_un_lot_recu_par_commande_puis_a_la_main_au_meme_numero_et_au_meme_mois_reste_un_seul_lot(): void
    {
        $produit = $this->substance(attributs: ['peremption_le' => '2028-03-31']);
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 10]);
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", ['bulletin' => 'BL-0', 'bulletin_le' => '2026-11-05'], $this->enTetes($this->jeton))->assertOk();
        $lot = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->assertSame('2028-03-31', $lot->peremption_le->toDateString());

        $this->inscrire($produit, $this->reception($lot->numero_lot, '2028-03'))->assertCreated()->assertJsonPath('data.lot_id', $lot->id);
        $this->assertSame(15, (int) $lot->fresh()->quantite);
        // Un autre mois : un autre lot
        $this->inscrire($produit, $this->reception($lot->numero_lot, '2028-04'))->assertCreated();
        $this->assertSame(2, Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->count());
    }

    public function test_le_lot_retrouve_par_son_numero_l_est_aussi_par_son_mois(): void
    {
        $produit = $this->substance(lots: [['X-1', '2028-03-31', 0], ['X-1', '2028-05-01', 0]]);
        $mai = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->whereDate('peremption_le', '2028-05-01')->sole();

        $this->assertSame($mai->id, app(FefoLots::class)->lotByNumber($produit->id, $this->clinique->id, 'X-1', Carbon::parse('2028-05-15'))->id);
        $juillet = app(FefoLots::class)->lotByNumber($produit->id, $this->clinique->id, 'X-1', Carbon::parse('2028-07-01'));
        $this->assertSame('2028-07-01', $juillet->peremption_le->toDateString());
        $this->assertSame(3, Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->count());
    }

    // ————— 3. Une péremption déjà passée se confirme —————

    public function test_une_reception_au_lot_deja_perime_demande_une_confirmation(): void
    {
        $produit = $this->substance();

        $this->inscrire($produit, $this->reception('VIEUX', '2026-09'))->assertUnprocessable()
            ->assertJsonValidationErrors(['lot_peremption' => 'Ce lot est déjà périmé : l’inscrire quand même ?']);
        $this->assertSame(0, MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->count());

        $this->inscrire($produit, $this->reception('VIEUX', '2026-09', ['lot_perime_confirme' => true]))->assertCreated();
        // Le mois en cours n'est pas encore passé : pas de confirmation
        $this->inscrire($produit, $this->reception('COURANT', '2026-11'))->assertCreated();
    }

    // ————— 4. Un lot vide se dit sur le champ du lot —————

    public function test_une_elimination_sur_un_lot_vide_le_dit_sur_le_champ_du_lot(): void
    {
        $produit = $this->substance(5, [['VIDE', '2026-09-01', 0], ['PLEIN', '2027-06-01', 5]]);
        $vide = Lot::withoutGlobalScopes()->where('numero_lot', 'VIDE')->sole();

        $this->inscrire($produit, ['type' => 'elimination', 'quantite' => 1, 'motif' => 'Remis au pharmacien cantonal', 'lot_id' => $vide->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id' => 'Le lot VIDE est vide : il n’en reste rien à sortir.'])
            ->assertJsonMissingValidationErrors('quantite');
    }

    // ————— 5. À qui un lot de stupéfiant a été donné : son registre fait foi —————

    public function test_les_sorties_d_un_lot_de_stupefiant_se_lisent_dans_son_registre(): void
    {
        $produit = $this->substance(10, [['LOT A', '2027-01-01', 10]]);
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 3, 'patient_id' => $this->patient->id])->assertCreated();
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 0.5, 'patient_id' => $this->patient->id])->assertCreated();
        // Une perte entièrement corrigée n'a rien sorti
        $perte = $this->inscrire($produit, ['type' => 'perte', 'quantite' => 1, 'motif' => 'Flacon renversé'])->assertCreated()->json('data.id');
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $perte, 'quantite' => 1, 'motif' => 'Retrouvé'])->assertCreated();
        $lot = Lot::withoutGlobalScopes()->where('numero_lot', 'LOT A')->sole();

        $reponse = $this->getJson("/api/v1/stocks/lots/{$lot->id}/sorties", $this->enTetes($this->jeton))->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsWithDelta(0.5, $reponse->json('data.0.quantite'), 0.0001);
        $this->assertEqualsWithDelta(3, $reponse->json('data.1.quantite'), 0.0001);
        $reponse->assertJsonPath('data.0.numero_lot', 'LOT A')
            ->assertJsonPath('data.0.patient.nom', 'Pilou')
            ->assertJsonPath('data.0.client.nom', 'M. Meunier')
            ->assertJsonPath('data.0.libelle', 'Administration');
        $this->assertSame((new MouvementStupefiant)->getMorphClass(), $reponse->json('data.0.source_type'));
    }
}
