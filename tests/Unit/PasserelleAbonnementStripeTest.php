<?php

namespace Tests\Unit;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnementStripe;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Doubles\ClientHttpStripeFactice;
use Tests\TestCase;

/**
 * La vraie passerelle des abonnements, sans réseau : la clé d'un prélèvement
 * resservie par l'autre mode (essai de la nuit sans réponse, puis « Régler »)
 * rend la réponse du premier essai, sans second prélèvement (24.10.2026).
 */
class PasserelleAbonnementStripeTest extends TestCase
{
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

    private function prelever(bool $horsSession): array
    {
        return (new PasserelleAbonnementStripe(new StripeClient('sk_test_faux')))
            ->prelever('cus_test', 'pm_test', 4900, 'F-1 · Abonnement', ['facture' => 'organisation:1'], $horsSession, 'facture-organisation:1-premier-pm_test');
    }

    public function test_regler_apres_un_essai_de_nuit_sans_reponse_rend_la_reponse_de_l_essai(): void
    {
        $this->http
            ->echouer(400, 'idempotency_error', null, 'Keys for idempotent requests can only be used with the same parameters they were first used with.')
            ->repondre(['id' => 'pi_nuit', 'object' => 'payment_intent', 'status' => 'succeeded', 'client_secret' => 'pi_nuit_secret']);

        $resultat = $this->prelever(false);

        $this->assertSame('pi_nuit', $resultat['id']);
        $this->assertSame('reussi', $resultat['statut']);
        $this->assertCount(2, $this->http->requetes);
        [$regler, $rejoue] = $this->http->requetes;
        $this->assertSame($regler['entetes']['Idempotency-Key'], $rejoue['entetes']['Idempotency-Key']);
        $this->assertSame('false', $regler['params']['off_session']);
        $this->assertSame('true', $rejoue['params']['off_session']);
    }

    public function test_une_cle_refusee_dans_les_deux_modes_reste_une_erreur(): void
    {
        $this->http
            ->echouer(400, 'idempotency_error', null)
            ->echouer(400, 'idempotency_error', null);

        $this->expectException(ErreurPaiement::class);
        $this->prelever(true);
    }

    /** D26.1 : seule une erreur de Stripe lui-même (5xx) use la clé ; un 409 (même clé en cours) ou une coupure réseau, non (26.10.2026). */
    public function test_seule_une_erreur_5xx_use_la_cle(): void
    {
        $verdict = function (callable $programmer): array {
            $programmer();
            try {
                $this->prelever(true);
            } catch (ErreurPaiement $e) {
                return [$e->statutHttp, $e->erreurGardeeParStripe()];
            }
            $this->fail('Erreur attendue');
        };

        $this->assertSame([500, true], $verdict(fn () => $this->http->echouer(500, 'api_error', null)));
        $this->assertSame([409, false], $verdict(fn () => $this->http->echouer(409, 'idempotency_error', null, 'There is currently another in-progress request using this Idempotent Key.')));
        $this->assertSame([null, false], $verdict(fn () => $this->http->couper(new ApiConnectionException('Délai dépassé'))));
    }

    /** D26.1c : les prélèvements d'une facture se lisent dans la liste du client (à jour tout de suite), filtrés par facture, les plus récents d'abord (26.10.2026). */
    public function test_les_prelevements_d_une_facture_se_lisent_dans_la_liste_du_client(): void
    {
        $this->http->repondre(['object' => 'list', 'data' => [
            ['id' => 'pi_ancien', 'object' => 'payment_intent', 'status' => 'canceled', 'created' => 100, 'metadata' => ['facture' => 'organisation:12']],
            ['id' => 'pi_autre', 'object' => 'payment_intent', 'status' => 'succeeded', 'created' => 300, 'metadata' => ['facture' => 'organisation:13']],
            ['id' => 'pi_recent', 'object' => 'payment_intent', 'status' => 'succeeded', 'created' => 200, 'metadata' => ['facture' => 'organisation:12']],
        ], 'has_more' => false]);

        $trouves = (new PasserelleAbonnementStripe(new StripeClient('sk_test_faux')))->chercherPrelevements('cus_test', 'organisation:12');

        $requete = $this->http->derniere();
        $this->assertSame('get', $requete['methode']);
        $this->assertSame('/v1/payment_intents', $requete['chemin']);
        $this->assertSame('cus_test', $requete['params']['customer']);
        $this->assertSame(['pi_recent', 'pi_ancien'], array_column($trouves, 'id'));
        $this->assertSame('reussi', $trouves[0]['statut']);
    }
}
