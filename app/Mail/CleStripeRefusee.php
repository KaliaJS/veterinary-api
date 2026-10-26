<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** À la plateforme : Stripe refuse la clé de l'API, plus rien n'est prélevé (26.10.2026). */
class CleStripeRefusee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $statut) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Paiements : Stripe refuse la clé de la plateforme');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.plateforme.cle-stripe');
    }
}
