<?php

namespace App\Enums;

/**
 * Ce qu'une clinique peut faire de l'identité d'un animal dont la fiche
 * est gérée par son propriétaire depuis l'espace client (16.10.2026).
 * `modification` : comme une fiche de clinique ; `lecture` : la clinique
 * consulte, soigne et enregistre ses actes, mais ne modifie pas l'identité
 * (nom, espèce, race, sexe, naissance, stérilisation, puce, AMICUS).
 */
enum AccesClinique: string
{
    case Modification = 'modification';
    case Lecture = 'lecture';
}
