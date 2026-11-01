<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\MessageClientele;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Au propriétaire : la clinique lui a écrit (réglage « Réponses de la clinique », 28.10.2026).
 * Le premier message d'un fil (la clinique écrit la première, souvent juste
 * après lui avoir ouvert son espace client, 01.11.2026) dit comment y entrer.
 */
class ReponseClinique extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public MessageClientele $message, public Clinique $clinique, public bool $estPremier = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nouveau message de '.$this->clinique->nom);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.messages.reponse', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/espace/messages?clinique='.$this->clinique->id,
        ]);
    }
}
