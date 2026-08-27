<?php

namespace App\Enums;

/**
 * Une ligne de facture est un acte (prestation) ou un médicament /
 * produit délivré — seule la seconde sort du stock (docs/data-model.md
 * §6).
 */
enum TypeLigneFacture: string
{
    case Acte = 'acte';
    case Medicament = 'med';
}
