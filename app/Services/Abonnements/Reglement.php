<?php

namespace App\Services\Abonnements;

use App\Models\FactureProprietaire;
use App\Models\OrganisationFacture;

/**
 * Issue d'un encaissement : payé, refusé, ou en attente de la confirmation
 * du client auprès de sa banque (le secret se passe à Stripe.js, puis la
 * page demande la confirmation à l'API).
 */
final readonly class Reglement
{
    public function __construct(
        public OrganisationFacture|FactureProprietaire $facture,
        public string $statut,
        public ?string $clientSecret = null,
        public ?string $message = null,
    ) {}

    public function estPaye(): bool
    {
        return $this->statut === 'reussi';
    }

    /** Ce que la page reçoit quand la banque demande une confirmation, sinon null. */
    public function action(): ?array
    {
        return $this->statut === 'action_requise' ? [
            'facture_id' => $this->facture->id,
            'client_secret' => $this->clientSecret,
            'cle_publique' => config('services.stripe.key'),
            'message' => $this->message ?? 'Votre banque demande de confirmer le paiement.',
        ] : null;
    }
}
