<?php

namespace Tests\Unit;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleStripe;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Doubles\ClientHttpStripeFactice;
use Tests\TestCase;

/**
 * La vraie passerelle Stripe, sans réseau (audit du 23.10.2026) : chaque
 * requête d'une société porte son compte (Stripe-Account), la commission
 * part en frais d'application, les créations et remboursements portent
 * leur clé d'idempotence, et toute erreur de Stripe devient une
 * `ErreurPaiement` lisible. Les webhooks sont vérifiés par leur signature.
 */
class PasserelleStripeRequetesTest extends TestCase
{
    private const COMPTE = 'acct_societe';

    private ClientHttpStripeFactice $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new ClientHttpStripeFactice;
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function passerelle(?string $compte = self::COMPTE, ?string $secret = null, ?string $secretComptes = null): PasserelleStripe
    {
        return (new PasserelleStripe(new StripeClient('sk_test_faux'), $secret, $secretComptes))->pourCompte($compte);
    }

    public function test_chaque_requete_d_une_societe_porte_son_compte(): void
    {
        $societe = $this->passerelle();

        $societe->creerIntentionTerminal(5000, ['facture_id' => '1'], 'cle-terminal', 25);
        $societe->creerIntentionTwint(5000, ['facture_id' => '1'], 'https://app.test/retour', 'cle-twint', 25);
        $societe->creerIntentionEnLigne(3000, ['objet' => 'acompte_rdv'], 'cle-en-ligne', 15);
        $societe->presenterSurLecteur('tmr_test', 'pi_test');
        $societe->annulerSurLecteur('tmr_test');
        $societe->lireIntention('pi_test');
        $societe->annulerIntention('pi_test');
        $societe->rembourser('pi_test', 'cle-rendu');
        $societe->fraisIntention('pi_test');
        $societe->creerLocation('Clinique du Lac', ['rue' => 'Quai 1', 'npa' => '1003', 'ville' => 'Lausanne'], ['clinique_id' => '1']);
        $societe->appairerLecteur('simulated-wpe', 'Comptoir', 'tml_test');
        $societe->lireLecteur('tmr_test');
        $societe->supprimerLecteur('tmr_test');
        $societe->simulerCarte('tmr_test');

        $this->assertCount(14, $this->http->requetes);
        foreach ($this->http->requetes as $requete) {
            $this->assertSame(self::COMPTE, $requete['entetes']['Stripe-Account'] ?? null, $requete['methode'].' '.$requete['chemin']);
        }

        // La copie n'a pas changé la passerelle d'origine : la plateforme reste sans en-tête
        $this->passerelle(null)->lireIntention('pi_test');
        $this->assertArrayNotHasKey('Stripe-Account', $this->http->derniere()['entetes']);
    }

    public function test_les_comptes_et_leurs_sessions_restent_au_niveau_de_la_plateforme(): void
    {
        // Même depuis une copie sur un compte : on ouvre, lit et gère les comptes en tant que plateforme
        $societe = $this->passerelle();

        $this->assertSame('acct_nouveau', $societe->creerComptePaiement([
            'nom' => 'Deux Rives Sàrl', 'email' => 'admin@deuxrives.ch', 'metadonnees' => ['societe_id' => '1'],
        ]));
        $this->assertSame(['paiements' => true, 'virements' => true, 'a_completer' => false], $societe->lireComptePaiement('acct_autre'));
        $this->assertSame('accs_secret_test', $societe->creerSessionCompte('acct_autre', ['payments'], remboursements: false));

        [$creation, $lecture, $session] = $this->http->requetes;
        foreach ($this->http->requetes as $requete) {
            $this->assertArrayNotHasKey('Stripe-Account', $requete['entetes'], $requete['chemin']);
        }
        $this->assertSame(['post', '/v2/core/accounts', 'v2'], [$creation['methode'], $creation['chemin'], $creation['mode']]);
        // Frais et pertes chez le prestataire, jamais chez Veterinary
        $this->assertSame(['fees_collector' => 'stripe', 'losses_collector' => 'stripe'], $creation['params']['defaults']['responsibilities']);
        $this->assertSame('/v1/accounts/acct_autre', $lecture['chemin']);
        $this->assertSame('acct_autre', $session['params']['account']);
        // Sans le droit de rembourser, ni remboursement ni litige dans le composant
        $this->assertSame('false', $session['params']['components']['payments']['features']['refund_management']);
        $this->assertSame('false', $session['params']['components']['payments']['features']['dispute_management']);
    }

