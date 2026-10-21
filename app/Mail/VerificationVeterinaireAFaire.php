<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aux modérateurs de la plateforme : un vétérinaire demande la vérification de son profil. */
class VerificationVeterinaireAFaire extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $veterinaire) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vétérinaire à vérifier : '.$this->veterinaire->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.plateforme.veterinaire-a-verifier', with: [
            'nom' => $this->veterinaire->name,
            'email' => $this->veterinaire->email,
            'gln' => $this->veterinaire->gln,
            'auRegistre' => $this->veterinaire->gln_verifie_le !== null,
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/plateforme/veterinaires',
        ]);
    }
}
