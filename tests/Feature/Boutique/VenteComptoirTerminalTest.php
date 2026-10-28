<?php

namespace Tests\Feature\Boutique;

use App\Auth\RolesParDefaut;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Role;
use App\Models\Stock;
use App\Models\TerminalPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Paiements\PaiementsCaisse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Vente comptoir par le terminal et TWINT (décision de Jim, 28.10.2026) :
 * en paiement intégré, une vente par carte ou TWINT attend le terminal ou
 * le QR TWINT, comme une facture ; le stock ne sort qu'au paiement réussi.
 */
class VenteComptoirTerminalTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private PasserellePaiementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
    }

    /** Le rôle par défaut « Assistant » : Stocks, sans Facturation */
    private function assistant(): string
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ASSISTANT)->value('id')]);

        return $jeton;
    }

    private function terminal(string $libelle = 'Boutique', string $lecteur = 'tmr_boutique'): TerminalPaiement
    {
        return TerminalPaiement::query()->firstOrCreate(['stripe_reader_id' => $lecteur], ['clinique_id' => $this->clinique->id, 'libelle' => $libelle, 'modele' => 'bbpos_wisepos_e']);
    }

    private function produitEnRayon(int $quantite): Produit
    {
        $produit = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'est_boutique' => true, 'est_sur_ordonnance' => false, 'prix' => 30, 'promo_pct' => 0,
        ]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $quantite]);

        return $produit;
    }

    private function stockDe(Produit $produit): int
    {
        return Stock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('clinique_id', $this->clinique->id)->sole()->quantite;
    }

    private function vendre(Produit $produit, string $moyen): TestResponse
    {
        return $this->postJson('/api/v1/boutique/vente', [
            'moyen_paiement' => $moyen,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ], $this->enTetes($this->jeton));
    }

    public function test_en_paiement_integre_une_vente_par_carte_attend_le_terminal(): void
    {
        $this->assertTrue($this->clinique->encaisseAvecVeterinary());
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'tpe')->assertCreated()
            ->assertJsonPath('data.statut', 'due')
            ->assertJsonPath('data.payee_le', null)
            ->assertJsonPath('data.total_ttc', 60);

        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_en_paiement_integre_une_vente_par_twint_attend_le_qr_twint(): void
    {
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'twint')->assertCreated()->assertJsonPath('data.statut', 'due');

        $this->assertSame(5, $this->stockDe($produit));
        // Pas encore payée : hors des chiffres de la boutique
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.totaux.tickets', 0);
    }

    public function test_le_paiement_reussi_au_terminal_paie_la_vente_sort_le_stock_et_le_z_ne_la_signale_pas(): void
    {
        $produit = $this->produitEnRayon(5);
        $facture = Facture::withoutGlobalScopes()->findOrFail($this->vendre($produit, 'tpe')->json('data.id'));
        $this->assertSame('due', $facture->statut->value);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Tpe,
            'montant' => 60, 'stripe_payment_intent_id' => 'pi_vente', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->appliquer($paiement, 'succeeded', null);

        $this->assertSame('payee', $facture->refresh()->statut->value);
        $this->assertSame(3, $this->stockDe($produit));
        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.moyens.tpe.nombre', 1)
            ->assertJsonPath('data.moyens.tpe.sans_terminal', 0);
    }

    public function test_en_especes_la_vente_reste_encaissee_tout_de_suite(): void
    {
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'cash')->assertCreated()->assertJsonPath('data.statut', 'payee');

        $this->assertSame(3, $this->stockDe($produit));
    }

    public function test_sans_paiement_integre_une_carte_est_encaissee_tout_de_suite(): void
    {
        $this->clinique->update(['paiements_integres' => false]);
        $produit = $this->produitEnRayon(5);

        $this->vendre($produit, 'tpe')->assertCreated()->assertJsonPath('data.statut', 'payee');

        $this->assertSame(3, $this->stockDe($produit));
    }

    public function test_un_assistant_avec_le_droit_stocks_encaisse_au_terminal_et_par_twint(): void
    {
        $jeton = $this->assistant();
        $produit = $this->produitEnRayon(5);
        $terminal = $this->terminal();

        $vente = $this->postJson('/api/v1/boutique/vente', ['moyen_paiement' => 'tpe', 'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]]], $this->enTetes($jeton))->assertCreated()->json('data');
        $this->getJson('/api/v1/boutique/terminaux', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.0.libelle', 'Boutique');
        $paiement = $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/paiements", ['moyen' => 'tpe', 'terminal_id' => $terminal->id], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.statut', 'en_attente')->json('data');
        $this->assertSame('tmr_boutique', $this->stripe->presentations[0]['lecteur']);

        $this->postJson("/api/v1/boutique/paiements/{$paiement['id']}/annuler", [], $this->enTetes($jeton))->assertOk();
        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/paiements", ['moyen' => 'twint'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.moyen', 'twint');
        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_une_vente_non_payee_s_annule_et_reste_au_journal_sans_toucher_le_stock(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'twint')->json('data');
        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))->assertCreated();

        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/annuler", [], $this->enTetes($this->assistant()))
            ->assertOk()->assertJsonPath('data.statut', 'annulee');

        $facture = Facture::withoutGlobalScopes()->findOrFail($vente['id']);
        $this->assertSame('annulee', $facture->statut->value);
        $this->assertSame(5, $this->stockDe($produit));
        $this->assertSame('annule', Paiement::query()->where('facture_id', $facture->id)->value('statut')->value);
        // Hors des chiffres et du Z
        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.moyens.twint.nombre', 0);
    }

    public function test_une_vente_deja_payee_ne_s_annule_pas(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'cash')->json('data');

        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/annuler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['vente' => 'Cette vente est payée : elle ne s’annule plus.']);
    }

    public function test_une_vente_du_comptoir_ne_se_paie_pas_plus_tard_et_ne_se_modifie_pas(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'tpe')->json('data');

        $this->postJson("/api/v1/facturation/factures/{$vente['id']}/payer-plus-tard", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['facture' => 'Une vente du comptoir se paie ou s’annule, jamais plus tard.']);
        $this->postJson("/api/v1/facturation/factures/{$vente['id']}/lignes", ['produit_id' => $produit->id, 'quantite' => 40], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['facture' => 'Le ticket du comptoir ne se modifie pas : annulez la vente et refaites-la.']);
        $this->assertSame(5, $this->stockDe($produit));
    }

    public function test_la_boutique_passe_en_especes_si_la_carte_echoue(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'tpe')->json('data');
        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->assertCreated();

        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/especes", [], $this->enTetes($this->assistant()))
            ->assertOk()->assertJsonPath('data.statut', 'payee')->assertJsonPath('data.moyen_paiement', 'cash');

        $this->assertSame(3, $this->stockDe($produit));
    }

    public function test_une_facture_de_consultation_ne_passe_pas_par_les_gestes_de_la_boutique(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);

        $this->postJson("/api/v1/boutique/ventes/{$facture->id}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))->assertNotFound();
        $this->postJson("/api/v1/boutique/ventes/{$facture->id}/annuler", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->postJson("/api/v1/boutique/ventes/{$facture->id}/especes", [], $this->enTetes($this->jeton))->assertNotFound();
    }

    /** Une vente abandonnée (page rechargée, app fermée) se retrouve : la boutique liste ses ventes en attente. */
    public function test_la_boutique_retrouve_ses_ventes_en_attente(): void
    {
        $produit = $this->produitEnRayon(5);
        $due = $this->vendre($produit, 'twint')->json('data');
        $this->vendre($produit, 'cash');

        $this->getJson('/api/v1/boutique/ventes', $this->enTetes($this->assistant()))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $due['id'])
            ->assertJsonPath('data.0.statut', 'due');
    }

    /** La caisse (droit Facturation, sans Stocks) peut aussi annuler une vente du comptoir restée due. */
    public function test_la_caisse_annule_une_vente_du_comptoir_restee_due(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'tpe')->json('data');
        ['employe' => $employe, 'jeton' => $caisse] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Caisse seulement', 'domaines' => ['facturation']]);
        $employe->update(['role_id' => $role->id]);

        $this->postJson("/api/v1/facturation/ventes/{$vente['id']}/annuler", [], $this->enTetes($caisse))
            ->assertOk()->assertJsonPath('data.statut', 'annulee');
        $this->assertSame(5, $this->stockDe($produit));
    }

    /** Stripe ne peut plus annuler la tentative (le client valide à l'instant) : la vente ne s'annule pas, ni ne passe en espèces. */
    public function test_une_tentative_qui_ne_s_annule_plus_bloque_l_annulation_et_les_especes(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'twint')->json('data');
        $paiement = $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))->json('data');
        $intention = Paiement::query()->findOrFail($paiement['id'])->stripe_payment_intent_id;
        $this->stripe->intentions[$intention]['statut'] = 'processing';
        $this->stripe->erreurs['annulerIntention'] = new \App\Paiements\ErreurPaiement(\App\Paiements\ErreurPaiement::GENERIQUE, 'payment_intent_unexpected_state');

        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/annuler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['vente' => 'Un paiement est en cours de validation : attendez son issue.']);
        $this->postJson("/api/v1/boutique/ventes/{$vente['id']}/especes", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['vente' => 'Un paiement est en cours de validation : attendez son issue.']);
        $this->assertSame('due', Facture::withoutGlobalScopes()->findOrFail($vente['id'])->statut->value);
    }

    /** Annuler la ligne d'un ticket ou en retirer une est refusé aussi (pas seulement l'ajout). */
    public function test_les_lignes_d_un_ticket_du_comptoir_ne_se_modifient_ni_ne_se_retirent(): void
    {
        $produit = $this->produitEnRayon(5);
        $vente = $this->vendre($produit, 'tpe')->json('data');
        $ligne = $vente['lignes'][0]['id'];

        $this->patchJson("/api/v1/facturation/factures/{$vente['id']}/lignes/{$ligne}", ['quantite' => 40], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->deleteJson("/api/v1/facturation/factures/{$vente['id']}/lignes/{$ligne}", [], $this->enTetes($this->jeton))->assertUnprocessable();
    }
}
