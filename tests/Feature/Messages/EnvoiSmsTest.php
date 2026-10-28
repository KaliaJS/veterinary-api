<?php

namespace Tests\Feature\Messages;

use App\Notifications\SmsEssai;
use App\Support\Telephone;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use Tests\Concerns\FauxTwilio;
use Tests\TestCase;
use Twilio\Exceptions\RestException;

/**
 * SMS par Twilio (décision de Jim, 28.10.2026) : « J'aimerais pouvoir
 * envoyer des SMS avec le nom Veterinary donc sans numéro de téléphone. »
 * Notifications de Laravel et paquet laravel-notification-channels/twilio ;
 * la clé est en région Irlande.
 */
class EnvoiSmsTest extends TestCase
{
    use FauxTwilio;

    public function test_le_sms_part_par_twilio_sous_le_nom_veterinary_sans_numero_par_dublin(): void
    {
        $this->fauxTwilio();

        Notification::route(TwilioChannel::class, '+41791234567')->notifyNow(new SmsEssai);

        $this->assertCount(1, $this->requetesTwilio);
        $requete = $this->requetesTwilio[0];
        $this->assertSame('POST', $requete['method']);
        $this->assertSame('https://api.dublin.ie1.twilio.com/2010-04-01/Accounts/AC-essai/Messages.json', $requete['url']);
        $this->assertSame('Veterinary', $requete['data']['From']);
        $this->assertSame('+41791234567', $requete['data']['To']);
        $this->assertSame('Essai Veterinary : les SMS partent bien sous ce nom.', $requete['data']['Body']);
        // La clé d'API (révocable), jamais le jeton maître
        $this->assertSame(['SK-essai', 'secret-essai'], [$requete['user'], $requete['password']]);
    }

    public function test_un_refus_de_twilio_remonte_avec_sa_raison(): void
    {
        $this->fauxTwilio(400, ['code' => 21211, 'message' => "The 'To' number is not a valid phone number.", 'status' => 400]);

        $this->expectException(RestException::class);
        $this->expectExceptionMessage('not a valid phone number');

        Notification::route(TwilioChannel::class, '+41791234567')->notifyNow(new SmsEssai);
    }

    public function test_seuls_les_mobiles_recoivent_un_sms(): void
    {
        $this->assertSame('+41791234567', Telephone::mobile('079 123 45 67'));
        $this->assertSame('+41761234567', Telephone::mobile('+41 76 123 45 67'));
        $this->assertSame('+41781234567', Telephone::mobile('0041 78 123 45 67'));
        // Fixe suisse : pas de SMS
        $this->assertNull(Telephone::mobile('032 123 45 67'));
        $this->assertNull(Telephone::mobile(''));
        $this->assertNull(Telephone::mobile(null));
        // Étranger : Twilio dira s'il le reçoit
        $this->assertSame('+33612345678', Telephone::mobile('+33 6 12 34 56 78'));
    }

    public function test_un_sms_d_essai_part_depuis_la_console(): void
    {
        $this->fauxTwilio();

        $this->artisan('sms:essai', ['numero' => '079 123 45 67'])->assertSuccessful();

        $this->assertSame('+41791234567', $this->requetesTwilio[0]['data']['To']);
    }

    public function test_un_fixe_est_refuse_par_la_console_sans_appeler_twilio(): void
    {
        $this->fauxTwilio();

        $this->artisan('sms:essai', ['numero' => '032 123 45 67'])->assertFailed();

        $this->assertSame([], $this->requetesTwilio);
    }
}
