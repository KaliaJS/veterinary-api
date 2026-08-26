<?php

namespace App\Enums;

/**
 * Conditionne la pause déjeuner (lignes consultation uniquement) et les
 * champs éditables d'un rendez-vous porté par la ligne.
 */
enum RoleLignePlanning: string
{
    case Consultation = 'consult';
    case Bloc = 'bloc';
    case Imagerie = 'img';
}
