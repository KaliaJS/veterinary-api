<?php

namespace App\Enums;

/**
 * Comment le consentement éclairé a été recueilli (02.11.2026).
 */
enum ConsentMethod: string
{
    /** Signé sur l'écran de la clinique */
    case Signed = 'signed';
    /** Signé par le propriétaire sur son téléphone, par le lien reçu */
    case Remote = 'remote';
    /** Accord donné de vive voix ou au téléphone, noté par le vétérinaire */
    case Oral = 'oral';
    /** Urgence : le vétérinaire en prend la responsabilité */
    case Emergency = 'emergency';
}
