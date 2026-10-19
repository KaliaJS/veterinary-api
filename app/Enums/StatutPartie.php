<?php

namespace App\Enums;

/**
 * Une partie d'enregistrement : morceaux en cours d'envoi, audio reçu et
 * assemblé, transcrite (l'audio est alors effacé), ou en erreur.
 */
enum StatutPartie: string
{
    case Envoi = 'envoi';
    case Recue = 'recue';
    case Transcrite = 'transcrite';
    case Erreur = 'erreur';
}
