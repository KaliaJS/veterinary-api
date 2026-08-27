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
}
