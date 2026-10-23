<?php

namespace Tests\Doubles;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;

/**
 * Double de la passerelle Stripe : intentions et lecteurs en mémoire,
 * erreurs programmables, webhooks signés par la chaîne « signature-valide ».
 * Le compte de la société (`pourCompte`) et la commission sont notés sur
 * chaque intention ; les comptes de paiement sont actifs sauf mention
 * contraire (`etatsComptes`).
 */
class PasserellePaiementFactice implements PasserellePaiement
{
    /** @var array<string, array{statut: string, erreur: ?string, centimes: int, metadonnees: array<string, string>}> */
    public array $intentions = [];

    /** @var list<array{lecteur: string, intention: string}> */
    public array $presentations = [];

    /** @var list<string> */
    public array $lecteursLiberes = [];

    /** @var list<string> */
    public array $locations = [];

    /** @var list<string> intentions remboursées */
    public array $remboursements = [];

    /** @var array<string, int> Montant rendu par intention (centimes) */
    public array $montantsRembourses = [];

    public ?ErreurPaiement $erreurLecteur = null;

    /** Compte de la société visé par les appels suivants (null : la plateforme) */
    public ?string $compte = null;

    /** @var list<array{nom: string, email: ?string, metadonnees: array<string, string>}> */
    public array $comptesCrees = [];

    /** @var array<string, array{paiements: bool, virements: bool, a_completer: bool}> */
    public array $etatsComptes = [];

    /** @var list<array{compte: string, composants: list<string>}> */
    public array $sessions = [];

    private int $sequence = 0;

    public function pourCompte(?string $compte): static
    {
        $this->compte = $compte;

        return $this;
    }

    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array
    {
        return ['id' => $this->intention($centimes, $metadonnees, 'requires_payment_method', $commission), 'statut' => 'requires_payment_method'];
    }

    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence, int $commission = 0): array
    {
        $id = $this->intention($centimes, $metadonnees, 'requires_action', $commission);

        return ['id' => $id, 'statut' => 'requires_action', 'url' => 'https://pm-redirects.stripe.com/twint/'.$id];
    }

    public function creerIntentionEnLigne(int $centimes, array $metadonnees, string $cleIdempotence): array
    {
        $id = $this->intention($centimes, $metadonnees, 'requires_payment_method');

        return ['id' => $id, 'statut' => 'requires_payment_method', 'client_secret' => $id.'_secret_test'];
    }

    public function rembourser(string $intentionId, string $cleIdempotence, ?int $centimes = null): void
    {
        $this->remboursements[] = $intentionId;
        $this->montantsRembourses[$intentionId] = $centimes ?? $this->intentions[$intentionId]['centimes'];
    }

    /** Comme Stripe en Suisse pour une carte en ligne : 2,9 % + 30 centimes */
    public function fraisIntention(string $intentionId): ?int
    {
        return $this->intentions[$intentionId]['statut'] === 'succeeded'
            ? (int) round($this->intentions[$intentionId]['centimes'] * 0.029) + 30
            : null;
    }

    public function presenterSurLecteur(string $lecteurId, string $intentionId): void
    {
        if ($this->erreurLecteur) {
            throw $this->erreurLecteur;
        }

        $this->presentations[] = ['lecteur' => $lecteurId, 'intention' => $intentionId];
    }

    public function annulerSurLecteur(string $lecteurId): void
    {
        $this->lecteursLiberes[] = $lecteurId;
    }

    public function lireIntention(string $intentionId): array
    {
        return ['id' => $intentionId, 'statut' => $this->intentions[$intentionId]['statut'], 'erreur' => $this->intentions[$intentionId]['erreur']];
    }

    public function annulerIntention(string $intentionId): array
    {
        if ($this->intentions[$intentionId]['statut'] === 'succeeded') {
            throw new ErreurPaiement('Déjà payée.');
        }

        $this->intentions[$intentionId]['statut'] = 'canceled';

        return $this->lireIntention($intentionId);
    }

    public function creerLocation(string $nom, array $adresse, array $metadonnees): string
    {
        return $this->locations[] = 'tml_'.(++$this->sequence);
    }

    public function appairerLecteur(string $codeAppairage, string $libelle, string $locationId): array
    {
        return ['id' => 'tmr_'.(++$this->sequence), 'libelle' => $libelle, 'modele' => 'bbpos_wisepos_e', 'numero_serie' => 'WSC-'.$this->sequence, 'en_ligne' => true];
    }

    public function lireLecteur(string $lecteurId): array
    {
        return ['id' => $lecteurId, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e', 'numero_serie' => null, 'en_ligne' => true];
    }

    public function supprimerLecteur(string $lecteurId): void {}

    public function simulerCarte(string $lecteurId): void {}

    public function lireEvenement(string $corps, string $signature): array
    {
        if ($signature !== 'signature-valide') {
            throw new ErreurPaiement('Signature de webhook invalide.');
        }

        $evenement = json_decode($corps, true);

        return ['id' => $evenement['id'], 'type' => $evenement['type'], 'objet' => $evenement['data']['object'], 'compte' => $evenement['account'] ?? null];
    }

    public function creerComptePaiement(array $societe): string
    {
        $this->comptesCrees[] = $societe;

        return 'acct_'.(++$this->sequence);
    }

    public function lireComptePaiement(string $compte): array
    {
        return $this->etatsComptes[$compte] ?? ['paiements' => true, 'virements' => true, 'a_completer' => false];
    }

    public function creerSessionCompte(string $compte, array $composants): string
    {
        $this->sessions[] = ['compte' => $compte, 'composants' => $composants];

        return 'accs_secret_'.(++$this->sequence);
    }

    /** Fait aboutir (ou échouer) une intention côté « Stripe ». */
    public function conclure(string $intentionId, string $statut, ?string $erreur = null): void
    {
        $this->intentions[$intentionId]['statut'] = $statut;
        $this->intentions[$intentionId]['erreur'] = $erreur;
    }

    /** @param  array<string, string>  $metadonnees */
    private function intention(int $centimes, array $metadonnees, string $statut, int $commission = 0): string
    {
        $id = 'pi_'.(++$this->sequence);
        $this->intentions[$id] = ['statut' => $statut, 'erreur' => null, 'centimes' => $centimes, 'metadonnees' => $metadonnees, 'compte' => $this->compte, 'commission' => $commission];

        return $id;
    }
}
