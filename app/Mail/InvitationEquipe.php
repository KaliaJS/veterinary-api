<?php

namespace App\Mail;

use App\Models\InvitationEmploye;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** À la personne invitée : rejoindre l'équipe d'une organisation, avec ou sans compte. */
class InvitationEquipe extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public InvitationEmploye $invitation, public string $jeton) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invitation à rejoindre '.$this->invitation->organisation->nom);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.equipe.invitation', with: [
            'organisation' => $this->invitation->organisation->nom,
            'invitant' => $this->invitation->invitant?->nom,
            'poste' => $this->invitation->donnees['poste'] ?? null,
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/invitation/'.$this->jeton,
            'jours' => InvitationEmploye::DUREE_JOURS,
        ]);
    }
}
