<?php

namespace App\Console\Commands;

use App\Notifications\SmsEssai;
use App\Support\Telephone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use Throwable;

/**
 * Un SMS d'essai, pour vérifier la clé Twilio, sa région et l'expéditeur
 * « Veterinary » : `php artisan sms:essai "079 123 45 67"`.
 */
class EssaiSms extends Command
{
    protected $signature = 'sms:essai {numero : Le mobile qui reçoit l’essai}';

    protected $description = 'Envoie un SMS d’essai sous le nom Veterinary (Twilio)';

    public function handle(): int
    {
        $mobile = Telephone::mobile((string) $this->argument('numero'));
        if ($mobile === null) {
            $this->error('Ce numéro ne reçoit pas de SMS (un mobile, par exemple 079 123 45 67).');

            return self::FAILURE;
        }

        try {
            Notification::route(TwilioChannel::class, $mobile)->notifyNow(new SmsEssai);
        } catch (Throwable $e) {
            $this->error('SMS refusé : '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("SMS parti vers {$mobile}.");

        return self::SUCCESS;
    }
}
