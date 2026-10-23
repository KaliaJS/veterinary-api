<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Jeton de choix de clinique (23.10.2026) : après une connexion réussie
 * d'un compte rattaché à plusieurs cliniques, le secret (code, défi
 * passkey) est consommé ; ce jeton court, à usage unique et lié au
 * compte, porte le choix jusqu'à l'émission du jeton d'API.
 */
class ChoixClinique
{
    private const int VALIDITE_MINUTES = 5;

    public static function ouvrir(User $user, string $appareil): string
    {
        $choix = Str::random(48);
        Cache::put(self::cle($choix), ['user_id' => $user->id, 'appareil' => $appareil], now()->addMinutes(self::VALIDITE_MINUTES));

        return $choix;
    }

    /** @return array{user_id: int, appareil: string}|null  Consommé à la lecture */
    public static function prendre(string $choix): ?array
    {
        return Cache::pull(self::cle($choix));
    }

    private static function cle(string $choix): string
    {
        return 'auth:choix-clinique:'.hash('sha256', $choix);
    }
}
