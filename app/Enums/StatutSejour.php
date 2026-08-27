<?php

namespace App\Enums;

/**
 * État clinique d'un séjour d'hospitalisation (STC du front). Il porte
 * aussi le forfait journalier facturé à la sortie (docs/data-model.md
 * §8) — tarifs de parité front (hospBill).
 */
enum StatutSejour: string
{
    case Stable = 'stable';
    case Surveillance = 'surv';
    case Critique = 'critique';

    public function libelleFacturation(): string
    {
        return match ($this) {
            self::Stable => 'surveillance simple',
            self::Surveillance => 'surveillance rapprochée',
            self::Critique => 'soins intensifs',
        };
    }

    /** Forfait journalier en CHF. */
    public function tarifJournalier(): float
    {
        return match ($this) {
            self::Stable => 65.0,
            self::Surveillance => 95.0,
            self::Critique => 140.0,
        };
    }
}
