<?php

namespace App\Enums;

/**
 * État du contrat d'un employé (écran Organisation —
 * docs/data-model.md §2) : signé par les deux parties, en attente de la
 * signature de l'employé, ou PDF déposé et archivé.
 */
enum StatutContrat: string
{
    case Signe = 'signe';
    case Attente = 'attente';
    case Pdf = 'pdf';
}
