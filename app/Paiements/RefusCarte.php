<?php

namespace App\Paiements;

/**
 * Refus d'une carte, dit en français (23.10.2026) : le message de Stripe
 * (en anglais) n'est jamais montré, seul son code est lu. Une carte
 * perdue ou volée est un simple refus : rien n'en est dit à la caisse.
 */
final class RefusCarte
{
    private const MESSAGES = [
        'insufficient_funds' => 'Fonds insuffisants sur la carte.',
        'expired_card' => 'La carte a expiré.',
        'incorrect_cvc' => 'Le code de sécurité de la carte est incorrect.',
        'invalid_cvc' => 'Le code de sécurité de la carte est incorrect.',
        'incorrect_pin' => 'Le code PIN est incorrect.',
        'invalid_pin' => 'Le code PIN est incorrect.',
        'pin_try_exceeded' => 'Trop d’essais du code PIN : la banque bloque la carte pour ce paiement.',
        'offline_pin_required' => 'La banque demande le code PIN : insérez la carte et réessayez.',
        'online_or_offline_pin_required' => 'La banque demande le code PIN : insérez la carte et réessayez.',
        'authentication_required' => 'La banque demande une confirmation : réessayez avec la carte.',
        'card_not_supported' => 'Cette carte n’est pas acceptée pour ce paiement.',
        'currency_not_supported' => 'Cette carte n’est pas acceptée pour ce paiement.',
        'processing_error' => 'La banque n’a pas pu traiter le paiement. Réessayez.',
        'card_velocity_exceeded' => 'La carte a atteint sa limite. Essayez un autre moyen de paiement.',
        'withdrawal_count_limit_exceeded' => 'La carte a atteint sa limite. Essayez un autre moyen de paiement.',
    ];

    public const GENERIQUE = 'Paiement refusé par la banque.';

    /**
     * L'erreur de Stripe (`last_payment_error`, ou les codes d'échec d'un
     * lecteur), ou rien : le message à montrer.
     *
     * @param  array<string, mixed>|null  $erreur
     */
    public static function message(?array $erreur): string
    {
        $codes = [$erreur['decline_code'] ?? null, $erreur['code'] ?? null, $erreur['failure_code'] ?? null];
        foreach ($codes as $code) {
            if (is_string($code) && isset(self::MESSAGES[$code])) {
                return self::MESSAGES[$code];
            }
        }

        return self::GENERIQUE;
    }
}
