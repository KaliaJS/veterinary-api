<?php

namespace App\Services\Domaines;

/**
 * Lecture DNS isolée (remplacée par un faux dans les tests) : le domaine
 * pointe-t-il vers nous, par CNAME ou par adresse IP ?
 */
class ResolveurDns
{
    public function pointeVers(string $domaine, string $cibleCname, ?string $ip): bool
    {
        $cname = @dns_get_record($domaine, DNS_CNAME) ?: [];

        foreach ($cname as $enregistrement) {
            if (rtrim(strtolower($enregistrement['target'] ?? ''), '.') === strtolower($cibleCname)) {
                return true;
            }
        }

        if ($ip) {
            foreach (@dns_get_record($domaine, DNS_A) ?: [] as $enregistrement) {
                if (($enregistrement['ip'] ?? null) === $ip) {
                    return true;
                }
            }
        }

        return false;
    }
}
