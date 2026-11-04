<?php

namespace App\Enums;

/**
 * Bilan de la bouche (04.11.2026) : l'occlusion, normale ou l'une des
 * quatre classes de malocclusion de l'AVDC (MAL1 à MAL4, nomenclature
 * avdc.org).
 */
enum Occlusion: string
{
    case Normal = 'normal';
    case Mal1 = 'MAL1';
    case Mal2 = 'MAL2';
    case Mal3 = 'MAL3';
    case Mal4 = 'MAL4';
}
