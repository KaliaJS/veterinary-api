<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** La plateforme approche (80 %) ou atteint (100 %) son plafond mensuel de minutes de transcription. */
class AlerteTranscription extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $seuil, public int $minutes, public int $plafond) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->seuil >= 100
            ? 'Transcription : plafond mensuel atteint'
            : "Transcription : {$this->seuil} % du plafond mensuel");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.transcription.alerte');
    }
}
