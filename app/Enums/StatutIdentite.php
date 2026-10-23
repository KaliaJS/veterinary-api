<?php

namespace App\Enums;

/**
 * Vérification d'identité d'une personne par Stripe Identity (23.10.2026) :
 * pièce d'identité et selfie, puis le nom lu sur la pièce comparé à celui
 * que MedReg donne pour son GLN. Une seule tentative : un échec, ou un
 * autre nom, passe à la main (modérateur de la plateforme).
 */
enum StatutIdentite: string
{
    case EnCours = 'en_cours';
    case Verifiee = 'verifiee';
    case ARevoir = 'a_revoir';
}
