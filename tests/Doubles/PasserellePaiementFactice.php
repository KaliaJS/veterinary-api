<?php

namespace Tests\Doubles;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;

/**
 * Double de la passerelle Stripe, fidèle à Connect : intentions et
 * lecteurs en mémoire, webhooks signés par la chaîne « signature-valide ».
 *
 * Comme la vraie passerelle, `pourCompte` rend une copie sur le compte de
 * la société (les données restent partagées) : chaque appel est noté avec
 * le compte visé (`appels`, l'en-tête Stripe-Account). Une intention ne se
 * lit, ne s'annule et ne se rembourse que sur le compte qui l'a reçue ;
 * ailleurs, ou inconnue, c'est une `ErreurPaiement` (`resource_missing`).
 * Les clés d'idempotence sont mémorisées par compte : la même clé avec les
 * mêmes paramètres rejoue la première réponse, avec d'autres paramètres
 * elle est refusée. Un remboursement ne rend jamais plus que ce qui reste,
 * et toute méthode peut échouer sur demande (`erreurs`, ex. `rembourser`).
 * Les comptes de paiement sont actifs sauf mention contraire (`etatsComptes`).
 */
class PasserellePaiementFactice implements PasserellePaiement
{
    /** Statuts où Stripe accepte encore d'annuler une intention */
    private const ANNULABLES = ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture', 'processing'];

    /** @var array<string, array{statut: string, erreur: ?string, centimes: int, metadonnees: array<string, string>, compte: ?string, commission: int}> */
    public array $intentions = [];

    /** @var list<array{methode: string, compte: ?string, objet: ?string, cle: ?string}> Chaque requête reçue, avec le compte visé */
    public array $appels = [];

    /** @var array<string, array{methode: string, parametres: array<string, mixed>, reponse: mixed}> Requêtes idempotentes, par « compte|clé » */
    public array $clesIdempotence = [];

    /** @var array<string, ErreurPaiement> Erreur levée à chaque appel de la méthode tant qu'elle est là */
    public array $erreurs = [];

    /** @var list<array{lecteur: string, intention: string}> */
    public array $presentations = [];

    /** @var list<string> */
    public array $lecteursLiberes = [];

    /** @var list<string> */
    public array $locations = [];

    /** @var list<string> intentions remboursées */
    public array $remboursements = [];

    /** @var array<string, int> Montant rendu par intention, cumulé (centimes) */
    public array $montantsRembourses = [];

    /** @var array<string, ?string> Compte qui a rendu, par intention */
    public array $comptesRembourses = [];

    /** @var list<array{intention: string, compte: ?string, centimes: int, cle: string}> Chaque remboursement accordé */
    public array $rendus = [];

    public ?ErreurPaiement $erreurLecteur = null;

    /** Compte de la société visé par cette copie (null : la plateforme) */
    public ?string $compte = null;

    /** @var list<array{nom: string, email: ?string, metadonnees: array<string, string>}> */
    public array $comptesCrees = [];

    /** @var array<string, array{paiements: bool, virements: bool, a_completer: bool}> */
    public array $etatsComptes = [];

    /** @var list<array{compte: string, composants: list<string>}> */
    public array $sessions = [];

    private int $sequence = 0;

