<?php

namespace App\Enums;

/**
 * Catégories du catalogue produits (docs/data-model.md §14) — valeurs =
 * codes du front (CAT_NAMES).
 */
enum CategorieProduit: string
{
    case Medicament = 'med';
    case Alimentation = 'ali';
    case Soin = 'soin';
    case Antiparasitaire = 'apara';
    case Phytotherapie = 'phyto';
    case Materiel = 'mat';
    // La boutique ne vend pas que des médicaments (Jim, 03.11.2026)
    case Accessoire = 'acc';

    /** Le nom de la famille, montré au public (filtres de la boutique du site, 07.11.2026) */
    public function label(): string
    {
        return match ($this) {
            self::Medicament => 'Médicaments',
            self::Alimentation => 'Alimentation',
            self::Soin => 'Soins et hygiène',
            self::Antiparasitaire => 'Antiparasitaires',
            self::Phytotherapie => 'Phytothérapie',
            self::Materiel => 'Matériel',
            self::Accessoire => 'Accessoires et jouets',
        };
    }

    /** Les familles propres aux médicaments : ordonnance (aussi les antiparasitaires) */
    public function isDrug(): bool
    {
        return $this === self::Medicament || $this === self::Antiparasitaire;
    }
}
