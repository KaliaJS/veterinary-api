<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
            ->greeting('Bonjour,')
            ->line("Votre code de connexion : **{$this->code}**")
            ->line("Il est valable {$this->validiteMinutes} minutes et à usage unique.")
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.');
    }
}
