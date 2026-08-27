<?php

namespace App\Enums;

/**
 * Une facture est à encaisser (`due`) ou payée — jamais supprimée hors
 * fenêtre d'annulation d'une création (docs/data-model.md §6).
 */
enum StatutFacture: string
{
    case Due = 'due';
    case Payee = 'payee';
}
