<?php

namespace App\Paiements;

/**
 * Abonnement SaaS des organisations chez le prestataire de paiement
 * (Stripe Billing) : client, carte enregistrée hors session, abonnement à
 * une formule, achats ponctuels facturés et prélevés aussitôt. Les objets
 * sont rendus tels que le prestataire les sert (tableaux), comme dans les
 * webhooks : un seul code les lit (AbonnementSaas). Erreurs : ErreurPaiement
 * au message lisible.
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

    /** La carte devient celle des factures du client. */
    public function definirCarteParDefaut(string $client, string $moyen): void;

    public function detacherMoyenPaiement(string $moyen): void;

    /** Prix récurrent d'une formule (créé au premier besoin), par sa clé. */
    public function prixFormule(string $cle, string $produit, int $centimes, string $intervalle): string;

    /**
     * Taux de TVA suisse appliqué aux factures (créé au premier besoin) :
     * en sus (prix HT des cliniques) ou inclus (prix TTC des propriétaires).
     */
    public function tauxTva(bool $inclus = false): string;

    /**
     * @param  array<string, string>  $metadonnees
     * @return array<string, mixed> abonnement
     */
    public function creerAbonnement(string $client, string $prix, string $tva, array $metadonnees, string $cleIdempotence): array;

    /**
     * Nouvelle formule ou nouveau cycle, proratisés et facturés aussitôt.
     *
     * @param  array<string, string>  $metadonnees
     * @return array<string, mixed> abonnement
     */
    public function changerAbonnement(string $abonnement, string $prix, array $metadonnees): array;

    /** @return array<string, mixed> abonnement */
    public function finDePeriode(string $abonnement, bool $resilier): array;

    /** @return array<string, mixed> abonnement */
    public function lireAbonnement(string $abonnement): array;

    /**
     * Achat ponctuel : facture finalisée et prélevée sur la carte par défaut.
     *
     * @param  array<string, string>  $metadonnees
     * @return array<string, mixed> facture
     */
    public function facturerAchat(string $client, int $centimes, string $libelle, string $tva, array $metadonnees, string $cleIdempotence): array;

    /** @return array<string, mixed> facture */
    public function lireFacture(string $facture): array;
}
