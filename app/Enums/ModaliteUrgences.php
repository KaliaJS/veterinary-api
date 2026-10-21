<?php

namespace App\Enums;

/** Urgences d'une clinique : à toute heure, ou pendant ses heures d'ouverture. */
enum ModaliteUrgences: string
{
    case Permanence = 'permanence';
    case Horaires = 'horaires';

    public function libelle(): string
    {
        return match ($this) {
            self::Permanence => '24 h sur 24',
            self::Horaires => 'Pendant les heures d’ouverture',
        };
    }
}
