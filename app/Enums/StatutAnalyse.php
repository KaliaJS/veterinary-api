<?php

namespace App\Enums;

/**
 * Cycle de vie d'une demande d'analyses (LAB_ADVANCE du front) :
 * attente (prélevé) → cours (analyse) → valid (à valider) → ok
 * (validé signé), un cran à la fois, sans retour — la validation est
 * une signature, jamais de suppression.
 */
enum StatutAnalyse: string
{
    case Attente = 'attente';
    case Cours = 'cours';
    case AValider = 'valid';
    case Valide = 'ok';

    public function suivant(): ?self
    {
        return match ($this) {
            self::Attente => self::Cours,
            self::Cours => self::AValider,
            self::AValider => self::Valide,
            self::Valide => null,
        };
    }
}
