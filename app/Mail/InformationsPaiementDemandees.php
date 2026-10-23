<?php

namespace App\Mail;

use App\Models\Societe;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Des informations sont demandées pour le compte de paiement d'une société
 * (23.10.2026) : le prestataire ne contacte jamais la société, c'est
 * Veterinary qui prévient ses administrateurs. `coupe` : les encaissements
 * sont suspendus en attendant.
 */
class InformationsPaiementDemandees extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Societe $societe, public bool $coupe = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->coupe
            ? 'Paiements suspendus pour '.$this->societe->nom.' : informations à fournir'
            : 'Paiements de '.$this->societe->nom.' : informations demandées');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.paiements.informations-demandees', with: [
            'lien' => rtrim((string) config('app.frontend_url'), '/').'/clinique/planning?reglages=paiements',
        ]);
    }
}
