<?php

namespace App\Enums;

/**
 * Pièces numérotées par séquence annuelle et par organisation
 * (docs/data-model.md §18) : FC-2026-0345, DV-2026-018, RX-2026-058,
 * Z-2026-041, BC-2026-031.
 */
enum PieceNumerotee: string
{
    case Facture = 'FC';
    case Devis = 'DV';
    case Ordonnance = 'RX';
    case ClotureCaisse = 'Z';
    case CommandeFournisseur = 'BC';

    /** Largeur du compteur dans le numéro formaté. */
    public function chiffres(): int
    {
        return $this === self::Facture ? 4 : 3;
    }

    public function formater(int $annee, int $compteur): string
    {
        return sprintf('%s-%d-%0'.$this->chiffres().'d', $this->value, $annee, $compteur);
    }
}
