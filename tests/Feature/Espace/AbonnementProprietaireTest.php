<?php

namespace Tests\Feature\Espace;

use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Offre du propriétaire : Gratuit par défaut ; Plus (1 CHF par mois TTC)
 * souscrit avec une carte Stripe (double en mémoire), facture reflétée,
 * retour à Gratuit en fin de période, réactivation, fin réelle par webhook.
 */
class AbonnementProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $this->stripe);
    }

    public function test_plus_se_souscrit_avec_une_carte_puis_revient_a_gratuit_en_fin_de_periode(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/espace/abonnement', $entetes)
            ->assertOk()->assertJsonPath('data.formule', 'gratuit')->assertJsonPath('data.prix_mensuel', 1)->assertJsonPath('data.factures', []);

        // Sans carte : refusé
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk()->assertJsonStructure(['data' => ['client_secret', 'cle_publique']]);
        $client = $user->refresh()->stripe_client_id;
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $this->stripe->carteConfirmee($client)], $entetes)
            ->assertOk()->assertJsonPath('data.moyen_paiement', 'Visa •••• 4242 · exp. 08/28');

        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.resilie_le', null)
            ->assertJsonPath('data.factures.0.libelle', 'Abonnement Plus, mensuel')
            ->assertJsonPath('data.factures.0.montant', 1)
            ->assertJsonPath('data.factures.0.montant_ht', 0.93)
            ->assertJsonPath('data.factures.0.statut', 'payee');
        $this->assertSame(100, $this->stripe->prix['veterinary-plus-mois']);
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('formule');

        // Retour à Gratuit : Plus reste jusqu'à la fin de la période, puis se réactive au besoin
        $fin = now()->addMonth()->toDateString();
        $this->postJson('/api/v1/espace/abonnement/resilier', [], $entetes)
            ->assertOk()->assertJsonPath('data.formule', 'plus')->assertJsonPath('data.resilie_le', $fin);
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertOk()->assertJsonPath('data.resilie_le', null);

        // Fin réelle (webhook) : retour à Gratuit
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $abonnement = $user->refresh()->stripe_abonnement_id;
        $this->stripe->abonnements[$abonnement]['status'] = 'canceled';
        $this->postJson('/api/v1/stripe/webhook', ['id' => 'evt_plus', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => $abonnement, 'customer' => $client]]], ['Stripe-Signature' => 'signature-valide'])
            ->assertNoContent();
        $this->getJson('/api/v1/espace/abonnement', $entetes)->assertOk()->assertJsonPath('data.formule', 'gratuit')->assertJsonPath('data.statut', 'resilie');
    }

    public function test_la_carte_d_un_autre_compte_est_refusee(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $etrangere = $this->stripe->carteConfirmee($this->stripe->creerClient('Autre', null, []));

        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $etrangere], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, User::query()->whereNotNull('moyen_paiement')->count());
    }
}
