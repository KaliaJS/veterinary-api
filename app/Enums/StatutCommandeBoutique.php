<?php

namespace App\Enums;

/**
 * Cycle d'une commande boutique en ligne (docs/data-model.md §14) :
 * à préparer → prête au retrait → retirée. La remise au client sort le
 * stock de la clinique de retrait.
 */
enum StatutCommandeBoutique: string
{
    case APreparer = 'prep';
    case Prete = 'ready';
    case Retiree = 'done';
}