    /** Une copie sur le compte donné ; intentions, journal et réglages restent communs. */
    public function pourCompte(?string $compte): static
    {
        $copie = clone $this;
        foreach (array_keys(get_object_vars($this)) as $propriete) {
            if ($propriete !== 'compte') {
                $copie->{$propriete} = &$this->{$propriete};
            }
        }
        $copie->compte = $compte;

        return $copie;
    }

    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array
    {
        $this->noter(__FUNCTION__, null, $cleIdempotence);

        return $this->idempotent(__FUNCTION__, $cleIdempotence, [$centimes, $metadonnees, $commission], fn () => [
            'id' => $this->intention($centimes, $metadonnees, 'requires_payment_method', $commission),
            'statut' => 'requires_payment_method',
        ]);
    }

    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence, int $commission = 0): array
    {
        $this->noter(__FUNCTION__, null, $cleIdempotence);

        return $this->idempotent(__FUNCTION__, $cleIdempotence, [$centimes, $metadonnees, $urlRetour, $commission], function () use ($centimes, $metadonnees, $commission): array {
            $id = $this->intention($centimes, $metadonnees, 'requires_action', $commission);

            return ['id' => $id, 'statut' => 'requires_action', 'url' => 'https://pm-redirects.stripe.com/twint/'.$id];
        });
    }

    public function creerIntentionEnLigne(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array
    {
        $this->noter(__FUNCTION__, null, $cleIdempotence);

        return $this->idempotent(__FUNCTION__, $cleIdempotence, [$centimes, $metadonnees, $commission], function () use ($centimes, $metadonnees, $commission): array {
            $id = $this->intention($centimes, $metadonnees, 'requires_payment_method', $commission);

            return ['id' => $id, 'statut' => 'requires_payment_method', 'client_secret' => $id.'_secret_test'];
        });
    }

    /** Comme Stripe : jamais plus que ce qui reste à rendre ; la commission n'est pas touchée. */
    public function rembourser(string $intentionId, string $cleIdempotence, ?int $centimes = null): void
    {
        $this->noter(__FUNCTION__, $intentionId, $cleIdempotence);

        $this->idempotent(__FUNCTION__, $cleIdempotence, [$intentionId, $centimes], function () use ($intentionId, $cleIdempotence, $centimes): bool {
            $intention = $this->trouver($intentionId);
            $dejaRendu = $this->montantsRembourses[$intentionId] ?? 0;
            $reste = $intention['centimes'] - $dejaRendu;
            $montant = $centimes ?? $reste;

            if ($reste <= 0) {
                throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'charge_already_refunded');
            }
            if ($montant <= 0 || $montant > $reste) {
                throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'amount_too_large');
            }

            $this->remboursements[] = $intentionId;
            $this->comptesRembourses[$intentionId] = $this->compte;
            $this->montantsRembourses[$intentionId] = $dejaRendu + $montant;
            $this->rendus[] = ['intention' => $intentionId, 'compte' => $this->compte, 'centimes' => $montant, 'cle' => $cleIdempotence];

            return true;
        });
    }

    /** Comme Stripe en Suisse pour une carte en ligne : 2,9 % + 30 centimes, plus la commission */
    public function fraisIntention(string $intentionId): ?int
    {
        $this->noter(__FUNCTION__, $intentionId);
        $intention = $this->trouver($intentionId);

        return $intention['statut'] === 'succeeded'
            ? (int) round($intention['centimes'] * 0.029) + 30 + $intention['commission']
            : null;
    }

    public function presenterSurLecteur(string $lecteurId, string $intentionId): void
    {
        $this->noter(__FUNCTION__, $lecteurId);
        if ($this->erreurLecteur) {
            throw $this->erreurLecteur;
        }
        if ($this->trouver($intentionId)['statut'] !== 'requires_payment_method') {
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'payment_intent_unexpected_state');
        }

        $this->presentations[] = ['lecteur' => $lecteurId, 'intention' => $intentionId];
    }

    public function annulerSurLecteur(string $lecteurId): void
    {
        $this->noter(__FUNCTION__, $lecteurId);
        $this->lecteursLiberes[] = $lecteurId;
    }

    public function lireIntention(string $intentionId): array
    {
        $this->noter(__FUNCTION__, $intentionId);
        $intention = $this->trouver($intentionId);

        return ['id' => $intentionId, 'statut' => $intention['statut'], 'erreur' => $intention['erreur']];
    }

    /** Comme Stripe : une intention payée ou déjà annulée ne s'annule plus. */
    public function annulerIntention(string $intentionId): array
    {
        $this->noter(__FUNCTION__, $intentionId);
        if (! in_array($this->trouver($intentionId)['statut'], self::ANNULABLES, true)) {
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'payment_intent_unexpected_state');
        }

        $this->intentions[$intentionId]['statut'] = 'canceled';

        return ['id' => $intentionId, 'statut' => 'canceled', 'erreur' => $this->intentions[$intentionId]['erreur']];
    }

    public function creerLocation(string $nom, array $adresse, array $metadonnees): string
    {
        $this->noter(__FUNCTION__);

        return $this->locations[] = 'tml_'.(++$this->sequence);
    }

    public function appairerLecteur(string $codeAppairage, string $libelle, string $locationId): array
    {
        $this->noter(__FUNCTION__, $locationId);

        return ['id' => 'tmr_'.(++$this->sequence), 'libelle' => $libelle, 'modele' => 'bbpos_wisepos_e', 'numero_serie' => 'WSC-'.$this->sequence, 'en_ligne' => true];
    }

    public function lireLecteur(string $lecteurId): array
    {
        $this->noter(__FUNCTION__, $lecteurId);

        return ['id' => $lecteurId, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e', 'numero_serie' => null, 'en_ligne' => true];
    }

    public function supprimerLecteur(string $lecteurId): void
    {
        $this->noter(__FUNCTION__, $lecteurId);
    }

    public function simulerCarte(string $lecteurId): void
    {
        $this->noter(__FUNCTION__, $lecteurId);
    }

    public function lireEvenement(string $corps, string $signature): array
    {
        if ($signature !== 'signature-valide') {
            throw new ErreurPaiement('Signature de webhook invalide.');
        }

        $evenement = json_decode($corps, true);

        return ['id' => $evenement['id'], 'type' => $evenement['type'], 'objet' => $evenement['data']['object'], 'compte' => $evenement['account'] ?? null];
    }

    /** Au niveau de la plateforme, comme la vraie passerelle (jamais d'en-tête Stripe-Account). */
    public function creerComptePaiement(array $societe): string
    {
        $this->noter(__FUNCTION__, null, null, plateforme: true);
        $this->comptesCrees[] = $societe;

        return 'acct_'.(++$this->sequence);
    }

    public function lireComptePaiement(string $compte): array
    {
        $this->noter(__FUNCTION__, $compte, null, plateforme: true);

        return $this->etatsComptes[$compte] ?? ['paiements' => true, 'virements' => true, 'a_completer' => false];
    }

    public function creerSessionCompte(string $compte, array $composants, bool $remboursements = false): string
    {
        $this->noter(__FUNCTION__, $compte, null, plateforme: true);
        $this->sessions[] = ['compte' => $compte, 'composants' => $composants, 'remboursements' => $remboursements];

        return 'accs_secret_'.(++$this->sequence);
    }

    /** Fait aboutir (ou échouer) une intention côté « Stripe ». */
    public function conclure(string $intentionId, string $statut, ?string $erreur = null): void
    {
        $this->intentions[$intentionId]['statut'] = $statut;
        $this->intentions[$intentionId]['erreur'] = $erreur;
    }

    /**
     * Les appels notés, d'une méthode ou de toutes.
     *
     * @return list<array{methode: string, compte: ?string, objet: ?string, cle: ?string}>
     */
    public function appelsDe(?string $methode = null): array
    {
        return array_values(array_filter($this->appels, fn (array $appel) => $methode === null || $appel['methode'] === $methode));
    }

    /** @param  array<string, string>  $metadonnees */
    private function intention(int $centimes, array $metadonnees, string $statut, int $commission = 0): string
    {
        $id = 'pi_'.(++$this->sequence);
        $this->intentions[$id] = ['statut' => $statut, 'erreur' => null, 'centimes' => $centimes, 'metadonnees' => $metadonnees, 'compte' => $this->compte, 'commission' => $commission];

        return $id;
    }

    /**
     * L'intention, sur le compte qui l'a reçue seulement : ailleurs, comme
     * chez Stripe, elle n'existe pas.
     *
     * @return array{statut: string, erreur: ?string, centimes: int, metadonnees: array<string, string>, compte: ?string, commission: int}
     */
    private function trouver(string $intentionId): array
    {
        $intention = $this->intentions[$intentionId] ?? null;

        if ($intention === null || $intention['compte'] !== $this->compte) {
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'resource_missing');
        }

        return $intention;
    }

    /**
     * La même clé (sur le même compte) rejoue la première réponse ; avec
     * d'autres paramètres, Stripe refuse (`idempotency_error`, sans code).
     *
     * @template T
     *
     * @param  array<int|string, mixed>  $parametres
     * @param  callable(): T  $executer
     * @return T
     */
    private function idempotent(string $methode, string $cle, array $parametres, callable $executer): mixed
    {
        $index = ($this->compte ?? 'plateforme').'|'.$cle;
        $deja = $this->clesIdempotence[$index] ?? null;

        if ($deja !== null) {
            if ($deja['methode'] !== $methode || $deja['parametres'] !== $parametres) {
                throw new ErreurPaiement(ErreurPaiement::GENERIQUE);
            }

            return $deja['reponse'];
        }

        $reponse = $executer();
        $this->clesIdempotence[$index] = ['methode' => $methode, 'parametres' => $parametres, 'reponse' => $reponse];

        return $reponse;
    }

    /** Note la requête reçue, puis lève l'erreur programmée pour cette méthode s'il y en a une. */
    private function noter(string $methode, ?string $objet = null, ?string $cle = null, bool $plateforme = false): void
    {
        $this->appels[] = ['methode' => $methode, 'compte' => $plateforme ? null : $this->compte, 'objet' => $objet, 'cle' => $cle];

        if (isset($this->erreurs[$methode])) {
            throw $this->erreurs[$methode];
        }
    }
}
