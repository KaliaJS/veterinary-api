<?php

namespace App\Enums;

/**
 * Le contexte d'une ligne de facture, qui décide de son taux de TVA
 * (03.11.2026, Info TVA 21 de l'AFC) : un soin, et ce qui est donné
 * pendant le soin, suit l'espèce de l'animal ; un article remis au
 * propriétaire ou vendu prend son propre taux de vente. Une remise (ligne
 * négative sans article) se répartit au prorata des taux de la facture ;
 * une part d'acompte (sur la facture d'acompte comme dans sa déduction)
 * porte le taux du devis qu'elle couvre (04.11.2026).
 */
enum VatContext: string
{
    case Care = 'care';
    case Sale = 'sale';
    case Discount = 'discount';
    case Deposit = 'deposit';
}
