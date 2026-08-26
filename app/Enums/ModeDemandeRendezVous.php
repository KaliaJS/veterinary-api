<?php

namespace App\Enums;

/**
 * Demande de rendez-vous en ligne : à placer manuellement par l'accueil
 * (`valider`) ou créneau déjà choisi par le client (`confirme`).
 */
enum ModeDemandeRendezVous: string
{
    case AValider = 'valider';
    case Confirmee = 'confirme';
}
