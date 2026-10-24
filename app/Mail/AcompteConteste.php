<?php

namespace App\Mail;

use App\Models\DemandeRendezVous;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Un acompte payé en ligne contesté auprès de la banque : à traiter avant l'échéance (24.10.2026). */
class AcompteConteste extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DemandeRendezVous $demande, public ?string $echeance) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Acompte contesté à '.$this->demande->clinique->nom.' : '.$this->demande->patient_name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.alertes.acompte-conteste', with: [
            'montant' => chf(((int) $this->demande->acompte_centimes) / 100),
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/facturation',
        ]);
    }
}
