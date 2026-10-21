<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : sa demande est bien arrivée à la clinique, avec le lien où il la suit. */
class DemandeRendezVousEnvoyee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande) {}

    public function envelope(): Envelope
    {
        $clinique = $this->demande->clinique;

        return new Envelope(
            subject: 'Demande de rendez-vous envoyée à '.$clinique->nom,
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        $fuseau = $this->demande->clinique->fuseau_horaire ?? 'Europe/Zurich';

        return new Content(markdown: 'emails.rendez-vous.demande-envoyee', with: [
            'quand' => $this->demande->debut_souhaite?->setTimezone($fuseau)->locale('fr')->isoFormat('dddd D MMMM [à] HH[:]mm'),
            'lien' => $this->demande->lienDeSuivi(),
        ]);
    }
}
