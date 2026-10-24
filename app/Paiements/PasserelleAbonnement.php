<?php

namespace App\Paiements;

/**
 * Prestataire de paiement des abonnements de la plateforme (Stripe) : il
 * garde les clients et leurs cartes (saisies dans le Payment Element de
 * Stripe, jamais sur nos serveurs) et prélève des montants. Abonnements,
 * renouvellements et factures sont tenus par l'application
 * (AbonnementSaas), plus par Stripe Billing (20.10.2026).
 */
interface PasserelleAbonnement
{
    /** @param  array<string, string>  $metadonnees */
    public function creerClient(string $nom, ?string $email, array $metadonnees): string;

    /**
     * Intention d'enregistrement d'une carte pour ce client (paiements hors session).
     *
     * @return array{id: string, client_secret: string}
     */
    public function creerIntentionCarte(string $client): array;

    /** @return array<string, mixed> moyen de paiement (id, customer, card…) */
    public function lireMoyenPaiement(string $moyen): array;

    /** La carte devient celle des prélèvements du client. */
    public function definirCarteParDefaut(string $client, string $moyen): void;

    public function detacherMoyenPaiement(string $moyen): void;

    /**
     * Prélève la carte enregistrée. Hors session (renouvellement), le client
     * n'est pas là ; sinon il paie à l'instant et peut confirmer auprès de sa
     * banque si elle le demande (`action_requise`, avec le secret à passer à
     * Stripe.js).
     *
     * @param  array<string, string>  $metadonnees
     * @return array{id: ?string, statut: 'reussi'|'action_requise'|'refuse'|'en_cours', client_secret: ?string, message: ?string}
     */
    public function prelever(string $client, string $moyen, int $centimes, string $libelle, array $metadonnees, bool $horsSession, string $cleIdempotence): array;

    /**
     * Où en est un prélèvement (après une confirmation auprès de la banque).
     *
     * @return array{id: ?string, statut: 'reussi'|'action_requise'|'refuse'|'en_cours', client_secret: ?string, message: ?string}
     */
    public function lirePrelevement(string $id): array;

    /**
     * Annule un prélèvement qui attend la confirmation du client (24.10.2026) :
     * avant d'en lancer un autre, pour qu'il ne confirme jamais les deux.
     * Déjà réussi ou traité entre-temps, l'état est relu et renvoyé.
     *
     * @return array{id: ?string, statut: 'reussi'|'action_requise'|'refuse'|'en_cours', client_secret: ?string, message: ?string}
     */
    public function annulerPrelevement(string $id): array;
}
