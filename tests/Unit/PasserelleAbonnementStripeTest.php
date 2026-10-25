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

    /** D26.1 : une erreur de Stripe garde son code de réponse ; une coupure réseau n'en a pas (26.10.2026). */
    public function test_une_erreur_de_stripe_dit_s_il_a_repondu(): void
    {
        $this->http->echouer(500, 'api_error', null, 'Something went wrong on Stripe.');
        try {
            $this->prelever(true);
            $this->fail('Erreur attendue');
        } catch (ErreurPaiement $e) {
            $this->assertSame(500, $e->statutHttp);
            $this->assertTrue($e->prestataireARepondu());
        }

        $this->http->couper(new ApiConnectionException('Délai dépassé'));
        try {
            $this->prelever(true);
            $this->fail('Erreur attendue');
        } catch (ErreurPaiement $e) {
            $this->assertNull($e->statutHttp);
            $this->assertFalse($e->prestataireARepondu());
        }
    }

    /** D26.1c : les prélèvements d'une facture se cherchent par sa métadonnée, les plus récents d'abord (26.10.2026). */
    public function test_les_prelevements_d_une_facture_se_cherchent_par_sa_metadonnee(): void
    {
        $this->http->repondre(['object' => 'search_result', 'data' => [
            ['id' => 'pi_ancien', 'object' => 'payment_intent', 'status' => 'canceled', 'created' => 100],
            ['id' => 'pi_recent', 'object' => 'payment_intent', 'status' => 'succeeded', 'created' => 200],
        ], 'has_more' => false]);

        $trouves = (new PasserelleAbonnementStripe(new StripeClient('sk_test_faux')))->chercherPrelevements('organisation:12');

        $requete = $this->http->derniere();
        $this->assertSame('/v1/payment_intents/search', $requete['chemin']);
        $this->assertSame("metadata['facture']:'organisation:12'", $requete['params']['query']);
        $this->assertSame(['pi_recent', 'pi_ancien'], array_column($trouves, 'id'));
        $this->assertSame('reussi', $trouves[0]['statut']);
    }
}
