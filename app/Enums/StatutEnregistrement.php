<?php

namespace App\Enums;

/**
 * Vie d'un enregistrement de consultation : parties en cours d'envoi,
 * puis (le vétérinaire a terminé) transcription des parties, rédaction du
 * brouillon SOAP, brouillon prêt à relire, compte rendu validé. Une erreur
 * de transcription ou de rédaction se relance.
 */
enum StatutEnregistrement: string
{
    case Enregistrement = 'enregistrement';
    case Transcription = 'transcription';
    case Redaction = 'redaction';
    case Pret = 'pret';
    case Valide = 'valide';
    case Erreur = 'erreur';
}
