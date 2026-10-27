<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Message du formulaire de contact du site (27.10.2026), vers l'adresse de
 * la plateforme. En texte seul : ce qu'a écrit la personne n'est jamais
 * interprété (ni HTML ni Markdown). Répondre écrit directement à son adresse.
 */
class MessageContact extends Mailable
{
    use Queueable;

    public function __construct(
        public string $nom,
        public string $email,
        public string $texte,
        public ?string $compte = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contact : '.Str::limit($this->nom, 60),
            replyTo: [new Address($this->email, $this->nom)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.contact.message-texte', with: [
            'recuLe' => now('Europe/Zurich')->format('d.m.Y H:i'),
        ]);
    }
}
