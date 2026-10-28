<?php

namespace Tests\Feature\Montre;

use App\Services\Notifications\ApnsPushok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Pushok\Response;
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

    private function configurer(string $keyId = 'ABC123'): void
    {
        config(['services.apns.cle_base64' => base64_encode($this->cleDEssai()), 'services.apns.key_id' => $keyId, 'services.apns.team_id' => '9M257CNL34']);
    }

    /** Apple refuse la signature (expirée, invalide) : elle est oubliée, la suivante est refaite */
    public function test_une_signature_refusee_est_oubliee_et_refaite(): void
    {
        Exceptions::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich'));
        $this->configurer();
        $apns = new ApnsPushok;
        $avant = $apns->autorisation('ch.veterinary.client.watchkitapp')?->get();

        $morts = $apns->lireReponses([new Response(403, '', '{"reason":"ExpiredProviderToken"}', str_repeat('ab', 32))], 'ch.veterinary.client.watchkitapp');
        $this->travel(1)->seconds();

        $this->assertSame([], $morts);
        $this->assertNotSame($avant, $apns->autorisation('ch.veterinary.client.watchkitapp')?->get());
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'ExpiredProviderToken'));
    }

    /** Un jeton qu'Apple ne connaît plus (BadDeviceToken, 410) est rendu pour être oublié */
    public function test_un_jeton_refuse_par_apple_est_rendu_comme_mort(): void
    {
        $morts = (new ApnsPushok)->lireReponses([
            new Response(400, '', '{"reason":"BadDeviceToken"}', 'jeton-faux'),
            new Response(410, '', '{"reason":"Unregistered","timestamp":1}', 'jeton-parti'),
            new Response(200, '', '', 'jeton-bon'),
        ], 'ch.veterinary.client.watchkitapp');

        $this->assertSame(['jeton-faux', 'jeton-parti'], $morts);
    }

    /** Une autre clé (APNS_KEY_ID changé) : son propre jeton signé, pas celui de l'ancienne */
    public function test_une_autre_cle_a_son_propre_jeton_signe(): void
    {
        $this->configurer('ABC123');
        $ancien = (new ApnsPushok)->autorisation('ch.veterinary.client.watchkitapp')?->get();
        $this->configurer('DEF456');
        $nouveau = (new ApnsPushok)->autorisation('ch.veterinary.client.watchkitapp')?->get();

        $this->assertNotSame($ancien, $nouveau);
    }

    /** Du base64 qui n'est pas une clé .p8 : signalé, rien ne part */
    public function test_un_base64_qui_n_est_pas_une_cle_est_signale(): void
    {
        Exceptions::fake();
        config(['services.apns.cle_base64' => base64_encode('bonjour'), 'services.apns.key_id' => 'ABC123']);

        $this->assertNull((new ApnsPushok)->autorisation('ch.veterinary.client.watchkitapp'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'APNS_PRIVATE_KEY_BASE64'));
    }
}
