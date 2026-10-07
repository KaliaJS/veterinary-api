<?php

namespace App\Services\Domaines;

/**
 * Qui sert les domaines personnalisés (certificat compris). « manuel » :
 * le serveur émet le certificat à la demande, on ne vérifie que le DNS ;
 * « cloudflare » : Cloudflare for SaaS (custom hostnames) devant l'app.
 */
interface FournisseurDomaines
{
    /** Déclare le domaine ; renvoie l'identifiant chez le fournisseur, s'il y en a un. */
    public function ajouter(string $domaine): ?string;

    /** @return array{actif: bool, erreur: string|null} */
    public function etat(string $domaine, ?string $identifiant): array;

    public function retirer(string $domaine, ?string $identifiant): void;
}
