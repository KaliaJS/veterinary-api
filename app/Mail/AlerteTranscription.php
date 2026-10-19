<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** La plateforme approche (80 %) ou atteint (100 %) son budget mensuel Deepgram. */
class AlerteTranscription extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public int $seuil, public float $depense, public float $budget, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->seuil >= 100
            ? 'Transcription : budget Deepgram du mois atteint'
            : "Transcription : {$this->seuil} % du budget Deepgram du mois");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.transcription.alerte');
    }
}
