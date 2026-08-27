<?php

namespace App\Enums;

/**
 * Pièces numérotées par séquence annuelle et par organisation
 * (docs/data-model.md §18) : FC-2026-0345, DV-2026-018, RX-2026-058,
 * Z-2026-041, BC-2026-031, A-26-0141.
 */
enum PieceNumerotee: string
{
    case Facture = 'FC';
    case Devis = 'DV';
    case Ordonnance = 'RX';
    case ClotureCaisse = 'Z';
    case CommandeFournisseur = 'BC';
    case FactureAbonnement = 'F';
    case CommandeBoutique = 'C';
    case AnalyseLabo = 'A';

    /** Largeur du compteur dans le numéro formaté. */
    public function chiffres(): int
    {
        return in_array($this, [self::Facture, self::AnalyseLabo], true) ? 4 : 3;
    }

    /** La commande boutique s'affiche sans année (« C-104 »). */
    public function avecAnnee(): bool
    {
        return $this !== self::CommandeBoutique;
    }

    /** L'analyse labo porte l'année sur deux chiffres (« A-26-0141 »). */
    public function anneeCourte(): bool
    {
        return $this === self::AnalyseLabo;
    }

    public function formater(int $annee, int $compteur): string
    {
        if (! $this->avecAnnee()) {
            return sprintf('%s-%0'.$this->chiffres().'d', $this->value, $compteur);
        }

        return $this->anneeCourte()
            ? sprintf('%s-%02d-%0'.$this->chiffres().'d', $this->value, $annee % 100, $compteur)
            : sprintf('%s-%d-%0'.$this->chiffres().'d', $this->value, $annee, $compteur);
    }
}
