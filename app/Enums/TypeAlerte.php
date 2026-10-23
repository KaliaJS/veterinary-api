<?php

namespace App\Enums;

/**
 * Familles d'alertes de la clinique (23.10.2026), dans l'app et par
 * e-mail ; chacun peut couper les e-mails d'une famille dans ses réglages.
 * Stock : produits sous le seuil, lots périmés ou qui périment bientôt
 * (domaine stocks). Consommation : plafonds de la transcription et des
 * suggestions diagnostiques (domaine organisation). Paiement : paiements
 * contestés ou remboursés chez Stripe (domaine facturation).
 */
enum TypeAlerte: string
{
    case Stock = 'stock';
    case Consommation = 'consommation';
    case Paiement = 'paiement';
}
