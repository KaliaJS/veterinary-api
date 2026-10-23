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
 * Sur le compte d'une société (`pourCompte`), chaque appel porte l'en-tête
 * Stripe-Account : paiements directs, la commission en frais d'application.
 */
class PasserelleStripe implements PasserellePaiement
{
    /** Messages lisibles des refus du lecteur (codes Stripe). */
    private const MESSAGES = [
        'terminal_reader_offline' => 'Le terminal est hors ligne. Vérifiez qu’il est allumé et connecté.',
        'terminal_reader_busy' => 'Le terminal est déjà occupé par un autre paiement.',
        'terminal_reader_timeout' => 'Le terminal n’a pas répondu à temps. Réessayez.',
        'resource_missing' => 'Ce terminal n’existe plus. Retirez-le et appairez-le à nouveau.',
        'payment_method_unactivated' => 'Ce moyen de paiement n’est pas encore activé sur le compte de paiement.',
    ];

    /** Composants intégrés permis (nom de l'API) et leurs réglages. */
    private const COMPOSANTS = [
        'account_onboarding' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
        'account_management' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
        'notification_banner' => ['enabled' => true, 'features' => ['external_account_collection' => true]],
        'payments' => ['enabled' => true, 'features' => ['refund_management' => true, 'dispute_management' => true, 'capture_payments' => false]],
        'payouts' => ['enabled' => true, 'features' => ['instant_payouts' => false, 'standard_payouts' => true, 'edit_payout_schedule' => true]],
        'balances' => ['enabled' => true, 'features' => ['instant_payouts' => false, 'standard_payouts' => true, 'edit_payout_schedule' => true]],
    ];

    private ?string $compte = null;

    public function __construct(
        private StripeClient $client,
        private ?string $secretWebhook,
        private ?string $secretWebhookComptes = null,
    ) {}

    public function pourCompte(?string $compte): static
    {
        $copie = clone $this;
        $copie->compte = $compte;

        return $copie;
    }

    public function creerIntentionTerminal(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->create([
            'amount' => $centimes,
            'currency' => 'chf',
            // Depuis l'API 2026 : les types autorisés remplacent `payment_method_types`
            'allowed_payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'metadata' => $metadonnees,
            ...$this->commission($commission),
        ], $this->options(['idempotency_key' => $cleIdempotence])));

