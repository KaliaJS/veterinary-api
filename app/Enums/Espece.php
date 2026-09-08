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

    /** Libellé affiché au propriétaire (table SPN du proto). */
    public function libelle(): string
    {
        return match ($this) {
            self::Chien => 'Chien',
            self::Chat => 'Chat',
            self::Lapin => 'Lapin',
            self::Furet => 'Furet',
            self::Rongeur => 'Rongeur',
            self::Oiseau => 'Oiseau',
            self::Reptile => 'Reptile',
        };
    }
}
