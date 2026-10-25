<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** À la plateforme : Cloudflare refuse le jeton de l'API, les domaines des sites ne sont plus vérifiés (26.10.2026). */
class JetonCloudflareRefuse extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $statut) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Domaines des sites : Cloudflare refuse le jeton');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.plateforme.jeton-cloudflare');
    }
}
