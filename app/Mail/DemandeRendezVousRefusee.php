<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au propriétaire : la clinique ne peut pas donner suite, avec son motif. */
class DemandeRendezVousRefusee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande) {}

    public function envelope(): Envelope
    {
        $clinique = $this->demande->clinique;

        return new Envelope(
            subject: 'Votre demande de rendez-vous chez '.$clinique->nom,
            replyTo: $clinique->email ? [new Address($clinique->email, $clinique->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.rendez-vous.demande-refusee', with: [
            'motif' => $this->demande->motif_refus?->libelle(),
        ]);
    }
}
