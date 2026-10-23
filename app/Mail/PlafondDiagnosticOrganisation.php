<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aux administrateurs : l'organisation approche (80 %) ou atteint (100 %) son plafond de suggestions diagnostiques de la période. */
class PlafondDiagnosticOrganisation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $seuil, public float $montant, public float $plafond, public string $organisation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->seuil >= 100
            ? 'Suggestions diagnostiques : plafond atteint'
            : "Suggestions diagnostiques : {$this->seuil} % du plafond");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.diagnostic.plafond-organisation');
    }
}
