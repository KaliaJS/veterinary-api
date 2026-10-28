<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/** Le SMS d'essai de `php artisan sms:essai` : vérifie la clé, la région et l'expéditeur « Veterinary ». */
class SmsEssai extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return new TwilioSmsMessage('Essai Veterinary : les SMS partent bien sous ce nom.');
    }
}
