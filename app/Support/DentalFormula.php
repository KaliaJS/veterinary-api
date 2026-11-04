<?php

namespace App\Support;

use App\Enums\Espece;

/**
 * Formules dentaires de l'adulte, en numéros Triadan (04.11.2026) : les
 * mêmes que le schéma dentaire du web (src/lib/dentalGeometry.js). Le chat
 * n'a pas de 105, 110, 205, 210, 305, 306, 310, 311, 405, 406, 410, 411 ;
 * le lapin a ses incisives de lait en 102 et 202 et un long diastème. Les
 * autres espèces n'ont pas de schéma dentaire.
 */
class DentalFormula
{
    /** @var array<string, array{upper: list<int>, lower: list<int>}> */
    private const POSITIONS = [
        'CH' => ['upper' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 'lower' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]],
        'CT' => ['upper' => [1, 2, 3, 4, 6, 7, 8, 9], 'lower' => [1, 2, 3, 4, 7, 8, 9]],
        'CV' => ['upper' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 'lower' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]],
        'LP' => ['upper' => [1, 2, 6, 7, 8, 9, 10, 11], 'lower' => [1, 7, 8, 9, 10, 11]],
        'FU' => ['upper' => [1, 2, 3, 4, 6, 7, 8, 9], 'lower' => [1, 2, 3, 4, 6, 7, 8, 9, 10]],
    ];

    /**
     * Les numéros de dent de l'espèce, ou null quand elle n'a pas de schéma.
     *
     * @return list<int>|null
     */
    public static function teethFor(?Espece $espece): ?array
    {
        $positions = $espece !== null ? (self::POSITIONS[$espece->value] ?? null) : null;
        if ($positions === null) {
            return null;
        }

        $teeth = [];
        foreach ([1 => 'upper', 2 => 'upper', 3 => 'lower', 4 => 'lower'] as $quadrant => $arch) {
            foreach ($positions[$arch] as $position) {
                $teeth[] = $quadrant * 100 + $position;
            }
        }

        return $teeth;
    }
}
