<?php

namespace App\Enums;

/**
 * Suivi d'une demande de remboursement (docs/data-model.md §6) :
 * à envoyer → envoyée → remboursée.
 */
enum StatutDecompteAssurance: string
{
    case AEnvoyer = 'todo';
    case Envoyee = 'sent';
    case Remboursee = 'paid';
}
