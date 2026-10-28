<?php

namespace App\Support;

/** Numéros de téléphone : format international, et mobile (seul à recevoir un SMS). */
class Telephone
{
    /** Numéro au format international (+41…), null s'il n'est pas lisible. */
    public static function international(?string $numero): ?string
    {
        $chiffres = preg_replace('/[^\d+]/', '', (string) $numero);
        if (str_starts_with($chiffres, '00')) {
            $chiffres = '+'.substr($chiffres, 2);
        } elseif (str_starts_with($chiffres, '0')) {
            $chiffres = '+41'.substr($chiffres, 1);
        }

        return preg_match('/^\+\d{8,15}$/', $chiffres) ? $chiffres : null;
    }

    /** Le numéro s'il reçoit des SMS : mobile suisse (07x), ou étranger (Twilio dira). */
    public static function mobile(?string $numero): ?string
    {
        $international = self::international($numero);
        if ($international === null) {
            return null;
        }

        return ! str_starts_with($international, '+41') || preg_match('/^\+417[5-9]\d{7}$/', $international) ? $international : null;
    }
}
