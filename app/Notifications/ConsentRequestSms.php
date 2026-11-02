<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

/** Le lien du consentement à signer, par SMS, sous le nom « Veterinary » (02.11.2026). */
class ConsentRequestSms extends Notification
{
    public function __construct(public string $text) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return new TwilioSmsMessage($this->text);
    }
}
