<?php

namespace App\Mail;

use App\Models\Paiement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Un paiement de la caisse contesté auprès de la banque : à traiter avant l'échéance. */
class PaiementConteste extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Paiement $paiement, public ?string $echeance) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Paiement contesté à '.$this->paiement->clinique->nom.' : facture '.$this->paiement->facture->numero);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.alertes.paiement-conteste', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/facturation',
        ]);
    }
}
