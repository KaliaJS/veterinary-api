<?php

namespace App\Paiements;

use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

/**
 * Stripe Billing : clients, cartes (SetupIntent + Payment Element côté
 * navigateur, la carte ne touche jamais nos serveurs), abonnements aux
 * formules (prix CHF repérés par lookup_key), achats ponctuels facturés.
 * TVA suisse 8,1 % en sus, par un taux Stripe partagé.
 */
class PasserelleAbonnementStripe implements PasserelleAbonnement
{
    private const MESSAGES = [
        'card_declined' => 'La carte a été refusée par la banque.',
        'insufficient_funds' => 'Fonds insuffisants sur la carte.',
        'expired_card' => 'La carte est expirée.',
        'incorrect_cvc' => 'Le code de sécurité de la carte est incorrect.',
        'processing_error' => 'Le paiement n’a pas pu être traité. Réessayez.',
        'authentication_required' => 'La banque demande une confirmation : enregistrez la carte à nouveau pour la valider.',
        'resource_missing' => 'Élément introuvable chez Stripe.',
    ];

    public function __construct(private StripeClient $client) {}

    public function creerClient(string $nom, ?string $email, array $metadonnees): string
    {
        return $this->appel(fn () => $this->client->customers->create(array_filter([
            'name' => $nom,
            'email' => $email,
            'preferred_locales' => ['fr'],
            'metadata' => $metadonnees,
        ])))->id;
    }

    public function creerIntentionCarte(string $client): array
    {
        $intention = $this->appel(fn () => $this->client->setupIntents->create([
            'customer' => $client,
            'usage' => 'off_session',
            'allowed_payment_method_types' => ['card'],
        ]));

        return ['id' => $intention->id, 'client_secret' => $intention->client_secret];
    }

    public function lireMoyenPaiement(string $moyen): array
    {
        return $this->appel(fn () => $this->client->paymentMethods->retrieve($moyen))->toArray();
    }

    public function definirCarteParDefaut(string $client, string $moyen): void
    {
        $this->appel(fn () => $this->client->customers->update($client, [
            'invoice_settings' => ['default_payment_method' => $moyen],
        ]));
    }

    public function detacherMoyenPaiement(string $moyen): void
    {
        $this->appel(fn () => $this->client->paymentMethods->detach($moyen));
    }

    public function prixFormule(string $cle, string $produit, int $centimes, string $intervalle): string
    {
        return Cache::rememberForever('stripe-prix-'.$cle.'-'.$centimes, function () use ($cle, $produit, $centimes, $intervalle): string {
            $existants = $this->appel(fn () => $this->client->prices->all(['lookup_keys' => [$cle], 'active' => true, 'limit' => 1]));
            $prix = $existants->data[0] ?? null;
            if ($prix && $prix->unit_amount === $centimes) {
                return $prix->id;
            }

            // Absent ou prix modifié : nouveau prix, la clé lui est transférée
            return $this->appel(fn () => $this->client->prices->create([
                'currency' => 'chf',
                'unit_amount' => $centimes,
                'recurring' => ['interval' => $intervalle],
                'lookup_key' => $cle,
                'transfer_lookup_key' => true,
                'product_data' => ['name' => $produit],
                'metadata' => ['veterinary' => $cle],
            ]))->id;
        });
    }

    public function tauxTva(): string
    {
        return Cache::rememberForever('stripe-tva-ch-81', function (): string {
            $taux = $this->appel(fn () => $this->client->taxRates->all(['active' => true, 'inclusive' => false, 'limit' => 100]));
            foreach ($taux->data as $t) {
                if (($t->metadata['veterinary'] ?? null) === 'tva-ch-8.1') {
                    return $t->id;
                }
            }

            return $this->appel(fn () => $this->client->taxRates->create([
                'display_name' => 'TVA',
                'description' => 'TVA suisse, taux normal',
                'percentage' => 8.1,
                'inclusive' => false,
                'country' => 'CH',
                'jurisdiction' => 'CH',
                'tax_type' => 'vat',
                'metadata' => ['veterinary' => 'tva-ch-8.1'],
            ]))->id;
        });
    }

    public function creerAbonnement(string $client, string $prix, string $tva, array $metadonnees, string $cleIdempotence): array
    {
        return $this->appel(fn () => $this->client->subscriptions->create([
            'customer' => $client,
            'items' => [['price' => $prix]],
            'default_tax_rates' => [$tva],
            // Carte refusée : rien n'est créé, l'erreur remonte
            'payment_behavior' => 'error_if_incomplete',
            'metadata' => $metadonnees,
            'expand' => ['latest_invoice'],
        ], ['idempotency_key' => $cleIdempotence]))->toArray();
    }

    public function changerAbonnement(string $abonnement, string $prix, array $metadonnees): array
    {
        $actuel = $this->appel(fn () => $this->client->subscriptions->retrieve($abonnement));

        return $this->appel(fn () => $this->client->subscriptions->update($abonnement, [
            'items' => [['id' => $actuel->items->data[0]->id, 'price' => $prix]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'error_if_incomplete',
            'cancel_at_period_end' => false,
            'metadata' => $metadonnees,
            'expand' => ['latest_invoice'],
        ]))->toArray();
    }

    public function finDePeriode(string $abonnement, bool $resilier): array
    {
        return $this->appel(fn () => $this->client->subscriptions->update($abonnement, [
            'cancel_at_period_end' => $resilier,
        ]))->toArray();
    }

    public function lireAbonnement(string $abonnement): array
    {
        return $this->appel(fn () => $this->client->subscriptions->retrieve($abonnement))->toArray();
    }

    public function facturerAchat(string $client, int $centimes, string $libelle, string $tva, array $metadonnees, string $cleIdempotence): array
    {
        $facture = $this->appel(fn () => $this->client->invoices->create([
            'customer' => $client,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'default_tax_rates' => [$tva],
            'description' => $libelle,
            'metadata' => $metadonnees,
        ], ['idempotency_key' => $cleIdempotence.'-facture']));

        $this->appel(fn () => $this->client->invoiceItems->create([
            'customer' => $client,
            'invoice' => $facture->id,
            'amount' => $centimes,
            'currency' => 'chf',
            'description' => $libelle,
        ], ['idempotency_key' => $cleIdempotence.'-ligne']));

        $this->appel(fn () => $this->client->invoices->finalizeInvoice($facture->id, ['auto_advance' => false]));

        try {
            return $this->client->invoices->pay($facture->id)->toArray();
        } catch (ApiErrorException $e) {
            // Refus : la facture finalisée est annulée, rien n'est dû
            try {
                $this->client->invoices->voidInvoice($facture->id);
            } catch (ApiErrorException) {
                // déjà réglée ou annulée : rien à faire
            }
            throw $this->erreur($e);
        }
    }

    public function lireFacture(string $facture): array
    {
        return $this->appel(fn () => $this->client->invoices->retrieve($facture))->toArray();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $appel
     * @return T
     */
    private function appel(callable $appel): mixed
    {
        try {
            return $appel();
        } catch (ApiErrorException $e) {
            throw $this->erreur($e);
        }
    }

    private function erreur(ApiErrorException $e): ErreurPaiement
    {
        $code = $e instanceof CardException ? ($e->getDeclineCode() ?: $e->getStripeCode()) : $e->getStripeCode();

        return new ErreurPaiement(self::MESSAGES[$code] ?? self::MESSAGES[$e->getStripeCode()] ?? $e->getMessage(), $e->getStripeCode());
    }
}
