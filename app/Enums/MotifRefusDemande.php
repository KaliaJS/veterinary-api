<?php

namespace App\Enums;

/** Motif d'un refus de demande de rendez-vous, écrit au propriétaire. */
enum MotifRefusDemande: string
{
    case AgendaComplet = 'agenda';
    case AReferer = 'referer';
    case HorsPerimetre = 'perimetre';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::AgendaComplet => 'Notre agenda est complet',
            self::AReferer => 'Votre animal sera mieux suivi par un confrère',
            self::HorsPerimetre => 'Ces soins ne font pas partie de ce que nous proposons',
            self::Autre => 'La clinique ne peut pas donner suite',
        };
    }
}
