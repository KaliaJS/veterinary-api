<?php

namespace App\Support;

/**
 * Le texte d'un SMS (28.10.2026) : Twilio facture chaque segment. Dans
 * l'alphabet SMS (GSM 03.38), un segment porte 160 caractères (153 en
 * plusieurs parties) ; un seul caractère hors de cet alphabet fait tout
 * passer en UCS-2 : 70 caractères (67 en plusieurs parties). Nos textes
 * s'écrivent donc dans l'alphabet SMS : « contrôle » part « controle ».
 */
class TexteSms
{
    private const string GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Caractères de l'extension : deux places chacun */
    private const string EXTENSION = '^{}\\[~]|€';

    /** @var array<string, string> */
    private const array REMPLACEMENTS = [
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ë' => 'e', 'ï' => 'i', 'ÿ' => 'y', 'ç' => 'c', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'À' => 'A', 'Â' => 'A', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Œ' => 'OE', 'œ' => 'oe',
        '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '«' => '"', '»' => '"', '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ',
    ];

    /** Le texte dans l'alphabet SMS : les accents qui n'y sont pas perdent leur accent. */
    public static function versGsm(string $texte): string
    {
        $texte = strtr($texte, self::REMPLACEMENTS);
        $sortie = '';
        foreach (mb_str_split($texte) as $c) {
            $sortie .= self::estGsm($c) ? $c : '?';
        }

        return $sortie;
    }

    /** Le nombre de segments facturés pour ce texte. */
    public static function segments(string $texte): int
    {
        $caracteres = mb_str_split($texte);
        $gsm = true;
        $longueur = 0;
        foreach ($caracteres as $c) {
            $gsm = $gsm && self::estGsm($c);
            $longueur += str_contains(self::EXTENSION, $c) ? 2 : 1;
        }
        if (! $gsm) {
            $longueur = count($caracteres);

            return $longueur <= 70 ? 1 : (int) ceil($longueur / 67);
        }

        return $longueur <= 160 ? 1 : (int) ceil($longueur / 153);
    }

    private static function estGsm(string $c): bool
    {
        return str_contains(self::GSM, $c) || str_contains(self::EXTENSION, $c);
    }
}
