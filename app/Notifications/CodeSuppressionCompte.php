<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code qui confirme la suppression du compte (27.10.2026) : même gabarit
 * que le code de connexion, avec des textes qui disent à quoi il sert, et
 * sans lien de connexion.
 */
class CodeSuppressionCompte extends Notification
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
            ->subject('Code pour supprimer votre compte')
            ->view(['html' => 'mail.code', 'text' => 'mail.code-texte'], [
                'code' => $this->code,
                'validiteMinutes' => $this->validiteMinutes,
                'lien' => null,
                'titre' => 'Supprimer votre compte',
                'consigne' => 'Saisissez ce code dans Veterinary pour confirmer la suppression de votre compte.',
                'avertissement' => 'Vous n’avez pas demandé la suppression de votre compte ? Ne transmettez ce code à personne : sans lui, votre compte reste intact.',
            ]);
    }
}
