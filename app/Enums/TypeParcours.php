<?php

namespace App\Enums;

/** Une ligne du parcours d'un vétérinaire, déclarée par lui : un poste ou une formation. */
enum TypeParcours: string
{
    case Experience = 'experience';
    case Formation = 'formation';
}
