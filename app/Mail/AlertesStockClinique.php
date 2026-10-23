<?php

namespace App\Mail;

use App\Models\Clinique;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Le point du matin sur le stock d'une clinique : seuils et péremptions. */
class AlertesStockClinique extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  list<array{cle: string, type: string, niveau: string, titre: string, detail: string, cible: string}>  $alertes */
    public function __construct(public Clinique $clinique, public array $alertes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Stock de '.$this->clinique->nom.' : '.mb_strtolower($this->alertes[0]['titre']).(count($this->alertes) > 1 ? ' et plus' : ''));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.alertes.stock', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/stocks',
        ]);
    }
}
