<?php

namespace App\Enums;

/**
 * Où en est une demande de rendez-vous en ligne : à payer (acompte d'une
 * réservation directe, avant que l'heure soit prise), à traiter par la
 * clinique, des horaires proposés au propriétaire (il en choisit un),
 * acceptée (le rendez-vous est au planning) ou refusée (avec un motif).
 * Une demande n'est jamais supprimée une fois traitée : le propriétaire
 * en suit l'issue, la clinique la retrouve dans les traitées du jour.
 */
enum StatutDemandeRendezVous: string
{
    /** Réservation directe avec acompte : le paiement n'est pas encore fait */
    case APayer = 'a_payer';
    case ATraiter = 'a_traiter';
    case Proposee = 'proposee';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';

    /** La clinique peut encore y répondre (accepter, proposer, refuser). */
    public function estOuverte(): bool
    {
        return $this === self::ATraiter || $this === self::Proposee;
    }
}
