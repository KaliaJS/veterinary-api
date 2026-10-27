<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * À une adresse non invitée qui demande un code pendant l'accès sur
 * invitation (27.10.2026) : aucun code, aucun compte. L'écran lui a
 * répondu comme à tout le monde ; seul le titulaire de l'adresse le sait.
 */
class AccesSurInvitation extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Veterinary est sur invitation pour l’instant');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.acces.sur-invitation', with: [
            'contact' => config('plateforme.emetteur.email'),
        ]);
    }
}
