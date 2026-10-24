<?php

namespace Tests\Unit;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnementStripe;
use Stripe\ApiRequestor;
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
}
