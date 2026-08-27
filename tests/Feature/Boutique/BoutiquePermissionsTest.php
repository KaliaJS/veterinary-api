<?php

namespace Tests\Feature\Boutique;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\DerogationPermission;
use App\Models\Produit;
use App\Models\Role;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La boutique relève du domaine stocks (l'ASV la tient — la
 * facturation, elle, lui reste fermée) : sans le domaine, chaque
 * endpoint refuse en 403.
 */
class BoutiquePermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private CommandeBoutique $commande;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->commande = CommandeBoutique::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true,
        ]);
    }

    public function test_chaque_endpoint_de_la_boutique_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $appels = [
            ['getJson', '/api/v1/boutique/commandes', []],
            ['postJson', '/api/v1/boutique/commandes', ['client_nom' => 'Refusé', 'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]]]],
            ['postJson', "/api/v1/boutique/commandes/{$this->commande->id}/preparer", []],
            ['postJson', "/api/v1/boutique/commandes/{$this->commande->id}/retirer", []],
            ['postJson', '/api/v1/boutique/vente', ['moyen_paiement' => 'cash', 'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]]]],
            ['patchJson', "/api/v1/boutique/produits/{$this->produit->id}/vitrine", []],
            ['getJson', '/api/v1/boutique/statistiques', []],
        ];

        foreach ($appels as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_stocks_ferme_la_boutique(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Stocks,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/boutique/commandes', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_la_vente_comptoir_n_exige_pas_le_domaine_facturation(): void
    {
        // Une ASV : stocks oui, facturation non — elle tient la
        // boutique, la facture naît comme effet système.
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ASV)
                ->value('id'),
        ]);

        Stock::factory()->create([
            'produit_id' => $this->produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => 5,
        ]);

        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jeton))->assertForbidden();

        $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => 'cash',
            'lignes' => [['produit_id' => $this->produit->id, 'quantite' => 1]],
        ], $this->enTetes($jeton))->assertCreated();
    }
}
