<?php

namespace App\Console\Commands;

use App\Services\Messages\EnvoiSms;
use App\Services\Messages\SmsRefuse;
use Illuminate\Console\Command;

/**
 * Un SMS d'essai, pour vérifier la clé Twilio et l'expéditeur
 * « Veterinary » : `php artisan sms:essai "079 123 45 67"`.
 */
class EssaiSms extends Command
{
    protected $signature = 'sms:essai {numero : Le mobile qui reçoit l’essai}';

    protected $description = 'Envoie un SMS d’essai sous le nom Veterinary (Twilio)';

    public function handle(EnvoiSms $sms): int
    {
        $mobile = EnvoiSms::mobile((string) $this->argument('numero'));
        if ($mobile === null) {
            $this->error('Ce numéro ne reçoit pas de SMS (un mobile, par exemple 079 123 45 67).');

            return self::FAILURE;
        }

        try {
            $sid = $sms->envoyer($mobile, 'Essai Veterinary : les SMS partent bien sous ce nom.');
        } catch (SmsRefuse $e) {
            $this->error('SMS refusé : '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("SMS parti vers {$mobile} ({$sid}).");

        return self::SUCCESS;
    }
}
