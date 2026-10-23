<?php

namespace App\Paiements;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe pour la plateforme : clients, cartes (SetupIntent + Payment
 * Element côté navigateur, la carte ne touche jamais nos serveurs) et
 * prélèvements (PaymentIntent confirmé aussitôt sur la carte enregistrée).
 * Pas de Stripe Billing : seuls les frais de carte s'appliquent.
 */
class PasserelleAbonnementStripe implements PasserelleAbonnement
{
    private const MESSAGES = [
        'card_declined' => 'La carte a été refusée par la banque.',
        'insufficient_funds' => 'Fonds insuffisants sur la carte.',
        'expired_card' => 'La carte est expirée.',
        'incorrect_cvc' => 'Le code de sécurité de la carte est incorrect.',
        'processing_error' => 'Le paiement n’a pas pu être traité. Réessayez.',
        'authentication_required' => 'Votre banque demande de confirmer le paiement.',
        'resource_missing' => 'Élément introuvable chez le prestataire de paiement.',
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

    public function prelever(string $client, string $moyen, int $centimes, string $libelle, array $metadonnees, bool $horsSession, string $cleIdempotence): array
    {
        try {
            $intention = $this->client->paymentIntents->create([
                'amount' => $centimes,
                'currency' => 'chf',
                'customer' => $client,
                'payment_method' => $moyen,
                // La carte enregistrée, sans redirection (3-D Secure passe par Stripe.js)
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'confirm' => true,
                'off_session' => $horsSession,
                'description' => $libelle,
                'statement_descriptor_suffix' => 'VETERINARY',
                'metadata' => $metadonnees,
            ], ['idempotency_key' => $cleIdempotence]);
        } catch (CardException $e) {
            // Refus ou confirmation exigée hors session : l'intention reste, réglable plus tard
            $intention = $e->getError()?->payment_intent;
            $code = $e->getDeclineCode() ?: ($e->getStripeCode() ?? 'card_declined');

            return [
                'id' => $intention?->id,
                'statut' => in_array('authentication_required', [$code, $e->getStripeCode()], true) ? 'action_requise' : 'refuse',
                'client_secret' => $intention?->client_secret,
                'message' => self::MESSAGES[$code] ?? self::MESSAGES['card_declined'],
            ];
        } catch (ApiErrorException $e) {
            throw $this->erreur($e);
        }

        return $this->etat($intention);
    }

    public function lirePrelevement(string $id): array
    {
        return $this->etat($this->appel(fn () => $this->client->paymentIntents->retrieve($id)));
    }

    /** @return array{id: ?string, statut: 'reussi'|'action_requise'|'refuse'|'en_cours', client_secret: ?string, message: ?string} */
    private function etat(PaymentIntent $intention): array
    {
        $statut = match ($intention->status) {
            'succeeded' => 'reussi',
            'requires_action', 'requires_confirmation' => 'action_requise',
            'processing' => 'en_cours',
            default => 'refuse',
        };
        // Clé facultative de l'objet Stripe : lue dans le tableau
        $code = $intention->last_payment_error?->toArray()['code'] ?? null;

        return [
            'id' => $intention->id,
            'statut' => $statut,
            'client_secret' => $intention->client_secret,
            'message' => $statut === 'refuse' ? (self::MESSAGES[$code ?? ''] ?? self::MESSAGES['card_declined']) : null,
        ];
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

        return ErreurPaiement::depuisStripe($e, self::MESSAGES[$code] ?? self::MESSAGES[$e->getStripeCode()] ?? null);
    }
}
