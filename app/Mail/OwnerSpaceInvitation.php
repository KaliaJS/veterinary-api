<?php

namespace App\Mail;

use App\Models\Clinique;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Au propriétaire que la clinique invite dans son espace en créant sa fiche
 * (décision de Jim, 03.11.2026). Rien n'est encore rattaché : le lien mène
 * à une page de l'espace qui prend l'invitation une fois connecté avec
 * cette adresse (code e-mail). Le jeton est dans le fragment de l'adresse,
 * que le navigateur ne transmet pas ; la page le passe à l'API dans le
 * corps d'un POST. En file chiffrée (le jeton n'y est jamais en clair),
 * envoyé après l'enregistrement.
 */
class OwnerSpaceInvitation extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Clinique $clinic, public string $token)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->clinic->nom.' vous invite dans votre espace Veterinary',
            replyTo: $this->clinic->email ? [new Address($this->clinic->email, $this->clinic->nom)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.espace.invitation', with: [
            'clinicName' => $this->clinic->nom,
            'link' => rtrim((string) config('app.frontend_url'), '/').'/espace/invitation#jeton='.$this->token,
        ]);
    }
}