    public function test_la_commission_part_en_frais_d_application_sur_les_trois_intentions(): void
    {
        $societe = $this->passerelle();
        $societe->creerIntentionTerminal(12000, [], 'cle-1', 60);
        $societe->creerIntentionTwint(12000, [], 'https://app.test/retour', 'cle-2', 60);
        $societe->creerIntentionEnLigne(3000, [], 'cle-3', 15);
        // Pas de commission : pas de frais d'application du tout
        $societe->creerIntentionEnLigne(3000, [], 'cle-4', 0);
        // Sur la plateforme : jamais de frais d'application (elle se paierait elle-même)
        $this->passerelle(null)->creerIntentionTwint(12000, [], 'https://app.test/retour', 'cle-5', 60);

        $params = array_column($this->http->requetes, 'params');
        $this->assertSame([60, 60, 15], array_column(array_slice($params, 0, 3), 'application_fee_amount'));
        $this->assertArrayNotHasKey('application_fee_amount', $params[3]);
        $this->assertArrayNotHasKey('application_fee_amount', $params[4]);
        foreach ($params as $intention) {
            $this->assertSame('chf', $intention['currency']);
        }
        $this->assertSame([12000, 12000, 3000, 3000, 12000], array_column($params, 'amount'));
    }

    public function test_les_creations_et_les_remboursements_portent_leur_cle_d_idempotence(): void
    {
        $societe = $this->passerelle();
        $societe->creerIntentionTerminal(5000, [], 'facture-7-terminal');
        $societe->creerIntentionTwint(5000, [], 'https://app.test/retour', 'facture-7-twint');
        $societe->creerIntentionEnLigne(3000, [], 'acompte-3');
        $societe->rembourser('pi_test', 'rendu-9');
        $societe->rembourser('pi_test', 'remboursement-acompte-3', 2868);

        $this->assertSame(
            ['facture-7-terminal', 'facture-7-twint', 'acompte-3', 'rendu-9', 'remboursement-acompte-3'],
            array_map(fn (array $requete) => $requete['entetes']['Idempotency-Key'] ?? null, $this->http->requetes),
        );

        // Les lectures n'en ont pas besoin
        $societe->lireIntention('pi_test');
        $this->assertArrayNotHasKey('Idempotency-Key', $this->http->derniere()['entetes']);
    }

    public function test_un_remboursement_partiel_ou_entier_ne_rend_jamais_la_commission(): void
    {
        $this->passerelle()->rembourser('pi_entier', 'rendu-1');
        $this->passerelle()->rembourser('pi_partiel', 'rendu-2', 2868);
        // Même un paiement de la plateforme (aucun aujourd'hui) garderait sa règle
        $this->passerelle(null)->rembourser('pi_plateforme', 'rendu-3');

        [$entier, $partiel, $plateforme] = array_column($this->http->requetes, 'params');
        $this->assertSame(['payment_intent' => 'pi_entier', 'refund_application_fee' => 'false'], $entier);
        $this->assertSame(['payment_intent' => 'pi_partiel', 'amount' => 2868, 'refund_application_fee' => 'false'], $partiel);
        $this->assertSame('false', $plateforme['refund_application_fee']);
    }

