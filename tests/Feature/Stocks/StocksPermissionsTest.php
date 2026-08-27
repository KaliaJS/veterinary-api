<?php

namespace Tests\Feature\Stocks;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\DerogationPermission;
use App\Models\Lot;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Permissions du domaine stocks, vérifiées en base à chaque requête :
 * sans le domaine, chaque endpoint — catalogue, ajustements, commandes,
 * lots — refuse en 403.
 */
class StocksPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Produit $produit;

    private CommandeFournisseur $commande;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
        $this->commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->lot = Lot::factory()->create([
            'produit_id' => $this->produit->id,
            'clinique_id' => $this->clinique->id,
        ]);
    }

    public function test_chaque_endpoint_du_domaine_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        foreach ($this->appelsDuDomaine() as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_stocks_ferme_le_domaine(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Stocks,
            'est_accordee' => false,
        ]);

        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/stocks/produits', $entetes)->assertForbidden();
        $this->getJson('/api/v1/stocks/commandes', $entetes)->assertForbidden();
        $this->getJson('/api/v1/stocks/lots', $entetes)->assertForbidden();

        // Le domaine planning, lui, reste ouvert.
        $this->getJson('/api/v1/rendez-vous', $entetes)->assertOk();
    }

    public function test_un_role_avec_le_domaine_stocks_accede_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/stocks/produits', $this->enTetes($jeton))->assertOk();
        $this->getJson("/api/v1/stocks/produits/{$this->produit->id}", $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/stocks/commandes', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/stocks/lots', $this->enTetes($jeton))->assertOk();
    }

    /**
     * Chaque endpoint du domaine avec une charge qui passe la
     * validation : l'autorisation (middleware can:stocks) vient avant
     * les contrôleurs.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function appelsDuDomaine(): array
    {
        $produit = $this->produit->id;

        return [
            ['getJson', '/api/v1/stocks/produits', []],
            ['postJson', '/api/v1/stocks/produits', ['nom' => 'Refusé', 'categorie' => 'mat', 'prix' => 10]],
            ['getJson', "/api/v1/stocks/produits/{$produit}", []],
            ['postJson', "/api/v1/stocks/produits/{$produit}/ajuster", ['quantite' => 1, 'motif' => 'recep']],
            ['getJson', "/api/v1/stocks/produits/{$produit}/mouvements", []],
            ['getJson', '/api/v1/stocks/commandes', []],
            ['postJson', '/api/v1/stocks/commandes', ['fournisseur' => 'Refusé', 'lignes' => [['produit_id' => $produit, 'quantite' => 1]]]],
            ['postJson', '/api/v1/stocks/commandes/generer', []],
            ['postJson', "/api/v1/stocks/commandes/{$this->commande->id}/envoyer", []],
            ['postJson', "/api/v1/stocks/commandes/{$this->commande->id}/receptionner", []],
            ['deleteJson', "/api/v1/stocks/commandes/{$this->commande->id}", []],
            ['getJson', '/api/v1/stocks/lots', []],
            ['postJson', "/api/v1/stocks/lots/{$this->lot->id}/ecarter", []],
        ];
    }
}
