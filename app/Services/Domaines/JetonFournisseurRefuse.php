<?php

namespace App\Services\Domaines;

/**
 * Cloudflare refuse notre jeton (401, 403 : expiré ou retiré, 26.10.2026).
 * Comme une panne, rien ne change pour les domaines ; mais la plateforme
 * est prévenue, sinon plus aucun domaine n'est vérifié sans que personne
 * le sache.
 */
class JetonFournisseurRefuse extends FournisseurIndisponible
{
    public function __construct(public readonly int $statut)
    {
        parent::__construct();
    }
}
