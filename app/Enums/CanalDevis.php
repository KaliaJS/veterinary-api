<?php

namespace App\Enums;

/**
 * Canal d'envoi d'un devis, choisi selon le compte du propriétaire :
 * espace client (signature électronique) ou papier (« bon pour
 * accord ») — docs/data-model.md §7.
 */
enum CanalDevis: string
{
    case Portail = 'portail';
    case Papier = 'papier';
}
