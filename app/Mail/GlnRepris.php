<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Au compte qui perd son GLN (24.10.2026) : un autre compte l'a saisi, et
 * seule une identité vérifiée réserve un GLN. Le titulaire peut réagir :
 * faire vérifier son identité, ou écrire à la plateforme. L'autre compte
 * n'est jamais nommé. Copie à la vérification de la plateforme.
 */
class GlnRepris extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $gln) {}

    public function envelope(): Envelope
    {
        $verification = config('plateforme.verification_email');

        return new Envelope(
            subject: 'Votre GLN a été saisi par un autre compte',
            replyTo: $verification ? [$verification] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.inscription.gln-repris', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/'),
        ]);
    }
}
