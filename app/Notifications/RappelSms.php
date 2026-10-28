<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/** Un rappel au propriétaire par SMS, sous le nom « Veterinary » (texte composé par RappelsProprietaires). */
class RappelSms extends Notification
{
    public function __construct(public string $texte) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return new TwilioSmsMessage($this->texte);
    }
}
