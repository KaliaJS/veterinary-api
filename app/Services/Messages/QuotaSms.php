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

        // Coupés (TWILIO_ENABLED=false) : le paquet ne les envoie pas, ils ne comptent pas comme partis
        return ($c['enabled'] ?? true) && filled($c['account_sid'] ?? null) && filled($c['username'] ?? null) && filled($c['password'] ?? null);
    }

    public static function cle(Organisation|int $organisation): string
    {
        return 'sms:'.($organisation instanceof Organisation ? $organisation->id : $organisation);
    }

    public static function utilises(Organisation|int $organisation): int
    {
        return CompteurMensuel::valeur(self::cle($organisation));
    }

    /** Le plafond du mois : la formule et les packs achetés ce mois-ci ; null sans limite. */
    public static function plafond(Organisation $organisation): ?int
    {
        $formule = $organisation->plan->limites()['sms'];

        return $formule === null ? null : $formule + self::packsDuMois($organisation);
    }

    /** Les SMS des packs achetés ce mois (« le solde non utilisé n'est pas reporté ») */
    public static function packsDuMois(Organisation|int $organisation): int
    {
        return CompteurMensuel::valeur(self::cle($organisation).':packs');
    }

    /** Un pack payé s'ajoute au mois en cours seulement. */
    public static function ajouterPack(Organisation $organisation, int $quantite): void
    {
        CompteurMensuel::ajouter(self::cle($organisation).':packs', $quantite);
    }

    /** Réserve les segments d'un SMS ; rend le mois compté, ou null si le quota ne les couvre pas. */
    public function reserver(Organisation $organisation, int $segments = 1): ?string
    {
        return DB::transaction(function () use ($organisation, $segments): ?string {
            // Un envoi à la fois par organisation : le plafond n'est jamais dépassé
            Organisation::query()->whereKey($organisation->id)->lockForUpdate()->first();
            $plafond = self::plafond($organisation);
            if ($plafond !== null && self::utilises($organisation) + $segments > $plafond) {
                return null;
            }

            return CompteurMensuel::ajouter(self::cle($organisation), $segments);
        });
    }

    public function rendre(Organisation $organisation, string $mois, int $segments = 1): void
    {
        CompteurMensuel::retirer(self::cle($organisation), $segments, $mois);
    }
}
