<?php

namespace App\Mail;

use App\Models\Devis;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : la clinique vient de lui envoyer un devis, à lire et accepter dans son espace (31.10.2026). */
class DevisEnvoye extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Devis $devis) {}

    public function envelope(): Envelope
    {
        $clinique = $this->devis->clinique;

        return new Envelope(
            subject: $clinique->nom.' vous a envoyé un devis',
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.devis.envoye', with: [
            'jusquau' => $this->devis->valide_jusquau?->locale('fr')->isoFormat('D MMMM YYYY'),
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/espace/documents?devis='.$this->devis->id,
        ]);
    }
}
