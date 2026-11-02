<?php

namespace Tests\Feature\Montre;

use App\Services\Notifications\DirectApns;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Apple (APNs) en direct, sans paquet (choix de Jim, 02.11.2026) : HTTP/2,
 * jeton signé ES256 gardé au moins 20 minutes (Apple refuse qu'on le change
 * plus souvent) ; une clé illisible se signale sans jamais s'afficher ; les
 * jetons qu'Apple ne connaît plus sont rendus pour être oubliés.
 */
class DirectApnsTest extends TestCase
{
    private ?\OpenSSLAsymmetricKey $cle = null;

    private function configurer(string $keyId = 'ABC123'): void
    {
        $this->cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($this->cle, $pem);
        config(['services.apns.cle_base64' => base64_encode($pem), 'services.apns.key_id' => $keyId, 'services.apns.team_id' => '9M257CNL34']);
    }

    /** Le JWT vérifié avec la clé publique : ES256, R‖S de 64 octets */
    private function verifier(string $jwt): array
    {
        [$h, $c, $s] = explode('.', $jwt);
        $brut = base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame(64, strlen($brut));
        $entier = fn (string $x): string => ($x = ltrim($x, "\x00")) === '' ? "\x00" : (ord($x[0]) & 0x80 ? "\x00".$x : $x);
        [$r, $ss] = [$entier(substr($brut, 0, 32)), $entier(substr($brut, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($ss))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($ss)).$ss;
        $publique = openssl_pkey_get_details($this->cle)['key'];
        $this->assertSame(1, openssl_verify($h.'.'.$c, $der, $publique, OPENSSL_ALGO_SHA256));

        return [json_decode(base64_decode(strtr($h, '-_', '+/')), true), json_decode(base64_decode(strtr($c, '-_', '+/')), true)];
    }

    public function test_une_notification_affichee_part_en_direct_chez_apple(): void
    {
        $this->configurer();
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

        $morts = (new DirectApns)->envoyer([str_repeat('ab', 32)], 'production', 'ch.veterinary.client', ['aps' => ['alert' => ['title' => 'Deux Rives', 'body' => 'Consentement à signer pour Bagheera'], 'sound' => 'default']], visible: true);

        $this->assertSame([], $morts);
        Http::assertSent(function (Request $r): bool {
            [$entete, $revendications] = $this->verifier(str_replace('bearer ', '', $r->header('authorization')[0]));

            return $r->url() === 'https://api.push.apple.com/3/device/'.str_repeat('ab', 32)
                && $r->header('apns-topic')[0] === 'ch.veterinary.client'
                && $r->header('apns-push-type')[0] === 'alert'
                && $r->header('apns-priority')[0] === '10'
                && $entete === ['alg' => 'ES256', 'kid' => 'ABC123']
                && $revendications['iss'] === '9M257CNL34'
                && $r['aps']['alert']['body'] === 'Consentement à signer pour Bagheera';
        });
    }

    public function test_le_reveil_de_la_montre_est_invisible_et_part_au_bac_a_sable_pour_xcode(): void
    {
        $this->configurer();
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response('', 200)]);

        (new DirectApns)->envoyer(['jeton-montre'], 'sandbox', 'ch.veterinary.client.watchkitapp', ['aps' => ['content-available' => 1], 'veterinary' => 'journee']);

        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.sandbox.push.apple.com/3/device/jeton-montre')
            && $r->header('apns-push-type')[0] === 'background' && $r->header('apns-priority')[0] === '5');
    }

    public function test_le_jeton_signe_est_garde_au_moins_20_minutes_puis_refait(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich'));
        $this->configurer();
        $apns = new DirectApns;

        $premier = $apns->autorisation('ch.veterinary.client.watchkitapp');
        $this->travel(25)->minutes();
        $ensuite = $apns->autorisation('ch.veterinary.client.watchkitapp');
        $this->travel(20)->minutes();
        $refait = $apns->autorisation('ch.veterinary.client.watchkitapp');

        $this->assertNotNull($premier);
        $this->assertSame($premier, $ensuite);
        $this->assertNotSame($premier, $refait);
    }

    public function test_une_cle_illisible_est_signalee_sans_etre_affichee(): void
    {
        Exceptions::fake();
        Http::fake();
        config(['services.apns.cle_base64' => 'pas-du-base64 secret-de-jim', 'services.apns.key_id' => 'ABC123', 'services.apns.team_id' => '9M257CNL34']);

        $this->assertSame([], (new DirectApns)->envoyer([str_repeat('ab', 32)], 'sandbox', 'ch.veterinary.client.watchkitapp', ['veterinary' => 'journee']));
        Http::assertNothingSent();
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'APNS_PRIVATE_KEY_BASE64') && ! str_contains($e->getMessage(), 'secret-de-jim'));

        config(['services.apns.cle_base64' => base64_encode('bonjour')]);
        $this->assertNull((new DirectApns)->autorisation('ch.veterinary.client.watchkitapp'));
    }

    public function test_les_jetons_refuses_sont_rendus_et_une_signature_refusee_est_refaite(): void
    {
        Exceptions::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich'));
        $this->configurer();
        Http::fake([
            '*/3/device/jeton-faux' => Http::response(['reason' => 'BadDeviceToken'], 400),
            '*/3/device/jeton-parti' => Http::response(['reason' => 'Unregistered', 'timestamp' => 1], 410),
            '*/3/device/jeton-autre-app' => Http::response(['reason' => 'DeviceTokenNotForTopic'], 400),
            '*/3/device/jeton-signature' => Http::response(['reason' => 'ExpiredProviderToken'], 403),
            '*/3/device/jeton-bon' => Http::response('', 200),
        ]);
        $apns = new DirectApns;
        $avant = $apns->autorisation('ch.veterinary.client');

        $morts = $apns->envoyer(['jeton-faux', 'jeton-parti', 'jeton-autre-app', 'jeton-signature', 'jeton-bon'], 'production', 'ch.veterinary.client', ['aps' => ['alert' => 'x']], visible: true);
        $this->travel(1)->seconds();

        $this->assertSame(['jeton-faux', 'jeton-parti', 'jeton-autre-app'], $morts);
        $this->assertNotSame($avant, $apns->autorisation('ch.veterinary.client'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'ExpiredProviderToken'));
    }

    public function test_une_autre_cle_a_son_propre_jeton_signe(): void
    {
        $this->configurer('ABC123');
        $ancien = (new DirectApns)->autorisation('ch.veterinary.client.watchkitapp');
        $this->configurer('DEF456');

        $this->assertNotSame($ancien, (new DirectApns)->autorisation('ch.veterinary.client.watchkitapp'));
    }
}
