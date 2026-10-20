<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aux administrateurs : l'organisation approche (80 %) ou atteint (100 %) son plafond de transcription de la période. */
class PlafondTranscriptionOrganisation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $seuil, public float $montant, public float $plafond, public string $organisation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->seuil >= 100
            ? 'Transcription des consultations : plafond atteint'
            : "Transcription des consultations : {$this->seuil} % du plafond");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.transcription.plafond-organisation');
    }
}
