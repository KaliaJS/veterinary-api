<?php

namespace Tests\Feature\Montre;

use App\Services\Notifications\ApnsPushok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * Apple (APNs) : le jeton signé ne se refait pas à chaque envoi (Apple
 * refuse qu'on le change plus d'une fois toutes les 20 minutes) ; une clé
 * illisible se signale au journal, sans jamais l'afficher.
 */
class ApnsPushokTest extends TestCase
{
    private function cleDEssai(): string
    {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($cle);
        openssl_pkey_export($cle, $pem);

        return $pem;
    }

    public function test_le_jeton_signe_est_garde_au_moins_20_minutes_puis_refait(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich'));
        config(['services.apns.cle_base64' => base64_encode($this->cleDEssai()), 'services.apns.key_id' => 'ABC123', 'services.apns.team_id' => '9M257CNL34']);
        $apns = new ApnsPushok;

        $premier = $apns->autorisation('ch.veterinary.client.watchkitapp')?->get();
        $this->travel(25)->minutes();
        $ensuite = $apns->autorisation('ch.veterinary.client.watchkitapp')?->get();
        $this->travel(20)->minutes();
        $refait = $apns->autorisation('ch.veterinary.client.watchkitapp')?->get();

        $this->assertNotNull($premier);
        $this->assertSame($premier, $ensuite);
        $this->assertNotSame($premier, $refait);
    }

    public function test_une_cle_illisible_est_signalee_sans_etre_affichee(): void
    {
        Exceptions::fake();
        config(['services.apns.cle_base64' => 'pas-du-base64 secret-de-jim', 'services.apns.key_id' => 'ABC123', 'services.apns.team_id' => '9M257CNL34']);

        $morts = (new ApnsPushok)->envoyer([str_repeat('ab', 32)], 'sandbox', 'ch.veterinary.client.watchkitapp', ['veterinary' => 'journee']);

        $this->assertSame([], $morts);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'APNS_PRIVATE_KEY_BASE64')
            && ! str_contains($e->getMessage(), 'secret-de-jim'));
    }
}
