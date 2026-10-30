<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * L'app iPhone se signale (en-tête X-Veterinary-Platform, 31.10.2026) :
 * on y monte et on y envoie des vidéos, mais rien ne s'y vend ni ne s'y
 * achète. L'écran le cache ; le serveur le refuse aussi.
 */
final class NativeApp
{
    public const HEADER = 'X-Veterinary-Platform';

    public static function isIos(Request $request): bool
    {
        return strtolower((string) $request->header(self::HEADER)) === 'ios';
    }

    public static function refuse(Request $request, string $message): void
    {
        if (self::isIos($request)) {
            throw ValidationException::withMessages(['plateforme' => $message]);
        }
    }
}
