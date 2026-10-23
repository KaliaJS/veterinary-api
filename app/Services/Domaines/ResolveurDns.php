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

    /**
     * Les valeurs TXT d'un nom (preuve qu'un domaine est à qui le relie).
     *
     * @return list<string>
     */
    public function txt(string $nom): array
    {
        return array_values(array_filter(array_map(
            fn (array $enregistrement): ?string => $enregistrement['txt'] ?? null,
            @dns_get_record($nom, DNS_TXT) ?: [],
        )));
    }
}
