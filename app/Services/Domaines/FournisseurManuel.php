<?php

namespace App\Services\Domaines;

/**
 * Vérification DNS seule : actif dès que le domaine pointe vers la cible
 * (CNAME) ou l'IP configurée. Le certificat est l'affaire du serveur
 * (ex. Caddy on-demand TLS, qui demande /public/domaines/autorise).
 */
class FournisseurManuel implements FournisseurDomaines
{
    public function __construct(private ResolveurDns $dns) {}

    public function ajouter(string $domaine): ?string
    {
        return null;
    }

    public function etat(string $domaine, ?string $identifiant): array
    {
        if (config('sites.domaines.test_auto') && str_ends_with($domaine, '.test')) {
            return ['actif' => true, 'erreur' => null];
        }

        $pointe = $this->dns->pointeVers($domaine, (string) config('sites.domaines.cible_cname'), config('sites.domaines.ip'));

        return ['actif' => $pointe, 'erreur' => null];
    }

    public function retirer(string $domaine, ?string $identifiant): void {}
}
