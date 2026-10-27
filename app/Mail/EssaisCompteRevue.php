<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Cinq codes faux de suite sur l'adresse de revue d'Apple (28.10.2026) :
 * quelqu'un devine peut-être son code fixe. À la plateforme, une fois par
 * jour au plus.
 */
class EssaisCompteRevue extends Mailable
{
    use Queueable;

    public function __construct(public string $adresse) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Compte de revue : cinq codes faux de suite');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plateforme.essais-compte-revue');
    }
}
