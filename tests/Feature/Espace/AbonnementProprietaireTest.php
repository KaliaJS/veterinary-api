<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Offre du propriétaire : Gratuit par défaut ; Plus (12 CHF par an, sans
 * TVA) souscrit avec une carte Stripe (double en mémoire), notre facture,
 * retour à Gratuit en fin d'année, réactivation, renouvellement annuel.
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

    public function test_plus_se_souscrit_pour_un_an_avec_une_carte_puis_revient_a_gratuit_en_fin_d_annee(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/espace/abonnement', $entetes)
            ->assertOk()->assertJsonPath('data.formule', 'gratuit')->assertJsonPath('data.prix_annuel', 12)->assertJsonPath('data.factures', []);

        // Sans carte : refusé
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk()->assertJsonStructure(['data' => ['client_secret', 'cle_publique']]);
        $client = $user->refresh()->stripe_client_id;
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $this->stripe->carteConfirmee($client)], $entetes)
            ->assertOk()->assertJsonPath('data.moyen_paiement', 'Visa •••• 4242 · exp. 08/28');

        // 12 CHF pour un an, sans TVA
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.periode_fin', '2027-10-20')
            ->assertJsonPath('data.resilie_le', null)
            ->assertJsonPath('data.factures.0.libelle', 'Abonnement Veterinary Plus, du 20.10.2026 au 19.10.2027')
            ->assertJsonPath('data.factures.0.montant', 12)
            ->assertJsonPath('data.factures.0.statut', 'payee');
        $this->assertSame(1200, $this->stripe->dernierPrelevement()['centimes']);
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('formule');

        // Retour à Gratuit : Plus reste jusqu'à la fin de l'année, réactivable d'ici là
        $this->postJson('/api/v1/espace/abonnement/resilier', [], $entetes)
            ->assertOk()->assertJsonPath('data.formule', 'plus')->assertJsonPath('data.resilie_le', '2027-10-20');
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertOk()->assertJsonPath('data.resilie_le', null);
        $this->postJson('/api/v1/espace/abonnement/resilier', [], $entetes)->assertOk();

        // À l'échéance : retour à Gratuit, rien n'est prélevé
        $this->travelTo(CarbonImmutable::parse('2027-10-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $user->refresh();
        $this->assertSame('gratuit', $user->formule);
        $this->assertSame('resilie', $user->statut_abonnement);
        $this->assertCount(1, $this->stripe->prelevements);
    }

    public function test_plus_se_renouvelle_chaque_annee_au_meme_prix(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $this->stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2027-10-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $user->refresh();
        $this->assertSame('2028-10-20', $user->periode_fin->toDateString());
        $this->assertSame(1200, $this->stripe->dernierPrelevement()['centimes']);
        $this->assertTrue($this->stripe->dernierPrelevement()['hors_session']);
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

    /** D26.1c pour Plus : prélevé malgré une réponse perdue et la liste en panne, le clic suivant retrouve le premier prélèvement, jamais un second (26.10.2026). */
    public function test_plus_preleve_malgre_une_reponse_perdue_n_est_pas_preleve_deux_fois(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $this->stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->listeEnPanne = false;

        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable();

        $this->assertCount(1, $this->stripe->prelevements);
        $this->assertSame(['plus', 'actif'], [$user->refresh()->formule, $user->statut_abonnement]);
    }

    /** Plus résilié pendant que son renouvellement attend une réponse de Stripe : la vérification ne le renvoie pas, rien n'est prélevé (26.10.2026). */
    public function test_plus_resilie_pendant_un_renouvellement_sans_reponse_n_est_pas_preleve(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $this->stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertOk();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->travelTo(CarbonImmutable::parse('2027-10-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2027-10-20 06:30', 'Europe/Zurich'));
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;
        $this->postJson('/api/v1/espace/abonnement/resilier', [], $this->enTetes($jeton))->assertOk();
        $this->stripe->panne = null;

        $this->travel(1)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();

        $this->assertCount($avant, $this->stripe->prelevements);
    }
}
