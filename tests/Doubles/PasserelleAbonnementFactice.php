<?php

namespace Tests\Doubles;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;

/**
 * Double de Stripe pour la plateforme : clients, cartes et prélèvements en
 * mémoire. Refus programmable (`refus`, le message de la banque) et
 * confirmation bancaire exigée (`actionRequise`), que le client « fait »
 * ensuite avec `confirmer`.
 */
class PasserelleAbonnementFactice implements PasserelleAbonnement
{
    /** @var array<string, array<string, mixed>> */
    public array $clients = [];

    /** @var array<string, array<string, mixed>> */
    public array $cartes = [];

    /** @var list<string> */
    public array $detachees = [];

    /** @var array<string, array{id: string, client: string, moyen: string, centimes: int, libelle: string, metadonnees: array<string, string>, hors_session: bool, statut: string}> */
    public array $prelevements = [];

    public ?string $refus = null;

    public bool $actionRequise = false;

    /** La banque traite encore le prélèvement (`processing`) */
    public bool $enCours = false;

    /** Le prestataire ne répond pas (délai, réseau) */
    public ?string $panne = null;

    /** Stripe répond par une erreur (ex. 500) ; avec `passeMalgreErreur`, le prélèvement a quand même eu lieu */
    public ?int $erreurServeur = null;

    public bool $passeMalgreErreur = false;

    /** La liste des prélèvements ne répond pas */
    public bool $listeEnPanne = false;

    /** Le serveur s'arrête au milieu du paiement (déploiement) : aucune réponse lue, rien de rattrapé */
    public bool $arret = false;

    /** Stripe refuse la clé de la plateforme (401) à tout appel */
    public bool $cleRefusee = false;

    /** La liste répond encore ce nombre de fois, puis tombe en panne */
    public ?int $listeRepondEncore = null;

    /** La liste ne montre pas encore les prélèvements tout juste créés */
    public bool $listeEnRetard = false;

    /** @var list<string> factures cherchées */
    public array $recherches = [];

    /** @var list<string> Clés d'idempotence reçues, pannes comprises */
    public array $cles = [];

    /** @var list<string> les appels dans l'ordre (prélever, annuler:id) */
    public array $journal = [];

    private int $sequence = 0;

    public function creerClient(string $nom, ?string $email, array $metadonnees): string
    {
        $id = 'cus_'.++$this->sequence;
        $this->clients[$id] = ['nom' => $nom, 'email' => $email, 'metadata' => $metadonnees, 'carte' => null];

        return $id;
    }

    public function creerIntentionCarte(string $client): array
    {
        return ['id' => 'seti_'.++$this->sequence, 'client_secret' => 'seti_secret_'.$this->sequence];
    }

    /** Ce que fait le Payment Element côté navigateur : une carte rattachée au client. */
    public function carteConfirmee(string $client, string $marque = 'visa', string $fin = '4242'): string
    {
        $id = 'pm_'.++$this->sequence;
        $this->cartes[$id] = ['id' => $id, 'customer' => $client, 'card' => ['brand' => $marque, 'last4' => $fin, 'exp_month' => 8, 'exp_year' => 2028]];

        return $id;
    }

    public function lireMoyenPaiement(string $moyen): array
    {
        return $this->cartes[$moyen] ?? throw new ErreurPaiement('Élément introuvable chez Stripe.', 'resource_missing');
    }

    public function definirCarteParDefaut(string $client, string $moyen): void
    {
        $this->clients[$client]['carte'] = $moyen;
    }

    public function detacherMoyenPaiement(string $moyen): void
    {
        $this->detachees[] = $moyen;
        unset($this->cartes[$moyen]);
    }

