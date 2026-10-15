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
    // Animaux de rente et chevaux (catalogue des vaccins, 16.10.2026)
    case Cheval = 'CV';
    case Bovin = 'BV';
    case Ovin = 'OV';
    case Caprin = 'CP';
    case Porc = 'PO';
    case Volaille = 'VO';

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
            self::Cheval => 'Cheval',
            self::Bovin => 'Bovin',
            self::Ovin => 'Ovin',
            self::Caprin => 'Caprin',
            self::Porc => 'Porc',
            self::Volaille => 'Volaille',
        };
    }
}
