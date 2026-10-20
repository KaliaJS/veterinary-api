<?php

namespace App\Mail;

use App\Models\FactureProprietaire;
use App\Models\OrganisationFacture;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Le renouvellement d'un abonnement n'a pas pu être prélevé : quand aura
 * lieu la prochaine tentative, ou la fin de l'abonnement après la dernière.
 */
class PaiementAbonnementRefuse extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public OrganisationFacture|FactureProprietaire $facture,
        public string $raison,
        public ?Carbon $prochaineTentative,
        public bool $pourClinique,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->prochaineTentative
            ? 'Paiement de votre abonnement refusé'
            : 'Votre abonnement a pris fin faute de paiement');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.abonnement.paiement-refuse');
    }
}
