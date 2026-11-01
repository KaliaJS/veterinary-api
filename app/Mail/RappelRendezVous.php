<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\RendezVous;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : son rendez-vous de demain (01.11.2026 : l’accueil public le promettait, « Un rappel vous attend la veille »). */
class RappelRendezVous extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public RendezVous $rendezVous,
        public Clinique $clinique,
        public Client $client,
        public string $lienDesabonnement,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rappel : rendez-vous de '.$this->rendezVous->patient->nom.' demain chez '.$this->clinique->nom,
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
        $debut = $this->rendezVous->starts_at->setTimezone($this->clinique->fuseau_horaire ?: 'Europe/Zurich');

        return new Content(markdown: 'emails.rappels.rendez-vous', with: [
            'patient' => $this->rendezVous->patient,
            'jour' => $debut->format('d.m.Y'),
            'heure' => $debut->format('H:i'),
        ]);
    }
}
