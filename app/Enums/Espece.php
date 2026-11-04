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

    /**
     * Animal de rente au sens de la TVA (Info TVA 21 de l'AFC, 03.11.2026) :
     * ses soins sont au taux réduit. Le cheval l'est, ânes et mulets compris
     * (rangés sous « Cheval ») ; chien, chat, NAC, oiseau et reptile sont
     * des animaux de compagnie.
     */
    public function isLivestock(): bool
    {
        return in_array($this, [self::Cheval, self::Bovin, self::Ovin, self::Caprin, self::Porc, self::Volaille], true);
    }

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
