<?php

namespace App\Enums;

/**
 * Odontogramme Triadan : une dent saine n'a pas de ligne en base
 * (l'absence d'enregistrement vaut « sain »).
 */
enum EtatDent: string
{
    case Tartre = 'tartre';
    case Gingivite = 'gingivite';
    case Parodontite = 'parodontite';
    case Fracture = 'fracture';
    case Absente = 'absente';
}
