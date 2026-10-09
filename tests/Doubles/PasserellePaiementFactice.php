<?php

namespace Tests\Doubles;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;

/**
 * Double de la passerelle Stripe : intentions et lecteurs en mémoire,
 * erreurs programmables, webhooks signés par la chaîne « signature-valide ».
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

    public ?ErreurPaiement $erreurLecteur = null;

    private int $sequence = 0;

    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence): array
    {
        return ['id' => $this->intention($centimes, $metadonnees, 'requires_payment_method'), 'statut' => 'requires_payment_method'];
    }

    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence): array
    {
        $id = $this->intention($centimes, $metadonnees, 'requires_action');

        return ['id' => $id, 'statut' => 'requires_action', 'url' => 'https://pm-redirects.stripe.com/twint/'.$id];
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

        return ['id' => $evenement['id'], 'type' => $evenement['type'], 'objet' => $evenement['data']['object']];
    }

    /** Fait aboutir (ou échouer) une intention côté « Stripe ». */
    public function conclure(string $intentionId, string $statut, ?string $erreur = null): void
    {
        $this->intentions[$intentionId]['statut'] = $statut;
        $this->intentions[$intentionId]['erreur'] = $erreur;
    }

    /** @param  array<string, string>  $metadonnees */
    private function intention(int $centimes, array $metadonnees, string $statut): string
    {
        $id = 'pi_'.(++$this->sequence);
        $this->intentions[$id] = ['statut' => $statut, 'erreur' => null, 'centimes' => $centimes, 'metadonnees' => $metadonnees];

        return $id;
    }
}
