<?php

namespace Tests\Unit;

use App\Paiements\PasserelleIdentiteStripe;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Tests\Doubles\ClientHttpStripeFactice;
use Tests\TestCase;

/** Compte effacé (27.10.2026) : la session d'identité est effacée chez Stripe (redact). */
class PasserelleIdentiteStripeTest extends TestCase
{
    public function test_effacer_une_session_appelle_redact_chez_stripe(): void
    {
        $http = new ClientHttpStripeFactice;
        ApiRequestor::setHttpClient($http);
        $http->repondre(['id' => 'vs_rachel', 'object' => 'identity.verification_session', 'status' => 'verified', 'redaction' => ['status' => 'processing']]);

        try {
            (new PasserelleIdentiteStripe(new StripeClient('sk_test_faux')))->effacerSession('vs_rachel');
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        $this->assertSame('post', $http->requetes[0]['methode']);
        $this->assertSame('/v1/identity/verification_sessions/vs_rachel/redact', $http->requetes[0]['chemin']);
    }
}
