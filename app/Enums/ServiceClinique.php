<?php

namespace App\Enums;

/**
 * Ce qu'une clinique propose, coché par l'administrateur de
 * l'organisation (écran Organisation). Un service sert à la fois
 * l'annuaire public (icônes, filtres) et l'espace vétérinaire : un
 * module n'y est ouvert que si la clinique a le service (middleware
 * `service:`), en plus de la permission du rôle. Une clinique créée
 * n'en a aucun : l'administrateur les coche.
 */
enum ServiceClinique: string
{
    case Hospitalisation = 'hospitalisation';
    case Chirurgie = 'chirurgie';
    case Imagerie = 'imagerie';
    case Laboratoire = 'laboratoire';
    case Boutique = 'boutique';
    case Teleconsultation = 'teleconsultation';
    case Urgences = 'urgences';
    case Domicile = 'domicile';

    public function libelle(): string
    {
        return match ($this) {
            self::Hospitalisation => 'Hospitalisation',
            self::Chirurgie => 'Chirurgie',
            self::Imagerie => 'Imagerie',
            self::Laboratoire => 'Laboratoire',
            self::Boutique => 'Boutique',
            self::Teleconsultation => 'Téléconsultation',
            self::Urgences => 'Urgences',
            self::Domicile => 'Visites à domicile',
        };
    }
}
