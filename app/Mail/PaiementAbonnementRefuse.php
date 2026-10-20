<?php

namespace App\Mail;

use App\Models\FactureProprietaire;
use App\Models\OrganisationFacture;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Le renouvellement d'un abonnement n'a pas pu être prélevé : la prochaine
 * tentative et le jour où l'accès se fermera sans paiement, ou, après la
 * dernière, la fermeture (et la facture des minutes de transcription dues).
 */
class PaiementAbonnementRefuse extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public OrganisationFacture|FactureProprietaire $facture,
        public string $raison,
        public ?Carbon $prochaineTentative,
        public ?CarbonInterface $fermetureLe,
        public bool $pourClinique,
        public ?OrganisationFacture $factureTranscription = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->prochaineTentative
            ? 'Paiement de votre abonnement refusé'
            : ($this->pourClinique ? 'Accès à Veterinary fermé faute de paiement' : 'Votre abonnement Plus a pris fin faute de paiement'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.abonnement.paiement-refuse');
    }
}
