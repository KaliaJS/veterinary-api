<?php

namespace App\Support;

/**
 * Un code ATC (OMS) ou ATCvet (le même, précédé de « Q ») : la famille d'un
 * médicament, pour mettre en garde quand l'animal y est allergique
 * (dossier patient, 03.11.2026). Le premier niveau est l'un des groupes de
 * l'OMS (A, B, C, D, G, H, J, L, M, N, P, R, S, V) ; l'ATCvet a en plus
 * QI, les immunologiques (vaccins), qui n'existe pas sans le « Q ».
 */
final class AtcCode
{
    public const PATTERN = '/^(Q[ABCDGHIJLMNPRSV]|[ABCDGHJLMNPRSV])(\d{2}([A-Z]([A-Z](\d{2})?)?)?)?$/';

    /** Les messages de validation, en français */
    public static function messages(string $field = 'atc_code'): array
    {
        return [
            "{$field}.present" => 'Indiquez le code ATC, ou null pour l’effacer.',
            "{$field}.max" => 'Un code ATC a au plus 12 caractères.',
            "{$field}.regex" => 'Ce code ATC n’est pas valable : une lettre de groupe (A, B, C, D, G, H, J, L, M, N, P, R, S ou V, précédée de Q en ATCvet, ou QI), puis des chiffres et des lettres, par exemple QM01AC06.',
        ];
    }

    /** Sans espace, en capitales ; vide devient null */
    public static function normalize(mixed $code): mixed
    {
        if (! is_string($code)) {
            return $code;
        }
        $clean = strtoupper((string) preg_replace('/\s+/', '', $code));

        return $clean === '' ? null : $clean;
    }
}
