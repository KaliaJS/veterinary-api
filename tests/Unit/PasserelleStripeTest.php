<?php

namespace Tests\Unit;

use App\Paiements\PasserelleStripe;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Ce que la passerelle envoie vraiment à Stripe (23.10.2026), par un
 * client HTTP qui note les requêtes au lieu de les envoyer.
 */
class PasserelleStripeTest extends TestCase
{
    /** @var list<array{methode: string, url: string, entetes: array<int, string>, params: array<string, mixed>}> */
    private array $requetes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private PasserelleStripeTest $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [$this->test->noter($method, $absUrl, $headers, $params), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /** @internal Appelé par le faux client HTTP */
    public function noter(string $methode, string $url, array $entetes, array $params): string
    {
        $this->requetes[] = ['methode' => $methode, 'url' => $url, 'entetes' => $entetes, 'params' => $params];

        return str_contains($url, '/refunds')
            ? '{"id":"re_test","object":"refund"}'
            : '{"id":"pi_test","object":"payment_intent","status":"requires_payment_method"}';
    }

    private function passerelle(?string $compte = 'acct_societe'): PasserelleStripe
    {
        return (new PasserelleStripe(new StripeClient('sk_test_faux'), null))->pourCompte($compte);
    }

    public function test_un_remboursement_ne_rend_jamais_la_commission(): void
    {
        $this->passerelle()->rembourser('pi_test', 'rendu-1');
        $this->passerelle()->rembourser('pi_test', 'rendu-2', 1500);

        $this->assertCount(2, $this->requetes);
        foreach ($this->requetes as $requete) {
            $this->assertStringEndsWith('/v1/refunds', $requete['url']);
            $this->assertSame('false', $requete['params']['refund_application_fee']);
            $this->assertContains('Stripe-Account: acct_societe', $requete['entetes']);
        }
        $this->assertArrayNotHasKey('amount', $this->requetes[0]['params']);
        $this->assertSame(1500, $this->requetes[1]['params']['amount']);
    }

    public function test_la_commission_est_prise_sur_le_compte_de_la_societe_seulement(): void
    {
        $this->passerelle()->creerIntentionTerminal(10000, ['facture' => '1'], 'cle-1', 250);
        $this->passerelle(null)->creerIntentionTerminal(10000, ['facture' => '1'], 'cle-2', 250);

        $this->assertSame(250, $this->requetes[0]['params']['application_fee_amount']);
        $this->assertArrayNotHasKey('application_fee_amount', $this->requetes[1]['params']);
        $this->assertNotContains('Stripe-Account: acct_societe', $this->requetes[1]['entetes']);
    }
}
