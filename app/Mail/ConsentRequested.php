<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\Intervention;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : le consentement d'une intervention à lire et signer (02.11.2026). */
class ConsentRequested extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Intervention $intervention,
        public Clinique $clinique,
        public string $lien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Consentement à signer pour '.$this->intervention->patient->nom,
            replyTo: $this->clinique->email ? [new Address($this->clinique->email, $this->clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.consent.request', with: [
            'patient' => $this->intervention->patient,
            'procedure' => $this->intervention->procedure,
        ]);
    }
}
