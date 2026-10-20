<?php

namespace App\Mail;

use App\Models\Organisation;
use App\Services\Inscription\ResultatVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * À la plateforme : une clinique inscrite n'a pas pu être vérifiée dans
 * MedReg. Ce qu'elle a déclaré, ce que MedReg en dit, et deux liens
 * signés (30 jours) pour Valider ou Refuser. Les liens ouvrent une page
 * de confirmation : un lien suivi par un filtre de messagerie ne décide
 * rien.
 */
class VerificationCliniqueAFaire extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $lienValider;

    public string $lienRefuser;

    public function __construct(
        public Organisation $organisation,
        public ResultatVerification $resultat,
    ) {
        $this->lienValider = URL::temporarySignedRoute('verification-clinique.valider', now()->addDays(30), ['organisation' => $organisation->id]);
        $this->lienRefuser = URL::temporarySignedRoute('verification-clinique.refuser', now()->addDays(30), ['organisation' => $organisation->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Clinique à vérifier : '.$this->organisation->nom);
    }

    public function content(): Content
    {
        $societe = $this->organisation->societes()->first();

        return new Content(markdown: 'emails.inscription.verification-a-faire', with: [
            'user' => $this->organisation->inscritePar,
            'societe' => $societe,
            'clinique' => $societe?->cliniques()->first(),
        ]);
    }
}
