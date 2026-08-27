<?php

namespace App\Enums;

/**
 * Drapeau d'un résultat hors bornes de référence — dérivé de la
 * valeur saisie, jamais saisi directement (flag '' | H | L du front,
 * le '' étant l'absence de drapeau).
 */
enum DrapeauResultatLabo: string
{
    case Haut = 'H';
    case Bas = 'L';

    public static function depuis(float $valeur, float $borneBasse, float $borneHaute): ?self
    {
        return match (true) {
            $valeur > $borneHaute => self::Haut,
            $valeur < $borneBasse => self::Bas,
            default => null,
        };
    }
}
