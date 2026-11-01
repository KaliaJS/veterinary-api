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

    /**
     * Le niveau d'un résultat hors bornes (décision de Jim, 01.11.2026) : « limite » s'il dépasse la borne de moins de
     * 10 % de l'écart entre les bornes (orange à l'écran), « hors » au-delà (rouge) ; null dans les bornes.
     */
    public static function niveau(?float $valeur, ?float $borneBasse, ?float $borneHaute): ?string
    {
        if ($valeur === null || $borneBasse === null || $borneHaute === null) {
            return null;
        }
        $depassement = max($valeur - $borneHaute, $borneBasse - $valeur);
        if ($depassement <= 0) {
            return null;
        }

        return round($depassement, 9) <= round(0.1 * ($borneHaute - $borneBasse), 9) ? 'limite' : 'hors';
    }
}
