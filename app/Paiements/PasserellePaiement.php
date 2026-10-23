<?php

namespace App\Paiements;

/**
 * Passerelle vers le prestataire de paiement (Stripe) — sans métier :
 * ni facture, ni clinique, seulement des montants, des identifiants et
 * des métadonnées. Remplacée par un double dans les tests.
 *
 * Les statuts d'intention sont ceux de Stripe (`succeeded`,
 * `requires_payment_method`, `canceled`…) ; les échecs remontent en
 * `ErreurPaiement`, avec un message lisible.
 *
 * Connect (23.10.2026) : chaque société encaisse sur son propre compte
 * (sans tableau de bord, risque et frais chez le prestataire). Les
 * paiements, terminaux et remboursements passent par `pourCompte` ; la
 * plateforme prend sa commission sur les intentions (`commission`, en
 * centimes). Les comptes se créent et se lisent au niveau de la plateforme.
 */
interface PasserellePaiement
{
    /** La même passerelle, sur le compte de paiement d'une société (null : la plateforme). */
    public function pourCompte(?string $compte): static;

    /**
     * Intention de paiement par carte sur un terminal (card_present), avec
     * la commission de la plateforme.
     *
     * @param  array<string, string>  $metadonnees
     * @return array{id: string, statut: string}
     */
    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array;

    /**
     * Intention TWINT confirmée : renvoie l'URL de la page TWINT (QR à
     * scanner par le client).
     *
     * @param  array<string, string>  $metadonnees
     * @return array{id: string, statut: string, url: ?string}
     */
    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence, int $commission = 0): array;

    /** Envoie l'intention au lecteur : il affiche le montant et attend la carte. */
    public function presenterSurLecteur(string $lecteurId, string $intentionId): void;

    /** Libère le lecteur (annule l'action en cours, s'il y en a une). */
    public function annulerSurLecteur(string $lecteurId): void;

    /**
     * Paiement en ligne par le client (Payment Element de Stripe.js : carte,
     * TWINT, ce que le compte accepte) : acompte d'une réservation.
     *
     * @param  array<string, string>  $metadonnees
     * @return array{id: string, statut: string, client_secret: string}
     */
    public function creerIntentionEnLigne(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array;

    /** Rembourse une intention payée : entièrement, ou le montant donné (centimes). */
    public function rembourser(string $intentionId, string $cleIdempotence, ?int $centimes = null): void;

    /** Frais retenus sur une intention payée, prestataire et commission (centimes), null s'ils ne sont pas encore connus. */
    public function fraisIntention(string $intentionId): ?int;

    /** @return array{id: string, statut: string, erreur: ?string} */
    public function lireIntention(string $intentionId): array;

    /** @return array{id: string, statut: string, erreur: ?string} */
    public function annulerIntention(string $intentionId): array;

    /**
     * @param  array{rue: ?string, npa: ?string, ville: ?string}  $adresse
     * @param  array<string, string>  $metadonnees
     */
    public function creerLocation(string $nom, array $adresse, array $metadonnees): string;

    /** @return array{id: string, libelle: string, modele: ?string, numero_serie: ?string, en_ligne: bool} */
    public function appairerLecteur(string $codeAppairage, string $libelle, string $locationId): array;

    /** @return array{id: string, libelle: string, modele: ?string, numero_serie: ?string, en_ligne: bool} */
    public function lireLecteur(string $lecteurId): array;

    public function supprimerLecteur(string $lecteurId): void;

    /** Mode test : simule la carte présentée sur un lecteur simulé. */
    public function simulerCarte(string $lecteurId): void;

    /**
     * Vérifie la signature d'un webhook (de la plateforme ou des comptes
     * des sociétés) et renvoie l'événement, avec le compte qui l'a émis.
     *
     * @return array{id: string, type: string, objet: array<string, mixed>, compte: ?string}
     */
    public function lireEvenement(string $corps, string $signature): array;

    /**
     * Ouvre le compte de paiement d'une société : sans tableau de bord,
     * informations légales, pertes et frais chez le prestataire.
     *
     * @param  array{nom: string, nom_commercial?: string, email: ?string, ide?: ?string, adresse?: ?array{rue: ?string, npa: ?string, ville: ?string}, telephone?: ?string, metadonnees: array<string, string>}  $societe
     */
    public function creerComptePaiement(array $societe): string;

    /** @return array{paiements: bool, virements: bool, a_completer: bool} */
    public function lireComptePaiement(string $compte): array;

    /**
     * Session des composants intégrés (inscription, gestion du compte,
     * paiements, virements, notifications) : le secret pour le navigateur.
     *
     * @param  list<string>  $composants
     */
    public function creerSessionCompte(string $compte, array $composants): string;
}
