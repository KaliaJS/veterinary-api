<?php

namespace App\Enums;

/**
 * Les ajustements manuels de stock sont toujours motivés
 * (docs/data-model.md §14 — enum REAS du front).
 */
enum MotifAjustementStock: string
{
    case Reception = 'recep';
    case Inventaire = 'inv';
    case Casse = 'casse';
    case Peremption = 'peremp';
    case UsageInterne = 'usage';

    public function libelle(): string
    {
        return match ($this) {
            self::Reception => 'Réception manuelle',
            self::Inventaire => 'Correction d’inventaire',
            self::Casse => 'Casse / perte',
            self::Peremption => 'Périmé — écarté',
            self::UsageInterne => 'Usage interne clinique',
        };
    }
}
