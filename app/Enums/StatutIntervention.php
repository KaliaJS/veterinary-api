<?php

namespace App\Enums;

/**
 * Cycle opératoire (STM du front) : prevu → prep → cours → reveil →
 * fini, sans retour ni saut — l'incision (`cours`) est bloquée tant
 * que la checklist de sécurité n'est pas complète.
 */
enum StatutIntervention: string
{
    case Prevu = 'prevu';
    case Preparation = 'prep';
    case Cours = 'cours';
    case Reveil = 'reveil';
    case Fini = 'fini';

    public function suivant(): ?self
    {
        return match ($this) {
            self::Prevu => self::Preparation,
            self::Preparation => self::Cours,
            self::Cours => self::Reveil,
            self::Reveil => self::Fini,
            self::Fini => null,
        };
    }
}
