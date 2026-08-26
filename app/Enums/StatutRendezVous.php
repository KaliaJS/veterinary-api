<?php

namespace App\Enums;

/**
 * Cycle de vie d'un rendez-vous. `annule` et `noshow` sont des statuts
 * terminaux : un rendez-vous n'est jamais supprimé (docs/data-model.md).
 */
enum StatutRendezVous: string
{
    case Prevu = 'prevu';
    case Salle = 'salle';
    case Cours = 'cours';
    case Fini = 'fini';
    case Noshow = 'noshow';
    case Annule = 'annule';
}
