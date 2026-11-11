<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Les jours fériés de chaque canton (Jim, 11.11.2026, É23 : « By canton,
 * automatic ») : les jours assimilés au dimanche par le droit du travail
 * (LTr art. 20a al. 1), d'après l'annexe du SECO « Jours fériés en
 * Suisse », état au 12.12.2024
 * (https://www.seco.admin.ch/dam/fr/sd-web/x5LtHPJdNis5/Anhang-Feiertage-Schweiz-Auszug-ArG-ArGV1-5-SECO-SB-2024-FR.pdf),
 * le 1er août partout (jour férié fédéral). Les cantons à régions
 * (Argovie, Fribourg, Neuchâtel, Soleure) ont leurs variantes, comme dans
 * l'annexe. La Näfelser Fahrt suit la règle du canton de Glaris (gl.ch) :
 * le premier jeudi d'avril, une semaine plus tard s'il tombe dans la
 * semaine sainte. Un jour à moitié férié (le 1er mai après-midi à
 * Soleure) compte une demi-journée.
 */
final class JoursFeries
{
    /** @var array<string, string> Les jours connus, leur nom */
    public const NOMS = [
        'nouvel_an' => 'Nouvel an',
        'berchtold' => 'Saint-Berchtold',
        'epiphanie' => 'Épiphanie',
        'republique_ne' => 'Instauration de la République',
        'saint_joseph' => 'Saint-Joseph',
        'fahrtsfest' => 'Näfelser Fahrt',
        'vendredi_saint' => 'Vendredi saint',
        'lundi_paques' => 'Lundi de Pâques',
        'fete_travail' => 'Fête du travail',
        'ascension' => 'Ascension',
        'lundi_pentecote' => 'Lundi de Pentecôte',
        'fete_dieu' => 'Fête-Dieu',
        'fete_nationale' => 'Fête nationale',
        'assomption' => 'Assomption',
        'jeune_genevois' => 'Jeûne genevois',
        'lundi_jeune' => 'Lundi du Jeûne fédéral',
        'toussaint' => 'Toussaint',
        'immaculee' => 'Immaculée Conception',
        'noel' => 'Noël',
        'saint_etienne' => 'Saint-Étienne',
        'restauration_ge' => 'Restauration de la République',
    ];

    private const AARAU = ['nouvel_an', 'berchtold', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'noel', 'saint_etienne'];

    private const CATHOLIQUE = ['nouvel_an', 'vendredi_saint', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'immaculee', 'noel'];

    private const BALE = ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'fete_travail', 'ascension', 'lundi_pentecote', 'noel', 'saint_etienne'];

    private const NEUCHATEL = ['nouvel_an', 'berchtold', 'republique_ne', 'vendredi_saint', 'fete_travail', 'ascension', 'noel', 'saint_etienne'];

    /**
     * Par canton, ses régions (la première par défaut) : un libellé et ses jours
     * (sans le 1er août, ajouté partout).
     *
     * @var array<string, array<string, array{0: string, 1: list<string>}>>
     */
    public const CANTONS = [
        'AG' => [
            'aarau' => ['Districts d’Aarau, Brugg, Kulm, Lenzburg et Zofingen, commune de Bergdietikon', self::AARAU],
            'baden' => ['Autres communes du district de Baden', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'fete_dieu', 'noel', 'saint_etienne']],
            'bremgarten' => ['District de Bremgarten', ['nouvel_an', 'vendredi_saint', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'noel', 'saint_etienne']],
            'laufenburg_muri' => ['Districts de Laufenburg et Muri', self::CATHOLIQUE],
            'rheinfelden_haut' => ['Hellikon, Mumpf, Obermumpf, Schupfart, Stein et Wegenstetten', self::CATHOLIQUE],
            'rheinfelden' => ['Kaiseraugst, Magden, Möhlin, Olsberg, Rheinfelden, Wallbach, Zeiningen et Zuzgen', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'toussaint', 'noel', 'saint_etienne']],
            'zurzach' => ['District de Zurzach', ['nouvel_an', 'berchtold', 'vendredi_saint', 'ascension', 'fete_dieu', 'toussaint', 'noel', 'saint_etienne']],
        ],
        'AI' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'fete_dieu', 'noel', 'saint_etienne']]],
        'AR' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'noel', 'saint_etienne']]],
        'BE' => ['canton' => ['Tout le canton', self::AARAU]],
        'BL' => ['canton' => ['Tout le canton', self::BALE]],
        'BS' => ['canton' => ['Tout le canton', self::BALE]],
        'FR' => [
            'catholique' => ['Communes à population majoritairement catholique romaine', self::CATHOLIQUE],
            'reformee' => ['Communes à population majoritairement évangélique réformée', self::AARAU],
        ],
        'GE' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'jeune_genevois', 'noel', 'restauration_ge']]],
        'GL' => ['canton' => ['Tout le canton', ['nouvel_an', 'fahrtsfest', 'vendredi_saint', 'lundi_paques', 'ascension', 'toussaint', 'noel', 'saint_etienne']]],
        'GR' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'noel', 'saint_etienne']]],
        'JU' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'fete_travail', 'ascension', 'lundi_pentecote', 'fete_dieu', 'noel']]],
        'LU' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'noel', 'saint_etienne']]],
        'NE' => [
            'canton' => ['Tout le canton', self::NEUCHATEL],
            'landeron' => ['Commune du Landeron', [...self::NEUCHATEL, 'fete_dieu']],
        ],
        'NW' => ['canton' => ['Tout le canton', self::CATHOLIQUE]],
        'OW' => ['canton' => ['Tout le canton', self::CATHOLIQUE]],
        'SG' => ['canton' => ['Tout le canton', ['nouvel_an', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'toussaint', 'noel', 'saint_etienne']]],
        'SH' => ['canton' => ['Tout le canton', self::BALE]],
        'SO' => [
            'canton' => ['Tout le canton, sauf le district de Bucheggberg', ['nouvel_an', 'vendredi_saint', 'fete_travail', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'noel']],
            'bucheggberg' => ['District de Bucheggberg', ['nouvel_an', 'vendredi_saint', 'fete_travail', 'ascension', 'noel']],
        ],
        'SZ' => ['canton' => ['Tout le canton', ['nouvel_an', 'saint_joseph', 'vendredi_saint', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'noel']]],
        'TG' => ['canton' => ['Tout le canton', self::AARAU]],
        'TI' => ['canton' => ['Tout le canton', ['nouvel_an', 'epiphanie', 'lundi_paques', 'ascension', 'assomption', 'toussaint', 'noel', 'saint_etienne']]],
        'UR' => ['canton' => ['Tout le canton', self::CATHOLIQUE]],
        'VD' => ['canton' => ['Tout le canton', ['nouvel_an', 'berchtold', 'vendredi_saint', 'lundi_paques', 'ascension', 'lundi_pentecote', 'lundi_jeune', 'noel']]],
        'VS' => ['canton' => ['Tout le canton', ['nouvel_an', 'saint_joseph', 'ascension', 'fete_dieu', 'assomption', 'toussaint', 'immaculee', 'noel']]],
        'ZG' => ['canton' => ['Tout le canton', self::CATHOLIQUE]],
        'ZH' => ['canton' => ['Tout le canton', self::BALE]],
    ];

    /**
     * Les jours fériés d'une année : date => [clé, nom, part du jour].
     *
     * @param  list<string>  $retires  jours que la clinique ne chôme pas
     * @param  list<string>  $ajoutes  jours de l'annexe qu'elle chôme en plus
     * @return array<string, array{cle: string, nom: string, part: float}>
     */
    public static function annee(?string $canton, ?string $variante, int $annee, array $retires = [], array $ajoutes = []): array
    {
        $cles = ['fete_nationale', ...self::clesDuCanton($canton, $variante)];
        $cles = array_values(array_unique(array_merge(array_diff($cles, $retires), array_intersect($ajoutes, array_keys(self::NOMS)))));

        $jours = [];
        foreach ($cles as $cle) {
            $date = self::date($cle, $annee);
            if ($date === null || ! self::estChome($cle, $date, $canton)) {
                continue;
            }
            $jours[$date->toDateString()] = [
                'cle' => $cle,
                'nom' => self::NOMS[$cle],
                // Soleure : le 1er mai, l'après-midi seulement
                'part' => $cle === 'fete_travail' && $canton === 'SO' ? 0.5 : 1.0,
            ];
        }
        ksort($jours);

        return $jours;
    }

    /** @return list<string> */
    public static function clesDuCanton(?string $canton, ?string $variante): array
    {
        $regions = self::CANTONS[$canton ?? ''] ?? null;
        if ($regions === null) {
            return [];
        }

        return ($regions[$variante ?? ''] ?? reset($regions))[1];
    }

    public static function date(string $cle, int $annee): ?CarbonImmutable
    {
        $paques = self::paques($annee);

        return match ($cle) {
            'nouvel_an' => CarbonImmutable::create($annee, 1, 1),
            'berchtold' => CarbonImmutable::create($annee, 1, 2),
            'epiphanie' => CarbonImmutable::create($annee, 1, 6),
            'republique_ne' => CarbonImmutable::create($annee, 3, 1),
            'saint_joseph' => CarbonImmutable::create($annee, 3, 19),
            'fahrtsfest' => self::fahrtsfest($annee, $paques),
            'vendredi_saint' => $paques->subDays(2),
            'lundi_paques' => $paques->addDay(),
            'fete_travail' => CarbonImmutable::create($annee, 5, 1),
            'ascension' => $paques->addDays(39),
            'lundi_pentecote' => $paques->addDays(50),
            'fete_dieu' => $paques->addDays(60),
            'fete_nationale' => CarbonImmutable::create($annee, 8, 1),
            'assomption' => CarbonImmutable::create($annee, 8, 15),
            // Le jeudi qui suit le premier dimanche de septembre
            'jeune_genevois' => self::premierDimancheDeSeptembre($annee)->addDays(4),
            // Le lundi qui suit le troisième dimanche de septembre
            'lundi_jeune' => self::premierDimancheDeSeptembre($annee)->addWeeks(2)->addDay(),
            'toussaint' => CarbonImmutable::create($annee, 11, 1),
            'immaculee' => CarbonImmutable::create($annee, 12, 8),
            'noel' => CarbonImmutable::create($annee, 12, 25),
            'saint_etienne' => CarbonImmutable::create($annee, 12, 26),
            'restauration_ge' => CarbonImmutable::create($annee, 12, 31),
            default => null,
        };
    }

    /** Les conditions de l'annexe : Appenzell, la Saint-Étienne ; Neuchâtel, le 2 janvier et la Saint-Étienne. */
    private static function estChome(string $cle, CarbonImmutable $date, ?string $canton): bool
    {
        // Appenzell (AR, AI) : pas de Saint-Étienne quand Noël tombe un lundi ou un vendredi (pas trois jours de repos de suite)
        if ($cle === 'saint_etienne' && in_array($canton, ['AR', 'AI'], true)) {
            $noel = $date->subDay();

            return ! $noel->isMonday() && ! $noel->isFriday();
        }
        // Neuchâtel : le 2 janvier et la Saint-Étienne seulement s'ils tombent un lundi
        if (in_array($cle, ['berchtold', 'saint_etienne'], true) && $canton === 'NE') {
            return $date->isMonday();
        }

        return true;
    }

    private static function fahrtsfest(int $annee, CarbonImmutable $paques): CarbonImmutable
    {
        $jeudi = CarbonImmutable::create($annee, 4, 1);
        while (! $jeudi->isThursday()) {
            $jeudi = $jeudi->addDay();
        }

        // Dans la semaine sainte (des Rameaux au samedi saint) : une semaine plus tard
        return $jeudi->gte($paques->subDays(7)) && $jeudi->lt($paques) ? $jeudi->addWeek() : $jeudi;
    }

    /** Le dimanche de Pâques (calendrier grégorien, méthode de Meeus) : sans l'extension calendar de PHP */
    public static function paques(int $annee): CarbonImmutable
    {
        $a = $annee % 19;
        $b = intdiv($annee, 100);
        $c = $annee % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - ($c % 4)) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mois = intdiv($h + $l - 7 * $m + 114, 31);
        $jour = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($annee, $mois, $jour);
    }

    private static function premierDimancheDeSeptembre(int $annee): CarbonImmutable
    {
        $jour = CarbonImmutable::create($annee, 9, 1);
        while (! $jour->isSunday()) {
            $jour = $jour->addDay();
        }

        return $jour;
    }
}
