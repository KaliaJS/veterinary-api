<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Services\Abonnements\AchatsAppStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\AppStoreFactice;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Achats dans l'app iPhone (StoreKit 2) : transactions signées vérifiées
 * contre la racine de confiance, réservées au compte qui les a passées ;
 * Plus d'Apple à côté de celui de Stripe ; objets posés sur la planète ;
 * notifications serveur (renouvellement, fin, remboursement).
 */
class AchatsAppStoreTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private AppStoreFactice $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new AppStoreFactice;
    }

    /** @return array{user: User, entetes: array, compte: string} */
    private function compte(): array
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $compte = $this->getJson('/api/v1/espace/app-store', $entetes)->assertOk()->json('data.compte');

        return ['user' => $user, 'entetes' => $entetes, 'compte' => $compte];
    }

    public function test_le_compte_et_les_produits_au_prix_du_site_majore_de_30_pourcents(): void
    {
        ['entetes' => $entetes, 'compte' => $compte] = $this->compte();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $compte);
        $this->getJson('/api/v1/espace/app-store', $entetes)
            ->assertOk()
            ->assertJsonPath('data.compte', $compte)
            ->assertJsonPath('data.produits.plus.id', 'ch.veterinary.client.plus')
            ->assertJsonPath('data.produits.plus.prix_reference', 1.3)
            ->assertJsonPath('data.produits.articles.balle.id', 'ch.veterinary.client.planete.balle')
            ->assertJsonPath('data.produits.articles.balle.prix_reference', 0.65)
            ->assertJsonPath('data.produits.articles.banc.prix_reference', 1.95)
            ->assertJsonPath('data.produits.articles.niche.prix_reference', 2.6);
    }

    public function test_plus_paye_par_l_app_store_s_active_et_bloque_plus_par_stripe(): void
    {
        $this->app->instance(PasserelleAbonnement::class, new PasserelleAbonnementFactice);
        ['user' => $user, 'entetes' => $entetes, 'compte' => $compte] = $this->compte();
        $fin = now()->addMonth();

        $jws = $this->store->transaction(AchatsAppStore::produitPlus(), $compte, ['expiresDate' => $fin->getTimestampMs()]);
        $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$jws]], $entetes)->assertOk()->assertJsonPath('data.enregistrees', 1);

        $this->getJson('/api/v1/espace/abonnement', $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.source', 'app_store')
            ->assertJsonPath('data.app_store.renouvelle', true);
        $this->assertSame('2000000001', $user->refresh()->apple_abonnement_id);
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('formule');
    }

    public function test_un_objet_achete_dans_l_app_se_pose_et_part_s_il_est_rembourse(): void
    {
        ['entetes' => $entetes, 'compte' => $compte] = $this->compte();
        $jws = $this->store->transaction('ch.veterinary.client.planete.niche', $compte, ['originalTransactionId' => '3000000042']);

        $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$jws, $jws]], $entetes)->assertOk();
        $this->getJson('/api/v1/espace/planete', $entetes)->assertOk()->assertJsonPath('data.possedes', ['niche']);

        $rembourse = $this->store->transaction('ch.veterinary.client.planete.niche', $compte, ['originalTransactionId' => '3000000042', 'revocationDate' => now()->getTimestampMs()]);
        $this->postJson('/api/v1/app-store/notifications', ['signedPayload' => $this->store->notification('REFUND', $rembourse)])->assertOk();
        $this->getJson('/api/v1/espace/planete', $entetes)->assertOk()->assertJsonPath('data.possedes', []);
    }

    public function test_les_notifications_suivent_la_resiliation_puis_la_fin_de_plus(): void
    {
        ['user' => $user, 'entetes' => $entetes, 'compte' => $compte] = $this->compte();
        $plus = AchatsAppStore::produitPlus();
        $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$this->store->transaction($plus, $compte, ['expiresDate' => now()->addDays(10)->getTimestampMs()])]], $entetes)->assertOk();

        $resiliation = $this->store->notification('DID_CHANGE_RENEWAL_STATUS', $this->store->transaction($plus, $compte, ['expiresDate' => now()->addDays(10)->getTimestampMs()]), ['autoRenewStatus' => 0, 'productId' => $plus]);
        $this->postJson('/api/v1/app-store/notifications', ['signedPayload' => $resiliation])->assertOk();
        $this->getJson('/api/v1/espace/abonnement', $entetes)->assertJsonPath('data.formule', 'plus')->assertJsonPath('data.app_store.renouvelle', false);

        // Onze jours plus tard (nouvelle session : la précédente a expiré)
        $this->travel(11)->days();
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;
        $this->getJson('/api/v1/espace/abonnement', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.formule', 'gratuit')->assertJsonPath('data.source', null);
    }

    public function test_une_transaction_d_un_autre_compte_ou_mal_signee_est_refusee(): void
    {
        ['entetes' => $entetes, 'compte' => $compte] = $this->compte();
        $balle = 'ch.veterinary.client.planete.balle';
        $refuse = fn (string $jws) => $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$jws]], $entetes)->assertUnprocessable()->assertJsonValidationErrors('jws');

        $refuse($this->store->transaction($balle, (string) str()->uuid()));
        $refuse($this->store->transaction($balle, $compte, ['bundleId' => 'com.autre.app']));
        $refuse($this->store->transaction($balle, $compte, ['environment' => 'Xcode']));
        $refuse($this->store->transaction('ch.veterinary.client.licorne', $compte));
        $refuse((new AppStoreFactice(estDeConfiance: false))->transaction($balle, $compte));
        [$entete, , $signature] = explode('.', $this->store->transaction($balle, $compte));
        $falsifie = rtrim(strtr(base64_encode(json_encode(['productId' => $balle, 'appAccountToken' => $compte, 'bundleId' => 'ch.veterinary.client', 'environment' => 'Sandbox'])), '+/', '-_'), '=');
        $refuse($entete.'.'.$falsifie.'.'.$signature);

        $this->getJson('/api/v1/espace/planete', $entetes)->assertOk()->assertJsonPath('data.possedes', []);
        $this->postJson('/api/v1/app-store/notifications', ['signedPayload' => 'pas.un.jws'])->assertStatus(400);
    }

    public function test_les_achats_de_test_d_xcode_ne_passent_qu_en_local_sur_option(): void
    {
        ['entetes' => $entetes, 'compte' => $compte] = $this->compte();
        $xcode = new AppStoreFactice(estDeConfiance: false);
        $jws = $xcode->transaction('ch.veterinary.client.planete.balle', $compte, ['environment' => 'Xcode']);

        $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$jws]], $entetes)->assertUnprocessable();

        config(['services.apple.accepte_xcode' => true]);
        $this->postJson('/api/v1/espace/app-store/transactions', ['transactions' => [$jws]], $entetes)->assertOk();
        $this->getJson('/api/v1/espace/planete', $entetes)->assertOk()->assertJsonPath('data.possedes', ['balle']);
    }
}
