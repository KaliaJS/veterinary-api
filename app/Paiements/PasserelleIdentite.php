<?php

namespace App\Paiements;

/**
 * Vérification d'identité par Stripe Identity (23.10.2026) : pièce
 * d'identité et selfie, sur la page de Stripe. Remplacée par un double
 * dans les tests.
 */
interface PasserelleIdentite
{
    /**
     * Ouvre la session de vérification d'une personne : une seule par
     * personne (clé d'idempotence sur sa référence).
     *
     * @param  array<string, string>  $metadonnees
     * @return array{id: string, url: ?string}
     */
    public function creerSession(string $reference, string $urlRetour, array $metadonnees): array;

    /**
     * Où en est la session ; `url` (à jour) tant qu'elle attend la
     * personne, nom lu sur la pièce une fois vérifiée.
     *
     * @return array{id: string, statut: string, url: ?string, erreur: ?string, prenom: ?string, nom: ?string}
     */
    public function lireSession(string $id): array;

    public function annulerSession(string $id): void;

    /**
     * Efface chez Stripe tout ce que la session a collecté (pièce, selfie,
     * rapports), irréversible : compte effacé (27.10.2026). Possible pour une
     * session à compléter ou vérifiée ; Stripe annule une session en cours.
     */
    public function effacerSession(string $id): void;
}
