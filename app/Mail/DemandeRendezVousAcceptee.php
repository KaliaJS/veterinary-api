<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use App\Models\RendezVous;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : la clinique accepte sa demande, le rendez-vous est pris. */
class DemandeRendezVousAcceptee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande, public RendezVous $rendezVous) {}

    public function envelope(): Envelope
    {
        $clinique = $this->demande->clinique;

        return new Envelope(
            subject: 'Rendez-vous confirmé chez '.$clinique->nom,
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        $fuseau = $this->demande->clinique->fuseau_horaire ?? 'Europe/Zurich';

        return new Content(markdown: 'emails.rendez-vous.demande-acceptee', with: [
            'quand' => $this->rendezVous->starts_at->setTimezone($fuseau)->locale('fr')->isoFormat('dddd D MMMM [à] HH[:]mm'),
        ]);
    }
}
