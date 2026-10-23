<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Joint le wordmark aux e-mails qui l'affichent (gabarit Markdown,
 * `cid:logo@veterinary`) : intégré au message plutôt que chargé depuis un
 * serveur, il s'affiche sans « afficher les images » (23.10.2026).
 */
class LogoDansLesMails
{
    public const string CID = 'logo@veterinary';

    public function handle(MessageSending $evenement): void
    {
        $html = (string) $evenement->message->getHtmlBody();

        if (! str_contains($html, 'cid:'.self::CID)) {
            return;
        }

        $evenement->message->addPart(
            (new DataPart(file_get_contents(resource_path('mail/wordmark.png')), 'veterinary.png', 'image/png'))
                ->asInline()
                ->setContentId(self::CID),
        );
    }
}
