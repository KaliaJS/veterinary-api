<?php

namespace App\Enums;

/** Bilan de la bouche (04.11.2026) : le tartre, de aucun à massif */
enum CalculusGrade: string
{
    case None = 'none';
    case Minimal = 'minimal';
    case Mild = 'mild';
    case Moderate = 'moderate';
    case Heavy = 'heavy';
}
