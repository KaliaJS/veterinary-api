<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Une adresse ajoutée à la liste des invitations (27.10.2026) : elle peut créer son compte. */
class InvitationAccesEnvoyee extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre invitation à Veterinary');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.acces.invitation', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/?connexion=1',
        ]);
    }
}
