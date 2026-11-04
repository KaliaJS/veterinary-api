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

    /** Les familles propres aux médicaments : ordonnance (aussi les antiparasitaires) */
    public function isDrug(): bool
    {
        return $this === self::Medicament || $this === self::Antiparasitaire;
    }
}
