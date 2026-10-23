<?php

namespace App\Mail;

use App\Models\Clinique;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire sans compte : le code qui prouve son adresse avant l'envoi de sa demande. */
class CodeDemandeRendezVous extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Clinique $clinique, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre code pour '.$this->clinique->nom.' : '.$this->code);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.rendez-vous.code-demande');
    }
}
