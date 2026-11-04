<?php

namespace App\Enums;

/** Bilan de la bouche (04.11.2026) : la gingivite, de aucune à sévère */
enum GingivitisGrade: string
{
    case None = 'none';
    case Mild = 'mild';
    case Moderate = 'moderate';
    case Severe = 'severe';
}
