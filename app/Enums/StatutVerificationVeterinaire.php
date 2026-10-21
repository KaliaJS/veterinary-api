<?php

namespace App\Enums;

/**
 * Vérification manuelle d'un vétérinaire par la plateforme Veterinary
 * (badge « Vérifié manuellement ») : demandée par le vétérinaire depuis
 * son profil, décidée par un modérateur. Le registre MedReg ne prouve
 * pas l'identité : seul ce statut ouvre l'annuaire à ses spécialités.
 */
enum StatutVerificationVeterinaire: string
{
    case EnAttente = 'en_attente';
    case Verifie = 'verifie';
    case Refuse = 'refuse';
}
