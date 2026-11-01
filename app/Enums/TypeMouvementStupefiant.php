<?php

namespace App\Enums;

/**
 * Le type d'une ligne du registre des stupéfiants (01.11.2026, mise aux
 * normes) : ce que demande la comptabilité des art. 57, 63 et 64 OCStup
 * et la liste de contrôle de l'OSAV (D 05, D 06) — réceptions,
 * utilisations et remises (animal, quantité), pertes et vols,
 * éliminations prouvables, retours au fournisseur, corrections liées à la
 * ligne corrigée, inventaires (écart justifié), visas du vétérinaire
 * responsable.
 */
enum TypeMouvementStupefiant: string
{
    case Reception = 'reception';
    case Administration = 'administration';
    case Remise = 'remise';
    case Perte = 'perte';
    case Vol = 'vol';
    case Retour = 'retour';
    case Elimination = 'elimination';
    case Correction = 'correction';
    case Inventaire = 'inventaire';
    case Visa = 'visa';

    public function libelle(): string
    {
        return match ($this) {
            self::Reception => 'Réception',
            self::Administration => 'Administration',
            self::Remise => 'Remise au détenteur',
            self::Perte => 'Perte',
            self::Vol => 'Vol',
            self::Retour => 'Retour au fournisseur',
            self::Elimination => 'Élimination',
            self::Correction => 'Correction',
            self::Inventaire => 'Inventaire',
            self::Visa => 'Visa',
        };
    }

    /** Le sens de la quantité saisie (positive) : +1 entre, −1 sort ; 0 : signée par l'écart ou nulle */
    public function sens(): int
    {
        return match ($this) {
            self::Reception => 1,
            self::Administration, self::Remise, self::Perte, self::Vol, self::Retour, self::Elimination => -1,
            self::Correction, self::Inventaire, self::Visa => 0,
        };
    }

    /** Une sortie vers un animal : son nom et celui de son détenteur sont inscrits */
    public function versUnAnimal(): bool
    {
        return in_array($this, [self::Administration, self::Remise], true);
    }
}
