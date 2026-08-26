<?php

namespace App\Enums;

/**
 * `garde` ne bloque pas la colonne du praticien (badge « DE GARDE ») ;
 * les autres types, validés, l'interdisent à la création de RDV.
 */
enum TypeAbsence: string
{
    case Conge = 'conge';
    case Maladie = 'maladie';
    case Formation = 'formation';
    case Recuperation = 'recup';
    case Garde = 'garde';

    public function libelle(): string
    {
        return match ($this) {
            self::Conge => 'Congés',
            self::Maladie => 'Maladie',
            self::Formation => 'Formation',
            self::Recuperation => 'Récupération',
            self::Garde => 'Garde',
        };
    }
}
