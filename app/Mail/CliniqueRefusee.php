<?php

namespace App\Mail;

use App\Models\Organisation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au vétérinaire qui a inscrit la clinique : l'inscription est refusée, avec le motif s'il y en a un ; il peut répondre. */
class CliniqueRefusee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Organisation $organisation) {}

    public function envelope(): Envelope
    {
        $plateforme = config('plateforme.verification_email');

        // Une réponse arrive à la plateforme, qui a refusé
        return new Envelope(
            subject: 'Inscription de votre clinique',
            replyTo: $plateforme ? [new Address($plateforme)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.inscription.clinique-refusee');
    }
}
