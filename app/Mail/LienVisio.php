<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\SessionTeleconsultation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : le lien de son appel vidéo avec la clinique (28.10.2026). */
class LienVisio extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SessionTeleconsultation $session,
        public Clinique $clinique,
        public string $lien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre appel vidéo avec '.$this->clinique->nom,
            replyTo: $this->clinique->email ? [new Address($this->clinique->email, $this->clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.visio.lien', with: [
            'patient' => $this->session->patient,
        ]);
    }
}
