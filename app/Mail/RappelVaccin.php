<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Vaccination;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Au propriétaire : le rappel d'un vaccin approche (28.10.2026). */
class RappelVaccin extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Vaccination $vaccination,
        public Clinique $clinique,
        public Client $client,
        public string $lienDesabonnement,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rappel de vaccin pour '.$this->vaccination->patient->nom.' chez '.$this->clinique->nom,
            replyTo: $this->clinique->email ? [new Address($this->clinique->email, $this->clinique->nom)] : [],
        );
    }

    /** Désabonnement en un clic depuis la messagerie (RFC 8058). */
    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->lienDesabonnement.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.rappels.vaccin', with: [
            'patient' => $this->vaccination->patient,
            'echeance' => $this->vaccination->echeance_le?->format('d.m.Y'),
            'lienRendezVous' => rtrim((string) config('app.frontend_url'), '/').'/cliniques/'.$this->clinique->id.'-'.Str::slug($this->clinique->nom),
        ]);
    }
}
