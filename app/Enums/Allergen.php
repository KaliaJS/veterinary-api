<?php

namespace App\Enums;

/** Allergique à quoi (précision de l'alerte, 02.11.2026) : les familles qui comptent pour prescrire */
enum Allergen: string
{
    case Penicillins = 'penicillins';
    case Cephalosporins = 'cephalosporins';
    case Sulfonamides = 'sulfonamides';
    case Nsaids = 'nsaids';
    case Anesthetics = 'anesthetics';
    case Vaccines = 'vaccines';
    case Food = 'food';
    case Antiparasitics = 'antiparasitics';
}
