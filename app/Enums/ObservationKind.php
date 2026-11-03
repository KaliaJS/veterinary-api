<?php

namespace App\Enums;

/**
 * Ce qu'on note au chevet (Jim, 03.11.2026) : manger ou pas, hydratation,
 * urine, selles, vomissements. Chaque sorte propose ses valeurs courtes ;
 * le ton (ok, moyen, alerte) dit à l'écran comment la teinter.
 */
enum ObservationKind: string
{
    case Eats = 'eats';
    case Drinks = 'drinks';
    case Urine = 'urine';
    case Stool = 'stool';
    case Vomit = 'vomit';

    public function label(): string
    {
        return match ($this) {
            self::Eats => 'Mange',
            self::Drinks => 'Boit',
            self::Urine => 'Urine',
            self::Stool => 'Selles',
            self::Vomit => 'Vomit',
        };
    }

    /**
     * Les valeurs proposées, de la meilleure à la plus inquiétante, avec
     * leur ton.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return match ($this) {
            self::Eats => ['tout' => 'ok', 'moitié' => 'mid', 'peu' => 'warn', 'gavé' => 'mid', 'rien' => 'warn'],
            self::Drinks => ['oui' => 'ok', 'peu' => 'warn', 'non' => 'warn'],
            self::Urine => ['oui' => 'ok', 'peu' => 'mid', 'non' => 'warn', 'sang' => 'warn'],
            self::Stool => ['normales' => 'ok', 'molles' => 'mid', 'diarrhée' => 'warn', 'sang' => 'warn', 'aucune' => 'mid'],
            self::Vomit => ['non' => 'ok', 'une fois' => 'mid', 'plusieurs fois' => 'warn'],
        };
    }

    /** Une valeur libre (hors de la liste) reste neutre. */
    public function tone(string $value): string
    {
        return $this->values()[mb_strtolower(trim($value))] ?? 'neutral';
    }

    /** @return list<array{kind: string, label: string, values: list<array{value: string, tone: string}>}> */
    public static function catalog(): array
    {
        return array_map(fn (self $kind): array => [
            'kind' => $kind->value,
            'label' => $kind->label(),
            'values' => array_map(fn (string $value, string $tone): array => ['value' => $value, 'tone' => $tone], array_keys($kind->values()), $kind->values()),
        ], self::cases());
    }
}
