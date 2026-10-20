<?php

namespace App\Mail;

use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La carte enregistrée expire avant le prochain prélèvement de
 * l'abonnement : 14 jours avant l'échéance, puis la veille.
 */
class CarteExpireAvantPrelevement extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Organisation|User $abonne,
        public CarbonInterface $echeance,
        public bool $pourClinique,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre carte expire avant le prochain prélèvement');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.abonnement.carte-expire');
    }
}
