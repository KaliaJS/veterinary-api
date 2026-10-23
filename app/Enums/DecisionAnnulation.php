<?php

namespace App\Enums;

/**
 * Réponse de la clinique à une demande d'annulation d'un rendez-vous
 * avec acompte (jamais rendu d'office) : annuler en rendant tout, en
 * rendant moins les frais du paiement, ou en le gardant ; ou refuser
 * l'annulation, le rendez-vous est maintenu.
 */
enum DecisionAnnulation: string
{
    case Complet = 'complet';
    case HorsFrais = 'hors_frais';
    case Sans = 'sans';
    case Refusee = 'refusee';

    public function annule(): bool
    {
        return $this !== self::Refusee;
    }
}
