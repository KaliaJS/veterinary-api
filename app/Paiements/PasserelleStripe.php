<?php

namespace App\Paiements;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Terminal\Reader;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Passerelle Stripe : Terminal piloté par le serveur (lecteurs smart,
 * pas de SDK côté navigateur) et TWINT par redirection. Devise : CHF.
 */
class PasserelleStripe implements PasserellePaiement
{
    /** Messages lisibles des refus du lecteur (codes Stripe). */
    private const MESSAGES = [
        'terminal_reader_offline' => 'Le terminal est hors ligne. Vérifiez qu’il est allumé et connecté.',
        'terminal_reader_busy' => 'Le terminal est déjà occupé par un autre paiement.',
        'terminal_reader_timeout' => 'Le terminal n’a pas répondu à temps. Réessayez.',
        'resource_missing' => 'Ce terminal n’existe plus chez Stripe. Retirez-le et appairez-le à nouveau.',
        'payment_method_unactivated' => 'Ce moyen de paiement n’est pas activé sur le compte Stripe.',
    ];

    public function __construct(
        private StripeClient $client,
        private ?string $secretWebhook,
    ) {}

    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence): array
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->create([
            'amount' => $centimes,
            'currency' => 'chf',
            // Depuis l'API 2026 : les types autorisés remplacent `payment_method_types`
            'allowed_payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'metadata' => $metadonnees,
        ], ['idempotency_key' => $cleIdempotence]));

        return ['id' => $intention->id, 'statut' => $intention->status];
    }

    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence): array
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->create([
            'amount' => $centimes,
            'currency' => 'chf',
            'allowed_payment_method_types' => ['twint'],
            'payment_method_data' => ['type' => 'twint'],
            'confirm' => true,
            'return_url' => $urlRetour,
            'metadata' => $metadonnees,
        ], ['idempotency_key' => $cleIdempotence]));

        return [
            'id' => $intention->id,
            'statut' => $intention->status,
            'url' => $intention->next_action?->redirect_to_url?->url,
        ];
    }

    public function presenterSurLecteur(string $lecteurId, string $intentionId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->processPaymentIntent($lecteurId, [
            'payment_intent' => $intentionId,
            'process_config' => ['enable_customer_cancellation' => true],
        ]));
    }

    public function annulerSurLecteur(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->cancelAction($lecteurId));
    }

    public function lireIntention(string $intentionId): array
    {
        return $this->intention($this->appel(fn () => $this->client->paymentIntents->retrieve($intentionId)));
    }

    public function annulerIntention(string $intentionId): array
    {
        return $this->intention($this->appel(fn () => $this->client->paymentIntents->cancel($intentionId)));
    }

    public function creerLocation(string $nom, array $adresse, array $metadonnees): string
    {
        $location = $this->appel(fn () => $this->client->terminal->locations->create([
            'display_name' => $nom,
            'address' => [
                'line1' => $adresse['rue'] ?: $nom,
                'postal_code' => $adresse['npa'] ?: null,
                'city' => $adresse['ville'] ?: null,
                'country' => 'CH',
            ],
            'metadata' => $metadonnees,
        ]));

        return $location->id;
    }

    public function appairerLecteur(string $codeAppairage, string $libelle, string $locationId): array
    {
        return $this->lecteur($this->appel(fn () => $this->client->terminal->readers->create([
            'registration_code' => $codeAppairage,
            'label' => $libelle,
            'location' => $locationId,
        ])));
    }

    public function lireLecteur(string $lecteurId): array
    {
        return $this->lecteur($this->appel(fn () => $this->client->terminal->readers->retrieve($lecteurId)));
    }

    public function supprimerLecteur(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->delete($lecteurId));
    }

    public function simulerCarte(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->testHelpers->terminal->readers->presentPaymentMethod($lecteurId));
    }

    public function lireEvenement(string $corps, string $signature): array
    {
        try {
            $evenement = Webhook::constructEvent($corps, $signature, (string) $this->secretWebhook);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new ErreurPaiement('Signature de webhook invalide.');
        }

        return [
            'id' => $evenement->id,
            'type' => $evenement->type,
            'objet' => $evenement->data->object->toArray(),
        ];
    }

    /** @return array{id: string, statut: string, erreur: ?string} */
    private function intention(PaymentIntent $intention): array
    {
        return [
            'id' => $intention->id,
            'statut' => $intention->status,
            'erreur' => $intention->last_payment_error?->message,
        ];
    }

    /** @return array{id: string, libelle: string, modele: ?string, numero_serie: ?string, en_ligne: bool} */
    private function lecteur(Reader $lecteur): array
    {
        return [
            'id' => $lecteur->id,
            'libelle' => (string) $lecteur->label,
            'modele' => $lecteur->device_type,
            'numero_serie' => $lecteur->serial_number,
            'en_ligne' => $lecteur->status === 'online',
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
            $code = $e->getStripeCode();

            throw new ErreurPaiement(self::MESSAGES[$code] ?? $e->getMessage(), $code);
        }
    }
}
