<?php

namespace App\Enums;

/**
 * Odontogramme Triadan : une dent saine n'a pas de ligne en base
 * (l'absence d'enregistrement vaut « sain »). Une dent peut porter
 * plusieurs états à la fois (04.11.2026).
 */
enum EtatDent: string
{
    case Tartre = 'tartre';
    case Gingivite = 'gingivite';
    case Parodontite = 'parodontite';
    case Fracture = 'fracture';
    // Schéma dentaire modernisé (04.11.2026) : mobilité (M de l'AVDC) et
    // résorption (TR de l'AVDC)
    case Mobilite = 'mobilite';
    case Resorption = 'resorption';
    // Demande des vétérinaires (04.11.2026) ; extraction : extraite ou à
    // extraire, absente : manquait déjà
    case Tumeur = 'tumeur';
    case Extraction = 'extraction';
    case Absente = 'absente';
}