    public function prelever(string $client, string $moyen, int $centimes, string $libelle, array $metadonnees, bool $horsSession, string $cleIdempotence): array
    {
        $this->cles[] = $cleIdempotence;
        $this->journal[] = 'prelever';
        $this->refuserLaCle();
        // Comme Stripe : une clé déjà servie rend le résultat de la première demande, si la réponse arrive
        foreach ($this->prelevements as $deja) {
            if ($deja['cle'] === $cleIdempotence) {
                if ($this->panne) {
                    throw new ErreurPaiement($this->panne, 'api_error');
                }

                return $this->lirePrelevement($deja['id']);
            }
        }
        if ($this->arret) {
            if ($this->passeMalgreErreur) {
                $id = 'pi_'.++$this->sequence;
                $this->prelevements[$id] = [
                    'id' => $id, 'client' => $client, 'moyen' => $moyen, 'centimes' => $centimes, 'libelle' => $libelle,
                    'metadonnees' => $metadonnees, 'hors_session' => $horsSession, 'statut' => 'reussi', 'cle' => $cleIdempotence,
                ];
            }
            throw new \RuntimeException('Serveur arrêté au milieu du paiement.');
        }
        if ($this->panne) {
            // Réponse perdue, mais Stripe a prélevé quand même
            if ($this->passeMalgreErreur) {
                $id = 'pi_'.++$this->sequence;
                $this->prelevements[$id] = [
                    'id' => $id, 'client' => $client, 'moyen' => $moyen, 'centimes' => $centimes, 'libelle' => $libelle,
                    'metadonnees' => $metadonnees, 'hors_session' => $horsSession, 'statut' => $this->actionRequise ? 'action_requise' : 'reussi', 'cle' => $cleIdempotence,
                ];
            }
            throw new ErreurPaiement($this->panne, 'api_error');
        }
        $id = 'pi_'.++$this->sequence;
        if ($this->erreurServeur !== null) {
            if ($this->passeMalgreErreur) {
                $this->prelevements[$id] = [
                    'id' => $id, 'client' => $client, 'moyen' => $moyen, 'centimes' => $centimes, 'libelle' => $libelle,
                    'metadonnees' => $metadonnees, 'hors_session' => $horsSession, 'statut' => $this->actionRequise ? 'action_requise' : 'reussi', 'cle' => $cleIdempotence,
                ];
            }
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'api_error', $this->erreurServeur);
        }
        $statut = match (true) {
            $this->refus !== null => 'refuse',
            $this->actionRequise => 'action_requise',
            $this->enCours => 'en_cours',
            default => 'reussi',
        };
        $this->prelevements[$id] = [
            'id' => $id, 'client' => $client, 'moyen' => $moyen, 'centimes' => $centimes, 'libelle' => $libelle,
            'metadonnees' => $metadonnees, 'hors_session' => $horsSession, 'statut' => $statut, 'cle' => $cleIdempotence,
        ];

        return $this->lirePrelevement($id);
    }

    public function lirePrelevement(string $id): array
    {
        $this->refuserLaCle();
        $p = $this->prelevements[$id] ?? throw new ErreurPaiement('Élément introuvable chez Stripe.', 'resource_missing');

        return [
            'id' => $id,
            'statut' => $p['statut'] === 'annule' ? 'refuse' : $p['statut'],
            'client_secret' => $id.'_secret',
            'message' => $p['statut'] === 'refuse' ? ($this->refus ?? 'La carte a été refusée par la banque.') : null,
        ];
    }

    public function annulerPrelevement(string $id): array
    {
        $this->journal[] = 'annuler:'.$id;
        $this->refuserLaCle();
        // Comme Stripe : en attente de confirmation ou refusé, il s'annule
        if (in_array($this->prelevements[$id]['statut'] ?? null, ['action_requise', 'refuse'], true)) {
            $this->prelevements[$id]['statut'] = 'annule';
        }

        return $this->lirePrelevement($id);
    }

    public function chercherPrelevements(string $client, string $facture): array
    {
        $this->recherches[] = $facture;
        $this->refuserLaCle();
        if ($this->listeRepondEncore !== null && $this->listeRepondEncore-- <= 0) {
            $this->listeEnPanne = true;
        }
        if ($this->listeEnPanne) {
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'api_connection_error');
        }
        $trouves = $this->listeEnRetard ? [] : array_filter($this->prelevements, fn (array $p) => $p['client'] === $client && ($p['metadonnees']['facture'] ?? null) === $facture);

        return array_values(array_map(fn (array $p) => $this->lirePrelevement($p['id']), array_reverse($trouves)));
    }

    private function refuserLaCle(): void
    {
        if ($this->cleRefusee) {
            throw new ErreurPaiement(ErreurPaiement::GENERIQUE, 'api_key_expired', 401);
        }
    }

    /** Le client confirme le paiement auprès de sa banque (Stripe.js). Un prélèvement annulé ne se confirme plus. */
    public function confirmer(string $id): void
    {
        if ($this->prelevements[$id]['statut'] !== 'annule') {
            $this->prelevements[$id]['statut'] = 'reussi';
        }
    }

    /** La banque refuse un prélèvement qu'elle traitait. */
    public function refuser(string $id): void
    {
        $this->prelevements[$id]['statut'] = 'refuse';
    }

    /** @return array<string, mixed>|null */
    public function dernierPrelevement(): ?array
    {
        return $this->prelevements === [] ? null : array_values($this->prelevements)[count($this->prelevements) - 1];
    }
}