    public function test_chaque_intention_demande_le_bon_moyen_de_paiement(): void
    {
        $this->http
            ->repondre(['id' => 'pi_tpe', 'object' => 'payment_intent', 'status' => 'requires_payment_method'])
            ->repondre(['id' => 'pi_twint', 'object' => 'payment_intent', 'status' => 'requires_action', 'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://pm-redirects.stripe.com/twint/pi_twint']]])
            ->repondre(['id' => 'pi_acompte', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'client_secret' => 'pi_acompte_secret_x']);

        $societe = $this->passerelle();
        $this->assertSame(['id' => 'pi_tpe', 'statut' => 'requires_payment_method'], $societe->creerIntentionTerminal(5000, ['facture_id' => '7'], 'cle-1', 25));
        $this->assertSame(
            ['id' => 'pi_twint', 'statut' => 'requires_action', 'url' => 'https://pm-redirects.stripe.com/twint/pi_twint'],
            $societe->creerIntentionTwint(5000, ['facture_id' => '7'], 'https://app.test/paiement-termine', 'cle-2', 25),
        );
        $this->assertSame(
            ['id' => 'pi_acompte', 'statut' => 'requires_payment_method', 'client_secret' => 'pi_acompte_secret_x'],
            $societe->creerIntentionEnLigne(3000, ['objet' => 'acompte_rdv'], 'cle-3', 15),
        );

        [$terminal, $twint, $enLigne] = array_column($this->http->requetes, 'params');
        $this->assertSame(['card_present'], $terminal['allowed_payment_method_types']);
        $this->assertSame('automatic', $terminal['capture_method']);
        $this->assertSame(['facture_id' => '7'], $terminal['metadata']);
        $this->assertSame(['twint'], $twint['allowed_payment_method_types']);
        $this->assertSame(['type' => 'twint'], $twint['payment_method_data']);
        $this->assertSame(['true', 'https://app.test/paiement-termine'], [$twint['confirm'], $twint['return_url']]);
        $this->assertSame(['enabled' => 'true'], $enLigne['automatic_payment_methods']);
        $this->assertSame(['objet' => 'acompte_rdv'], $enLigne['metadata']);
    }

    public function test_les_frais_d_une_intention_sont_lus_sur_sa_transaction(): void
    {
        $this->http
            ->repondre(['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => [
                'id' => 'ch_1', 'object' => 'charge', 'balance_transaction' => ['id' => 'txn_1', 'object' => 'balance_transaction', 'fee' => 132],
            ]])
            ->repondre(['id' => 'pi_2', 'object' => 'payment_intent', 'status' => 'processing', 'latest_charge' => null]);

        $this->assertSame(132, $this->passerelle()->fraisIntention('pi_1'));
        // Pas encore payée : les frais ne sont pas connus
        $this->assertNull($this->passerelle()->fraisIntention('pi_2'));

        $lecture = $this->http->requetes[0];
        $this->assertSame(['get', '/v1/payment_intents/pi_1'], [$lecture['methode'], $lecture['chemin']]);
        $this->assertSame(['latest_charge.balance_transaction'], $lecture['params']['expand']);
        $this->assertSame(self::COMPTE, $lecture['entetes']['Stripe-Account']);
    }

    public function test_une_intention_refusee_rend_le_motif_du_refus(): void
    {
        $this->http
            ->repondre(['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']])
            ->repondre(['id' => 'pi_2', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'stolen_card', 'message' => 'Your card was declined.']]);

        // En français d'après le code ; une carte volée n'est qu'un refus
        $this->assertSame(
            ['id' => 'pi_1', 'statut' => 'requires_payment_method', 'erreur' => 'Fonds insuffisants sur la carte.'],
            $this->passerelle()->lireIntention('pi_1'),
        );
        $this->assertSame('Paiement refusé par la banque.', $this->passerelle()->lireIntention('pi_2')['erreur']);
    }

    public function test_les_erreurs_de_stripe_deviennent_des_erreurs_du_domaine(): void
    {
        $cas = [
            // [statut HTTP, type, code Stripe, message attendu]
            [400, 'invalid_request_error', 'terminal_reader_busy', 'Le terminal est déjà occupé par un autre paiement.'],
            [400, 'invalid_request_error', 'terminal_reader_timeout', 'Le terminal n’a pas répondu à temps. Réessayez.'],
            [400, 'invalid_request_error', 'payment_method_unactivated', 'Ce moyen de paiement n’est pas encore activé sur le compte de paiement.'],
            [402, 'card_error', 'card_declined', ErreurPaiement::GENERIQUE],
            [400, 'idempotency_error', null, ErreurPaiement::GENERIQUE],
            [400, 'invalid_request_error', 'charge_already_refunded', ErreurPaiement::GENERIQUE],
            [401, 'invalid_request_error', null, ErreurPaiement::GENERIQUE],
            [403, 'invalid_request_error', 'account_invalid', ErreurPaiement::GENERIQUE],
            [429, 'invalid_request_error', 'rate_limit', ErreurPaiement::GENERIQUE],
            [500, 'api_error', null, ErreurPaiement::GENERIQUE],
        ];

        foreach ($cas as [$statut, $type, $code, $attendu]) {
            $this->http->echouer($statut, $type, $code, 'Raw Stripe message: see https://stripe.com/docs');
            try {
                $this->passerelle()->rembourser('pi_test', 'rendu-'.$statut.'-'.$code);
                $this->fail("L'erreur {$statut} {$code} n'a pas été levée.");
            } catch (ErreurPaiement $e) {
                $this->assertSame($attendu, $e->getMessage(), "{$statut} {$code}");
                $this->assertSame($code, $e->codeStripe, "{$statut} {$code}");
                $this->assertStringNotContainsString('stripe.com', $e->getMessage());
            }
        }
    }

    public function test_un_reseau_coupe_donne_le_message_generique_et_le_detail_au_journal(): void
    {
        Log::spy();
        $this->http->couper(new ApiConnectionException('Could not connect to Stripe (https://api.stripe.com/v1/payment_intents).'));

        try {
            $this->passerelle()->creerIntentionTerminal(5000, [], 'cle-1', 25);
            $this->fail('La coupure n’a pas été signalée.');
        } catch (ErreurPaiement $e) {
            $this->assertTrue($e->estGenerique());
            $this->assertNull($e->codeStripe);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $contexte) => $message === 'Stripe a refusé un appel.'
            && str_contains($contexte['message'], 'Could not connect'));
    }

    public function test_un_lecteur_disparu_garde_son_code_pour_etre_retire(): void
    {
        $this->http->echouer(404, 'invalid_request_error', 'resource_missing', 'No such terminal.reader: tmr_test');

        try {
            $this->passerelle()->supprimerLecteur('tmr_test');
            $this->fail('Le lecteur disparu n’a pas été signalé.');
        } catch (ErreurPaiement $e) {
            // TerminauxPaiement::retirer s'appuie sur ce code pour retirer quand même
            $this->assertSame('resource_missing', $e->codeStripe);
            $this->assertSame('Ce terminal n’existe plus. Retirez-le et appairez-le à nouveau.', $e->getMessage());
        }
    }

    public function test_un_webhook_se_lit_avec_le_secret_de_la_plateforme_ou_celui_des_comptes(): void
    {
        $passerelle = $this->passerelle(null, 'whsec_plateforme', 'whsec_comptes');

        $deCompte = $this->evenement('evt_compte', 'payment_intent.succeeded', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'metadata' => ['objet' => 'acompte_rdv']], self::COMPTE);
        $lu = $passerelle->lireEvenement($deCompte, $this->signature($deCompte, 'whsec_comptes'));
        $this->assertSame(['evt_compte', 'payment_intent.succeeded', self::COMPTE], [$lu['id'], $lu['type'], $lu['compte']]);
        $this->assertSame(['objet' => 'acompte_rdv'], $lu['objet']['metadata']);

        $dePlateforme = $this->evenement('evt_plateforme', 'identity.verification_session.verified', ['id' => 'vs_1', 'object' => 'identity.verification_session']);
        $lu = $passerelle->lireEvenement($dePlateforme, $this->signature($dePlateforme, 'whsec_plateforme'));
        $this->assertSame(['evt_plateforme', null, 'vs_1'], [$lu['id'], $lu['compte'], $lu['objet']['id']]);

        // Aucun appel à Stripe pour lire un webhook
        $this->assertSame([], $this->http->requetes);
    }

    public function test_un_webhook_mal_signe_perime_ou_illisible_est_refuse(): void
    {
        $passerelle = $this->passerelle(null, 'whsec_plateforme', 'whsec_comptes');
        $corps = $this->evenement('evt_1', 'payment_intent.succeeded', ['id' => 'pi_1', 'object' => 'payment_intent']);

        $refus = [
            'autre secret' => [$corps, $this->signature($corps, 'whsec_inconnu')],
            'corps modifié' => [str_replace('pi_1', 'pi_2', $corps), $this->signature($corps, 'whsec_plateforme')],
            'trop ancien' => [$corps, $this->signature($corps, 'whsec_plateforme', time() - 3600)],
            'sans signature' => [$corps, ''],
            'illisible' => ['{pas du json', $this->signature('{pas du json', 'whsec_plateforme')],
        ];
        foreach ($refus as $cas => [$contenu, $signature]) {
            try {
                $passerelle->lireEvenement($contenu, $signature);
                $this->fail("Webhook accepté : {$cas}.");
            } catch (ErreurPaiement $e) {
                $this->assertSame('Signature de webhook invalide.', $e->getMessage(), $cas);
            }
        }

        // Aucun secret configuré : rien n'est accepté
        $this->expectException(ErreurPaiement::class);
        $this->passerelle(null)->lireEvenement($corps, $this->signature($corps, ''));
    }

    /** @param  array<string, mixed>  $objet */
    private function evenement(string $id, string $type, array $objet, ?string $compte = null): string
    {
        return (string) json_encode(array_filter([
            'id' => $id, 'object' => 'event', 'type' => $type, 'account' => $compte, 'api_version' => '2026-09-30.preview',
            'created' => time(), 'data' => ['object' => $objet],
        ], fn ($valeur) => $valeur !== null));
    }

    private function signature(string $corps, string $secret, ?int $horodatage = null): string
    {
        $horodatage ??= time();

        return 't='.$horodatage.',v1='.hash_hmac('sha256', $horodatage.'.'.$corps, $secret);
    }
}
