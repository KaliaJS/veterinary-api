<?php

namespace App\Services\Facturation;

use App\Enums\Espece;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Enums\VatRate;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Patient;
use App\Models\Produit;

/**
 * Le taux de TVA d'une ligne de facture, calculé seul selon son contexte
 * (décision de Jim, 03.11.2026, Info TVA 21 de l'AFC) ; personne ne le
 * choisit. Un soin suit l'espèce de l'animal : taux normal pour un animal
 * de compagnie, réduit pour un animal de rente. Un article donné pendant
 * le soin suit le soin ; remis au propriétaire ou vendu, il prend son
 * propre taux de vente. Une remise n'a pas de taux : elle se répartit au
 * prorata des taux de la facture. L'acompte d'un devis se répartit sur
 * ses taux, et sa déduction reprend le taux de chaque part. Une société non assujettie ne facture aucune TVA.
 * Exemple de Jim : une pommade appliquée pendant la consultation d'un
 * chien est au taux normal, le même tube remis au propriétaire au taux
 * réduit.
 */
class LineVat
{
    /** Le contexte d'une ligne qui n'en dit rien : un article est vendu, une ligne négative sans article est une remise, le reste un soin. */
    public static function defaultContext(FactureLigne $ligne): VatContext
    {
        if ($ligne->produit_id === null && (float) $ligne->prix_chf < 0) {
            return VatContext::Discount;
        }

        return $ligne->produit_id !== null && $ligne->type === TypeLigneFacture::Medicament ? VatContext::Sale : VatContext::Care;
    }

    /**
     * Le contexte proposé pour un article : donné pendant le soin s'il sort
     * à la dose (contenance et unité de dose) et que la facture contient un
     * soin, sinon remis au propriétaire (vérification du 03.11.2026).
     */
    public static function articleContext(Produit $produit, bool $hasCare): VatContext
    {
        return $hasCare && $produit->content_per_unit !== null && $produit->dose_unit !== null ? VatContext::Care : VatContext::Sale;
    }

    /** Le taux de la ligne sur sa facture, en % ; null pour une remise, répartie au prorata. */
    public static function forLine(FactureLigne $ligne, Facture $facture): ?float
    {
        $context = $ligne->vat_context ?? self::defaultContext($ligne);
        if ($context === VatContext::Discount) {
            return null;
        }
        // Une part d'acompte : le taux du devis, ou celui de la facture d'acompte pour sa déduction,
        // posé par CreationFacture, même si l'assujettissement a changé depuis
        if ($context === VatContext::Deposit && $ligne->taux_tva !== null) {
            return (float) $ligne->taux_tva;
        }
        // Une facture d'avant (des lignes sans contexte) garde son taux unique : jamais deux régimes mêlés
        if ($facture->exists && FactureLigne::query()->where('facture_id', $facture->id)->whereNull('vat_context')->exists()) {
            return (float) $facture->taux_tva;
        }
        // La facture garde l'assujettissement de sa société au jour de l'émission
        if ((float) $facture->taux_tva <= 0) {
            return 0.0;
        }

        if ($context === VatContext::Sale) {
            $produit = $ligne->produit_id === null ? null : Produit::withoutGlobalScopes()->find($ligne->produit_id);

            return self::saleRate($produit);
        }

        return self::careRate(self::speciesOf($facture));
    }

    /** Le taux de vente d'un article, en % ; sans article, le taux normal. */
    public static function saleRate(?Produit $produit): float
    {
        return ($produit === null ? VatRate::Normal : $produit->vat_rate)->percent();
    }

    /** Le taux d'un soin à un animal de cette espèce, en % ; sans espèce connue, un animal de compagnie. */
    public static function careRate(?Espece $espece): float
    {
        return ($espece?->isLivestock() ? VatRate::Reduced : VatRate::Normal)->percent();
    }

    /**
     * L'espèce qui décide du soin : celle de la fiche patient, ou celle du
     * rendez-vous ; jamais une espèce envoyée sans fiche (vérification du
     * 03.11.2026).
     */
    private static function speciesOf(Facture $facture): ?Espece
    {
        if ($facture->patient_id !== null) {
            return Patient::withoutGlobalScopes()->find($facture->patient_id)?->espece;
        }

        return $facture->rendez_vous_id !== null ? $facture->species : null;
    }
}
