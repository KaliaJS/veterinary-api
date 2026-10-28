<?php

namespace App\Services\Messages;

use App\Models\Organisation;
use App\Services\CompteurMensuel;
use Illuminate\Support\Facades\DB;

/**
 * Les SMS de l'organisation (28.10.2026) : chacun compte dans le mois en
 * cours (heure suisse), dans la limite de la formule et des packs achetés.
 * Réservé avant l'envoi, rendu si Twilio refuse.
 */
class QuotaSms
{
    /** Les clés Twilio sont là : les SMS peuvent partir. */
    public static function estConfigure(): bool
    {
        $c = config('twilio-notification-channel');

        return filled($c['account_sid'] ?? null) && filled($c['username'] ?? null) && filled($c['password'] ?? null);
    }

    public static function cle(Organisation|int $organisation): string
    {
        return 'sms:'.($organisation instanceof Organisation ? $organisation->id : $organisation);
    }

    public static function utilises(Organisation|int $organisation): int
    {
        return CompteurMensuel::valeur(self::cle($organisation));
    }

    /** Le plafond du mois : la formule et les packs ; null sans limite. */
    public static function plafond(Organisation $organisation): ?int
    {
        $formule = $organisation->plan->limites()['sms'];

        return $formule === null ? null : $formule + (int) $organisation->quota_extra_sms;
    }

    /** Réserve un SMS ; rend le mois compté, ou null si le quota est atteint. */
    public function reserver(Organisation $organisation): ?string
    {
        return DB::transaction(function () use ($organisation): ?string {
            // Un envoi à la fois par organisation : le plafond n'est jamais dépassé
            Organisation::query()->whereKey($organisation->id)->lockForUpdate()->first();
            $plafond = self::plafond($organisation);
            if ($plafond !== null && self::utilises($organisation) >= $plafond) {
                return null;
            }

            return CompteurMensuel::ajouter(self::cle($organisation), 1);
        });
    }

    public function rendre(Organisation $organisation, string $mois): void
    {
        CompteurMensuel::retirer(self::cle($organisation), 1, $mois);
    }
}
