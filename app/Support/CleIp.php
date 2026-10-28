<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Clé d'une adresse IP pour les limites (28.10.2026) : l'adresse IPv4
 * telle quelle (même écrite en IPv6) ; en IPv6, le préfixe /64, qu'un seul
 * abonné reçoit en entier (des milliards d'adresses : une limite par
 * adresse ne tiendrait pas).
 */
final class CleIp
{
    public static function de(Request $request): string
    {
        $ip = (string) $request->ip();
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        // Une adresse IPv4 écrite en IPv6 (::ffff:a.b.c.d) reste une IPv4
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $v4) === 1) {
            return $v4[1];
        }

        $octets = inet_pton($ip);

        return $octets === false ? $ip : inet_ntop(substr($octets, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
