<?php

namespace Tests\Feature\Messages;

use App\Services\Messages\EnvoiSms;
use App\Services\Messages\SmsRefuse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMS par Twilio (décision de Jim, 28.10.2026) : « J'aimerais pouvoir
 * envoyer des SMS avec le nom Veterinary donc sans numéro de téléphone. »
 */
class EnvoiSmsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twilio' => ['account_sid' => 'AC123', 'api_key' => 'SK456', 'api_secret' => 'secret-essai', 'expediteur' => 'Veterinary']]);
    }

    public function test_le_sms_part_par_twilio_sous_le_nom_veterinary_sans_numero(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM789', 'status' => 'queued'], 201)]);

        $sid = app(EnvoiSms::class)->envoyer('+41791234567', 'Rappel : le vaccin de Félix est à faire avant le 3.11.');

        $this->assertSame('SM789', $sid);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $r['From'] === 'Veterinary'
            && $r['To'] === '+41791234567'
            && $r['Body'] === 'Rappel : le vaccin de Félix est à faire avant le 3.11.'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('SK456:secret-essai')));
    }

    public function test_un_refus_de_twilio_est_une_erreur_avec_sa_raison(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => "The 'To' number is not a valid phone number."], 400)]);

        try {
            app(EnvoiSms::class)->envoyer('+41791234567', 'Essai');
            $this->fail('Le refus aurait dû lever une erreur.');
        } catch (SmsRefuse $e) {
            $this->assertSame(21211, $e->getCode());
            $this->assertStringContainsString('not a valid phone number', $e->getMessage());
        }
    }

    public function test_sans_cle_twilio_aucun_envoi(): void
    {
        config(['services.twilio.api_secret' => null]);
        Http::fake();

        $this->assertFalse(app(EnvoiSms::class)->estConfigure());
        $this->expectException(SmsRefuse::class);
        app(EnvoiSms::class)->envoyer('+41791234567', 'Essai');
    }

    public function test_seuls_les_mobiles_recoivent_un_sms(): void
    {
        $this->assertSame('+41791234567', EnvoiSms::mobile('079 123 45 67'));
        $this->assertSame('+41761234567', EnvoiSms::mobile('+41 76 123 45 67'));
        $this->assertSame('+41781234567', EnvoiSms::mobile('0041 78 123 45 67'));
        // Fixe suisse : pas de SMS
        $this->assertNull(EnvoiSms::mobile('032 123 45 67'));
        $this->assertNull(EnvoiSms::mobile(''));
        $this->assertNull(EnvoiSms::mobile(null));
        // Étranger : Twilio dira s'il le reçoit
        $this->assertSame('+33612345678', EnvoiSms::mobile('+33 6 12 34 56 78'));
    }

    public function test_un_sms_d_essai_part_depuis_la_console(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);

        $this->artisan('sms:essai', ['numero' => '079 123 45 67'])->assertSuccessful();

        Http::assertSent(fn (Request $r): bool => $r['To'] === '+41791234567' && $r['From'] === 'Veterinary');
    }
}
