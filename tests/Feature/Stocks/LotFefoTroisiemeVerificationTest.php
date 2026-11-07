<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Enums\TypeMouvementStupefiant;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Lot;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\User;
use App\Services\RegistreStupefiants;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Troisième vérification des lots décomptés (07.11.2026) : une correction
 * en moins d'une ligne sans lot sort des lots ce que le stock sans lot ne
 * couvre plus ; la réception manuelle d'un stupéfiant donne son lot.
 */
class LotFefoTroisiemeVerificationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private User $user;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton, 'user' => $this->user] = $this->employeAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation, 'civilite' => 'M.', 'nom' => 'Meunier']);
        $this->patient = Patient::factory()->for($client)->create(['organisation_id' => $organisation, 'nom' => 'Pilou']);
    }

    private function substance(array $attributs = []): Produit
    {
        return Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_stupefiant' => true, 'tableau_stupefiant' => 'b', 'unite' => 'ml', ...$attributs]);
    }

    private function inscrire(Produit $produit, array $donnees)
    {
        return $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, ...$donnees], $this->enTetes($this->jeton));
    }

    /** Le registre, le stock et le contenu de chaque lot (en ml) : ce qui doit rester juste à chaque étape */
    private function etat(Produit $produit): array
    {
        [$lots, $contenus] = app(RegistreStupefiants::class)->contenuDesLots($produit->id, $this->clinique->id);

        return [
            'registre' => round((float) MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->sum('quantite'), 3),
            'stock' => (int) Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->value('quantite'),
            'lots' => $lots->mapWithKeys(fn (Lot $lot): array => [$lot->numero_lot => $contenus[$lot->id]])->all(),
        ];
    }

    // ————— M1 : une correction en moins d'une ligne sans lot —————

    public function test_le_scenario_de_la_verification_garde_le_registre_juste_a_chaque_etape(): void
    {
        // 1. Un lot PÉRIMÉ de 10 ml, au registre et au stock
        $produit = $this->substance(['peremption_le' => '2027-09-01']);
        Stock::withoutEvents(fn () => Stock::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 10]));
        $perime = Lot::withoutEvents(fn () => Lot::create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => 'PERIME', 'peremption_le' => '2026-09-01', 'quantite' => 10]));
        MouvementStupefiant::factory()->create(['clinique_id' => $this->clinique->id, 'produit_id' => $produit->id, 'quantite' => 10]);

        // 2. Une réception manuelle de 5 ml, saisie deux fois (d'avant la réception avec lot : sans lot)
        app(ContexteClinique::class)->definir($this->clinique);
        foreach ([1, 2] as $fois) {
            app(RegistreStupefiants::class)->ecrire($produit, TypeMouvementStupefiant::Reception, 5, ['fournisseur' => 'Grossiste', 'bulletin' => 'BL-'.$fois], $this->clinique->id, $this->user);
        }
        $doublon = MouvementStupefiant::withoutGlobalScopes()->where('type', 'reception')->latest('id')->value('id');
        app(ContexteClinique::class)->oublier();
        $this->assertSame(['registre' => 20.0, 'stock' => 20, 'lots' => ['PERIME' => 10.0]], $this->etat($produit));

        // 3. 8 ml administrés : du stock sans lot, avant le lot périmé
        $this->inscrire($produit, ['type' => 'administration', 'quantite' => 8, 'patient_id' => $this->patient->id])->assertCreated();
        $this->assertSame(['registre' => 12.0, 'stock' => 12, 'lots' => ['PERIME' => 10.0]], $this->etat($produit));

        // 4. Le doublon corrigé de −5 : 2 ml que le stock sans lot couvre encore, 3 ml pris au lot
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $doublon, 'quantite' => -5, 'motif' => 'Saisie en double'])->assertCreated()
            ->assertJsonCount(2, 'data.lignes');
        $corrections = MouvementStupefiant::withoutGlobalScopes()->where('type', 'correction')->orderBy('id')->get();
        $this->assertSame([[-2.0, null], [-3.0, 'PERIME']], $corrections->map(fn ($m): array => [(float) $m->quantite, $m->numero_lot])->all());
        $this->assertSame([$doublon, $doublon], $corrections->pluck('corrige_id')->all());
        $this->assertSame(['registre' => 7.0, 'stock' => 7, 'lots' => ['PERIME' => 7.0]], $this->etat($produit));

        // 5. Une commande reçue avec le lot NEUF de 10 ml
        $commande = CommandeFournisseur::factory()->envoyee()->create(['clinique_id' => $this->clinique->id]);
        CommandeFournisseurLigne::factory()->create(['commande_fournisseur_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 10]);
        $this->postJson("/api/v1/stocks/commandes/{$commande->id}/receptionner", ['bulletin' => 'BL-3', 'bulletin_le' => '2026-11-05'], $this->enTetes($this->jeton))->assertOk();
        $neuf = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->whereKeyNot($perime->id)->sole()->numero_lot;
        $this->assertSame($neuf, MouvementStupefiant::withoutGlobalScopes()->where('bulletin', 'BL-3')->value('numero_lot'));
        $this->assertSame(['registre' => 17.0, 'stock' => 17, 'lots' => ['PERIME' => 7.0, $neuf => 10.0]], $this->etat($produit));

        // 6. Le lot PÉRIMÉ écarté : 7 ml éliminés, il reste le lot NEUF
        $this->postJson("/api/v1/stocks/lots/{$perime->id}/ecarter", ['elimination' => 'Remis au pharmacien cantonal'], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame(-7.0, (float) MouvementStupefiant::withoutGlobalScopes()->where('type', 'elimination')->sole()->quantite);
        $this->assertSame(['registre' => 10.0, 'stock' => 10, 'lots' => [$neuf => 10.0]], $this->etat($produit));
    }

    // ————— Décision de Jim : la réception manuelle donne son lot —————

    public function test_une_reception_manuelle_demande_son_lot_et_sa_peremption_et_cree_le_lot(): void
    {
        $produit = $this->substance();
        $reception = ['type' => 'reception', 'quantite' => 5, 'fournisseur' => 'Grossiste', 'bulletin' => 'BL-1', 'bulletin_le' => '2026-11-05'];

        $this->inscrire($produit, $reception)->assertUnprocessable()
            ->assertJsonValidationErrors(['numero_lot' => 'Indiquez le numéro du lot reçu.', 'lot_peremption' => 'Indiquez la péremption du lot reçu.']);

        $ligne = $this->inscrire($produit, [...$reception, 'numero_lot' => ' A-2028 ', 'lot_peremption' => '2028-03'])->assertCreated()->json('data');
        $lot = Lot::withoutGlobalScopes()->where('produit_id', $produit->id)->sole();
        $this->assertSame(['A-2028', '2028-03-01', 5, $this->clinique->id], [$lot->numero_lot, $lot->peremption_le->toDateString(), (int) $lot->quantite, $lot->clinique_id]);
        $this->assertSame([$lot->id, 'A-2028'], [$ligne['lot_id'], $ligne['numero_lot']]);

        // Le même numéro et la même péremption : le même lot ; une autre péremption : un autre lot
        $this->inscrire($produit, [...$reception, 'numero_lot' => 'A-2028', 'lot_peremption' => '2028-03'])->assertCreated()->assertJsonPath('data.lot_id', $lot->id);
        $this->assertSame(10, (int) $lot->fresh()->quantite);
        $autre = $this->inscrire($produit, [...$reception, 'numero_lot' => 'A-2028', 'lot_peremption' => '2029-01'])->assertCreated()->json('data.lot_id');
        $this->assertNotSame($lot->id, $autre);
        $this->assertSame(15, (int) Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->value('quantite'));

        // Corrigée, la réception rend ou reprend dans son lot
        $this->inscrire($produit, ['type' => 'correction', 'corrige_id' => $ligne['id'], 'quantite' => -2, 'motif' => 'Deux de moins'])->assertCreated()
            ->assertJsonPath('data.lot_id', $lot->id);
        $this->assertSame(8.0, app(RegistreStupefiants::class)->contenuDesLots($produit->id, $this->clinique->id)[1][$lot->id]);
        $this->assertSame(8, (int) $lot->fresh()->quantite);
    }
}
