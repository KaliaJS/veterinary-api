<?php

namespace App\Enums;

/**
 * Taux de TVA à la vente d'un article (03.11.2026) : réduit pour les
 * médicaments, antiparasitaires, la phytothérapie et l'alimentation,
 * normal pour le reste. Proposé selon la famille, corrigible par article.
 * Le pourcentage vient de config/vat.php, jamais d'ici.
 */
enum VatRate: string
{
    case Normal = 'normal';
    case Reduced = 'reduced';

    /** Le taux en %, tel que configuré. */
    public function percent(): float
    {
        return (float) config('vat.rates.'.$this->value);
    }

    /** Le taux proposé pour une famille d'articles ; une famille nouvelle va au taux normal. */
    public static function forCategory(?CategorieProduit $categorie): self
    {
        return in_array($categorie, [
            CategorieProduit::Medicament,
            CategorieProduit::Antiparasitaire,
            CategorieProduit::Phytotherapie,
            CategorieProduit::Alimentation,
        ], true) ? self::Reduced : self::Normal;
    }
}
