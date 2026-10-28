<?php

namespace App\Mail;

use App\Models\Devis;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : un devis de la clinique attend sa réponse dans son espace (28.10.2026). */
class RelanceDevis extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Devis $devis) {}

    public function envelope(): Envelope
    {
        $clinique = $this->devis->clinique;

        return new Envelope(
            subject: 'Un devis de '.$clinique->nom.' attend votre réponse',
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.devis.relance', with: [
            'jusquau' => $this->devis->valide_jusquau?->locale('fr')->isoFormat('D MMMM YYYY'),
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/espace/documents',
        ]);
    }
}
