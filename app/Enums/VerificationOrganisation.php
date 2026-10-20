<?php

namespace App\Enums;

/**
 * Vérification d'une organisation inscrite : le vétérinaire qui l'a
 * inscrite est retrouvé dans MedReg (GLN, profession vétérinaire, même
 * nom), sinon la plateforme vérifie à la main. Tant qu'elle n'est pas
 * vérifiée, l'organisation n'ouvre rien (ni l'application, ni l'annuaire).
 */
enum VerificationOrganisation: string
{
    case EnAttente = 'en_attente';
    case Verifiee = 'verifiee';
    case Refusee = 'refusee';
}
