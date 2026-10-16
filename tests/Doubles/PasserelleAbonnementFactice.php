<?php

namespace Tests\Doubles;

use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;

/**
 * Double de Stripe Billing : clients, cartes, abonnements et factures en
 * mémoire, sous la forme des objets Stripe (tableaux) ; refus de carte
 * programmable (`refus`).
 */
class PasserelleAbonnementFactice implements PasserelleAbonnement
{
    /** @var array<string, array<string, mixed>> */
    public array $clients = [];

    /** @var array<string, array<string, mixed>> */
    public array $cartes = [];

    /** @var array<string, array<string, mixed>> */
    public array $abonnements = [];

    /** @var array<string, array<string, mixed>> */
    public array $factures = [];

    /** @var array<string, int> prix créés : id → centimes */
    public array $prix = [];

    /** @var list<string> */
    public array $detachees = [];

    public ?string $refus = null;

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

    public function prixFormule(string $cle, string $produit, int $centimes, string $intervalle): string
    {
        $this->prix[$cle] = $centimes;

        return 'price_'.$cle;
    }

    public function tauxTva(): string
    {
        return 'txr_tva';
    }

    public function creerAbonnement(string $client, string $prix, string $tva, array $metadonnees, string $cleIdempotence): array
    {
        $this->refuser();
        $id = 'sub_'.++$this->sequence;
        $this->abonnements[$id] = [
            'id' => $id, 'customer' => $client, 'status' => 'active', 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null,
            'metadata' => $metadonnees, 'prix' => $prix,
            'items' => ['data' => [['id' => 'si_'.$id, 'current_period_end' => now()->addMonth()->startOfDay()->timestamp]]],
        ];
        $this->abonnements[$id]['latest_invoice'] = $this->facture($client, $this->prix[substr($prix, 6)] ?? 0, 'subscription_create', [], ['metadata' => $metadonnees]);

        return $this->abonnements[$id];
    }

    public function changerAbonnement(string $abonnement, string $prix, array $metadonnees): array
    {
        $this->refuser();
        $a = &$this->abonnements[$abonnement];
        $a['prix'] = $prix;
        $a['metadata'] = $metadonnees;
        $a['cancel_at_period_end'] = false;
        $a['latest_invoice'] = $this->facture($a['customer'], 1234, 'subscription_update', [], ['metadata' => $metadonnees]);

        return $a;
    }

    public function finDePeriode(string $abonnement, bool $resilier): array
    {
        $this->abonnements[$abonnement]['cancel_at_period_end'] = $resilier;

        return $this->abonnements[$abonnement];
    }

    public function lireAbonnement(string $abonnement): array
    {
        return $this->abonnements[$abonnement];
    }

    public function facturerAchat(string $client, int $centimes, string $libelle, string $tva, array $metadonnees, string $cleIdempotence): array
    {
        $this->refuser();

        return $this->facture($client, $centimes, 'manual', $metadonnees, null, $libelle);
    }

    public function lireFacture(string $facture): array
    {
        return $this->factures[$facture];
    }

    private function refuser(): void
    {
        if ($this->refus) {
            throw new ErreurPaiement($this->refus, 'card_declined');
        }
    }

    /**
     * @param  array<string, string>  $metadonnees
     * @param  array<string, mixed>|null  $abonnement
     * @return array<string, mixed>
     */
    private function facture(string $client, int $htCentimes, string $raison, array $metadonnees, ?array $abonnement = null, ?string $description = null): array
    {
        $id = 'in_'.++$this->sequence;
        $tva = (int) round($htCentimes * 0.081);

        return $this->factures[$id] = [
            'id' => $id, 'customer' => $client, 'number' => 'VET-'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'status' => 'paid', 'billing_reason' => $raison, 'created' => now()->timestamp,
            'total' => $htCentimes + $tva, 'total_excluding_tax' => $htCentimes,
            'invoice_pdf' => 'https://pay.stripe.com/invoice/'.$id.'/pdf', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/'.$id,
            'description' => $description, 'metadata' => $metadonnees,
            'parent' => $abonnement ? ['subscription_details' => $abonnement] : null,
        ];
    }
}
