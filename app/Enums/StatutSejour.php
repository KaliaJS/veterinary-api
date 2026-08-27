<?php

namespace App\Enums;

/**
 * État clinique d'un séjour d'hospitalisation (STC du front). Il
 * détermine le forfait journalier facturé à la sortie (docs/
 * data-model.md §8) — le prix vit au catalogue `prestations` de
 * l'organisation, jamais en constante PHP.
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

    /** Code de la prestation du forfait journalier au catalogue. */
    public function codePrestation(): string
    {
        return match ($this) {
            self::Stable => 'hosp_stable',
            self::Surveillance => 'hosp_surv',
            self::Critique => 'hosp_critique',
        };
    }
}
