<?php

namespace App\Enums;

/**
 * Échelles de douleur reconnues (Jim, 03.11.2026) : la Glasgow abrégée
 * (CMPS-SF) pour le chien, sur 24, à traiter à partir de 6 ; la Feline
 * Grimace Scale pour le chat, sur 10, à partir de 4 ; l'échelle de douleur
 * aiguë de Colorado pour les autres espèces, sur 4, à partir de 2.
 */
enum PainScale: string
{
    case CmpsSf = 'cmps_sf';
    case Fgs = 'fgs';
    case Colorado = 'colorado';

    public static function forSpecies(?Espece $espece): self
    {
        return match ($espece) {
            Espece::Chien => self::CmpsSf,
            Espece::Chat => self::Fgs,
            default => self::Colorado,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CmpsSf => 'Glasgow abrégée (CMPS-SF)',
            self::Fgs => 'Grimaces du chat (FGS)',
            self::Colorado => 'Colorado (douleur aiguë)',
        };
    }

    public function max(): int
    {
        return match ($this) {
            self::CmpsSf => 24,
            self::Fgs => 10,
            self::Colorado => 4,
        };
    }

    /** Seuil de traitement : à partir de ce score, l'antalgie est à revoir. */
    public function threshold(): int
    {
        return match ($this) {
            self::CmpsSf => 6,
            self::Fgs => 4,
            self::Colorado => 2,
        };
    }

    /** @return array{code: string, label: string, max: int, threshold: int} */
    public function toArray(): array
    {
        return ['code' => $this->value, 'label' => $this->label(), 'max' => $this->max(), 'threshold' => $this->threshold()];
    }
}
