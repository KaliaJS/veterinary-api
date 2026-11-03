<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\PreferencesProprietaire;
use App\Models\User;
use App\Services\Rappels\RappelsProprietaires;

/**
 * Les rappels et préférences d'un propriétaire, tels qu'ils valent
 * (décision de Jim, 03.11.2026) : avec un compte Veterinary, ses choix
 * dans son espace font foi et la clinique les voit sans les modifier ;
 * sans compte, la clinique règle sur la fiche.
 */
final class OwnerPreferences
{
    /** Les types de rappels qu'on peut couper */
    public const array KINDS = ['vaccines', 'appointments', 'checkups', 'quotes'];

    /** Libellés pour « À venir » et les messages */
    public const array LABELS = ['vaccines' => 'vaccins', 'appointments' => 'rendez-vous', 'checkups' => 'contrôles', 'quotes' => 'devis'];

    /** Le type d'un rappel envoyé (objet de RappelsProprietaires) */
    public static function kindOf(string $objet): ?string
    {
        return match ($objet) {
            'vaccin', 'vaccin_retard' => 'vaccines',
            'rdv', 'noshow' => 'appointments',
            'controle' => 'checkups',
            'devis' => 'quotes',
            default => null,
        };
    }

    /** @return list<string> */
    public static function mutedFor(Client $client): array
    {
        $muted = $client->user_id !== null
            ? PreferencesProprietaire::query()->where('user_id', $client->user_id)->value('muted_reminders')
            : $client->muted_reminders;
        if (is_string($muted)) {
            $muted = json_decode($muted, true);
        }

        return array_values(array_intersect(self::KINDS, (array) ($muted ?? [])));
    }

    /** Ce rappel est-il coupé pour ce propriétaire ? */
    public static function isMuted(Client $client, string $objet): bool
    {
        $kind = self::kindOf($objet);

        return $kind !== null && in_array($kind, self::mutedFor($client), true);
    }

    /**
     * Ce que montre la fenêtre propriétaire (onglet « Préférences »).
     *
     * @return array<string, mixed>
     */
    public static function forClient(Client $client): array
    {
        if ($client->user_id !== null) {
            $prefs = PreferencesProprietaire::query()->where('user_id', $client->user_id)->first();

            return [
                'source' => 'compte',
                'est_modifiable' => false,
                // Depuis quand il a son espace Veterinary
                'espace_depuis' => User::query()->whereKey($client->user_id)->value('created_at')?->toIso8601String(),
                'email' => ($prefs->rappels_email ?? true) && $client->rappels_email !== false,
                'sms' => $prefs !== null ? (RappelsProprietaires::choixSms($prefs) ?? (bool) $client->rappels_sms) : (bool) $client->rappels_sms,
                'muted_reminders' => self::mutedFor($client),
                'language' => $prefs->langue ?? 'fr',
                'accepts_news' => (bool) ($prefs->nouveautes_boutique ?? false),
                'photo_consent_at' => $client->photo_consent_at?->toIso8601String(),
            ];
        }

        return [
            'source' => 'fiche',
            'est_modifiable' => true,
            'espace_depuis' => null,
            'email' => $client->rappels_email !== false,
            'sms' => (bool) $client->rappels_sms,
            'muted_reminders' => self::mutedFor($client),
            'language' => $client->language ?? 'fr',
            'accepts_news' => (bool) $client->accepts_news,
            'photo_consent_at' => $client->photo_consent_at?->toIso8601String(),
        ];
    }
}
