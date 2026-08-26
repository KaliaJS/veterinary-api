<?php

namespace App\Enums;

enum Espece: string
{
    case Chien = 'CH';
    case Chat = 'CT';
    case Lapin = 'LP';
    case Furet = 'FU';
    case Rongeur = 'RG';
    case Oiseau = 'OI';
    case Reptile = 'RP';
}