        return ['id' => $intention->id, 'statut' => $intention->status];
    }

    public function creerIntentionTwint(int $centimes, array $metadonnees, string $urlRetour, string $cleIdempotence, int $commission = 0): array
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->create([
            'amount' => $centimes,
            'currency' => 'chf',
            'allowed_payment_method_types' => ['twint'],
            'payment_method_data' => ['type' => 'twint'],
            'confirm' => true,
            'return_url' => $urlRetour,
            'metadata' => $metadonnees,
            ...$this->commission($commission),
        ], $this->options(['idempotency_key' => $cleIdempotence])));

        return [
            'id' => $intention->id,
            'statut' => $intention->status,
            'url' => $intention->next_action?->redirect_to_url?->url,
        ];
    }

    public function creerIntentionEnLigne(int $centimes, array $metadonnees, string $cleIdempotence, int $commission = 0): array
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->create([
            'amount' => $centimes,
            'currency' => 'chf',
            // Les moyens activés sur le compte (carte, TWINT…), redirection permise
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadonnees,
            ...$this->commission($commission),
        ], $this->options(['idempotency_key' => $cleIdempotence])));

        return ['id' => $intention->id, 'statut' => $intention->status, 'client_secret' => $intention->client_secret];
    }

    public function rembourser(string $intentionId, string $cleIdempotence, ?int $centimes = null): void
    {
        $this->appel(fn () => $this->client->refunds->create(
            // La commission de la plateforme n'est jamais rendue (décision du 23.10.2026)
            [...array_filter(['payment_intent' => $intentionId, 'amount' => $centimes]), 'refund_application_fee' => false],
            $this->options(['idempotency_key' => $cleIdempotence]),
        ));
    }

    public function fraisIntention(string $intentionId): ?int
    {
        $intention = $this->appel(fn () => $this->client->paymentIntents->retrieve($intentionId, ['expand' => ['latest_charge.balance_transaction']], $this->options()));
        $transaction = $intention->latest_charge->balance_transaction ?? null;

        return is_object($transaction) ? (int) $transaction->fee : null;
    }

    public function presenterSurLecteur(string $lecteurId, string $intentionId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->processPaymentIntent($lecteurId, [
            'payment_intent' => $intentionId,
            'process_config' => ['enable_customer_cancellation' => true],
        ], $this->options()));
    }

    public function annulerSurLecteur(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->cancelAction($lecteurId, null, $this->options()));
    }

    public function lireIntention(string $intentionId): array
    {
        return $this->intention($this->appel(fn () => $this->client->paymentIntents->retrieve($intentionId, null, $this->options())));
    }

    public function annulerIntention(string $intentionId): array
    {
        return $this->intention($this->appel(fn () => $this->client->paymentIntents->cancel($intentionId, null, $this->options())));
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
        ], $this->options()));

        return $location->id;
    }

    public function appairerLecteur(string $codeAppairage, string $libelle, string $locationId): array
    {
        return $this->lecteur($this->appel(fn () => $this->client->terminal->readers->create([
            'registration_code' => $codeAppairage,
            'label' => $libelle,
            'location' => $locationId,
        ], $this->options())));
    }

    public function lireLecteur(string $lecteurId): array
    {
        return $this->lecteur($this->appel(fn () => $this->client->terminal->readers->retrieve($lecteurId, null, $this->options())));
    }

    public function supprimerLecteur(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->terminal->readers->delete($lecteurId, null, $this->options()));
    }

    public function simulerCarte(string $lecteurId): void
    {
        $this->appel(fn () => $this->client->testHelpers->terminal->readers->presentPaymentMethod($lecteurId, null, $this->options()));
    }

    public function lireEvenement(string $corps, string $signature): array
    {
        // Deux points d'arrivée : la plateforme, et les comptes des sociétés (Connect)
        $evenement = null;
        foreach (array_filter([$this->secretWebhook, $this->secretWebhookComptes]) as $secret) {
            try {
                $evenement = Webhook::constructEvent($corps, $signature, (string) $secret);
                break;
            } catch (SignatureVerificationException|UnexpectedValueException) {
                continue;
            }
        }
        if (! $evenement) {
            throw new ErreurPaiement('Signature de webhook invalide.');
        }

        return [
            'id' => $evenement->id,
            'type' => $evenement->type,
            'objet' => $evenement->data->object->toArray(),
            'compte' => $evenement->account ?? null,
        ];
    }

    public function creerComptePaiement(array $societe): string
    {
        $adresse = $societe['adresse'] ?? null;
        $compte = $this->appel(fn () => $this->client->v2->core->accounts->create([
            // Le nom commercial : celui que les clients voient sur leur relevé et dans TWINT
            'display_name' => $societe['nom_commercial'] ?? $societe['nom'],
            'contact_email' => $societe['email'],
            // Aucun tableau de bord Stripe : tout se fait dans Veterinary (composants intégrés)
            'dashboard' => 'none',
            'identity' => [
                'country' => 'ch',
                'entity_type' => 'company',
                // Prérempli pour raccourcir l'inscription : la société reste libre de corriger
                'business_details' => array_filter([
                    'registered_name' => $societe['nom'],
                    'id_numbers' => ! empty($societe['ide']) ? [['type' => 'ch_uid', 'value' => $societe['ide']]] : null,
                    'address' => $adresse ? array_filter(['country' => 'ch', 'line1' => $adresse['rue'], 'postal_code' => $adresse['npa'], 'city' => $adresse['ville']]) : null,
                    'phone' => $societe['telephone'] ?? null,
                ]),
            ],
            'configuration' => [
                'merchant' => [
                    // Services vétérinaires
                    'mcc' => '0742',
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'twint_payments' => ['requested' => true],
                    ],
                ],
            ],
            'defaults' => [
                'currency' => 'chf',
                'locales' => ['fr'],
                'profile' => ['doing_business_as' => $societe['nom_commercial'] ?? $societe['nom']],
                // Le prestataire collecte ses frais auprès de la société et porte les pertes
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'metadata' => $societe['metadonnees'],
        ]));

        return $compte->id;
    }

    public function lireComptePaiement(string $compte): array
    {
        $lu = $this->appel(fn () => $this->client->accounts->retrieve($compte));
        $exigences = $lu->requirements;

        return [
            'paiements' => (bool) $lu->charges_enabled,
            'virements' => (bool) $lu->payouts_enabled,
            'a_completer' => ! empty($exigences?->currently_due) || ! empty($exigences?->past_due) || ($exigences?->disabled_reason ?? null) !== null,
        ];
    }

    public function creerSessionCompte(string $compte, array $composants, bool $remboursements = false): string
    {
        $choisis = array_intersect_key(self::COMPOSANTS, array_flip($composants));
        if (isset($choisis['payments'])) {
            $choisis['payments']['features']['refund_management'] = $remboursements;
            $choisis['payments']['features']['dispute_management'] = $remboursements;
        }

        $session = $this->appel(fn () => $this->client->accountSessions->create([
            'account' => $compte,
            'components' => $choisis,
        ]));

        return $session->client_secret;
    }

    /** @return array<string, string> */
    private function options(array $options = []): array
    {
        return $this->compte ? [...$options, 'stripe_account' => $this->compte] : $options;
    }

    /** La commission de la plateforme, sur le compte d'une société seulement. */
    private function commission(int $centimes): array
    {
        return $this->compte && $centimes > 0 ? ['application_fee_amount' => $centimes] : [];
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
            throw ErreurPaiement::depuisStripe($e, self::MESSAGES[$e->getStripeCode()] ?? null);
        }
    }
}
