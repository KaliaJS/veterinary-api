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

    /** Le prestataire répond par une erreur (requête refusée, panne) */
    public ?string $panne = null;

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
        if ($this->panne) {
            throw new ErreurPaiement($this->panne, 'api_error');
        }
        $id = 'pi_'.++$this->sequence;
        $statut = match (true) {
            $this->refus !== null => 'refuse',
            $this->actionRequise => 'action_requise',
            default => 'reussi',
        };
        $this->prelevements[$id] = [
            'id' => $id, 'client' => $client, 'moyen' => $moyen, 'centimes' => $centimes, 'libelle' => $libelle,
            'metadonnees' => $metadonnees, 'hors_session' => $horsSession, 'statut' => $statut,
        ];

        return $this->lirePrelevement($id);
    }

    public function lirePrelevement(string $id): array
    {
        $p = $this->prelevements[$id] ?? throw new ErreurPaiement('Élément introuvable chez Stripe.', 'resource_missing');

        return [
            'id' => $id,
            'statut' => $p['statut'],
            'client_secret' => $id.'_secret',
            'message' => $p['statut'] === 'refuse' ? ($this->refus ?? 'La carte a été refusée par la banque.') : null,
        ];
    }

    /** Le client confirme le paiement auprès de sa banque (Stripe.js). */
    public function confirmer(string $id): void
    {
        $this->prelevements[$id]['statut'] = 'reussi';
    }

    /** @return array<string, mixed>|null */
    public function dernierPrelevement(): ?array
    {
        return $this->prelevements === [] ? null : array_values($this->prelevements)[count($this->prelevements) - 1];
    }
}
