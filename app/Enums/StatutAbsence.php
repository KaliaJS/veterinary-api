<?php

namespace App\Enums;

enum StatutAbsence: string
{
    case Validee = 'ok';
    case EnAttente = 'att';
    case Refusee = 'ref';
}
