<?php

namespace App\Enums;

/**
 * Où en est une déclaration SI ABV (08.11.2026).
 * - to_complete : il manque ce que la déclaration demande (rien ne part) ;
 * - ready : complète, attend son envoi (file d'attente) ;
 * - sent : reçue par l'OSAV (statut OK) ;
 * - rejected : refusée par l'OSAV (statut NOK), à corriger ;
 * - replaced : envoyée, puis corrigée : désactivée à l'OSAV avant l'envoi de la nouvelle ;
 * - deactivated : désactivée à l'OSAV (prescription annulée) ;
 * - web : un traitement oral de groupe, saisi dans l'application web de l'OSAV.
 */
enum DeclarationStatus: string
{
    case ToComplete = 'to_complete';
    case Ready = 'ready';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Replaced = 'replaced';
    case Deactivated = 'deactivated';
    /** Traitement oral de groupe, saisi par le vétérinaire dans l'application web de l'OSAV */
    case EnteredOnWeb = 'web';

    /** Encore à faire par le vétérinaire (« Terminé à ») */
    public function needsAction(): bool
    {
        return $this === self::ToComplete || $this === self::Rejected;
    }

    /** Se modifie encore : une déclaration envoyée se corrige par une nouvelle */
    public function isEditable(): bool
    {
        return in_array($this, [self::ToComplete, self::Ready, self::Rejected], true);
    }
}
