<?php

namespace App\Mail;

use App\Models\Organisation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Au vétérinaire qui a inscrit la clinique : elle est vérifiée, reste à choisir une formule. */
class CliniqueVerifiee extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Organisation $organisation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre clinique est vérifiée');
    }

    public function content(): Content
    {
        // Page d'accès de cette clinique : la session y bascule, même si
        // le vétérinaire travaille déjà dans une autre
        $clinique = $this->organisation->cliniques()->orderBy('cliniques.id')->value('cliniques.id');

        return new Content(markdown: 'emails.inscription.clinique-verifiee', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/acces-clinique'.($clinique ? '?clinique='.$clinique : ''),
        ]);
    }
}
