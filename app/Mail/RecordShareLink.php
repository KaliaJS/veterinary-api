<?php

namespace App\Mail;

use App\Models\Clinique;
use App\Models\Patient;
use App\Models\RecordShare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Au confrère : une clinique lui partage le dossier d'un animal (09.11.2026).
 * Rien de médical dans l'e-mail : seulement l'animal, la clinique, et le
 * lien personnel vers les pièces, valable jusqu'à sa date.
 */
class RecordShareLink extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public RecordShare $share, public Clinique $clinique, public Patient $patient, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Dossier de '.$this->patient->nom.', partagé par '.$this->clinique->nom);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.partage.dossier', with: [
            'jusqu' => $this->share->expiryDay(),
        ]);
    }
}
