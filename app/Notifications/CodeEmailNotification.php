<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code à usage unique (connexion, inscription, vérification d'adresse) :
 * gabarit maison aux couleurs du site (resources/views/mail/code*),
 * version HTML et texte.
 */
class CodeEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $validiteMinutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre code de connexion')
            ->view(['html' => 'mail.code', 'text' => 'mail.code-texte'], [
                'code' => $this->code,
                'validiteMinutes' => $this->validiteMinutes,
            ]);
    }
}
