<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Un compteur du mois en cours (heure suisse), en base (24.10.2026) : les
 * budgets des fournisseurs y comptent ce qui ne laisse pas d'autre trace.
 */
final class CompteurMensuel
{
    /** Ajoute `$n` au compteur du mois ; rend le mois compté, pour retirer au même plus tard. */
    public static function ajouter(string $cle, int $n): string
    {
        $mois = self::mois();
        DB::table('compteurs_mensuels')->insertOrIgnore(['cle' => $cle, 'mois' => $mois, 'valeur' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('compteurs_mensuels')->where('cle', $cle)->where('mois', $mois)->increment('valeur', $n, ['updated_at' => now()]);

        return $mois;
    }

    public static function retirer(string $cle, int $n, string $mois): void
    {
        DB::table('compteurs_mensuels')->where('cle', $cle)->where('mois', $mois)->decrement('valeur', $n, ['updated_at' => now()]);
    }

    public static function valeur(string $cle): int
    {
        return max(0, (int) DB::table('compteurs_mensuels')->where('cle', $cle)->where('mois', self::mois())->value('valeur'));
    }

    private static function mois(): string
    {
        return CarbonImmutable::now('Europe/Zurich')->format('Y-m');
    }
}
