<?php

namespace App\Paiements\AppStore;

/**
 * Vérifie une donnée signée par l'App Store (JWS, StoreKit 2) : transaction,
 * renouvellement, notification serveur. Tout se vérifie sur place, sans
 * appel à Apple :
 *
 * - en-tête ES256 et chaîne x5c (feuille, intermédiaire, racine) ;
 * - racine identique à une racine de confiance (Apple Root CA G3, fichier
 *   de resources/apple), chaque certificat signé par le suivant ;
 * - marqueurs d'Apple sur l'intermédiaire (1.2.840.113635.100.6.2.1) et
 *   la feuille (1.2.840.113635.100.6.11.1) ;
 * - certificats valides à la date de signature (et non aujourd'hui : un
 *   achat ancien reste vérifiable) ;
 * - signature de la feuille sur l'en-tête et le contenu.
 *
 * En local et sur option (`services.apple.accepte_xcode`), les achats de
 * test d'Xcode passent aussi : signés par un certificat local, ils n'ont
 * ni la racine ni les marqueurs d'Apple ; leur chaîne et leur signature
 * restent vérifiées.
 */
class VerificateurJws
{
    private const OID_INTERMEDIAIRE = '1.2.840.113635.100.6.2.1';

    private const OID_FEUILLE = '1.2.840.113635.100.6.11.1';

    /** @return array<string, mixed> contenu décodé */
    public function verifier(string $jws): array
    {
        $parties = explode('.', trim($jws));
        if (count($parties) !== 3) {
            throw new JwsInvalide('Format JWS inattendu.');
        }
        [$enteteB64, $contenuB64, $signatureB64] = $parties;
        $entete = json_decode($this->b64url($enteteB64), true);
        $contenu = json_decode($this->b64url($contenuB64), true);
        if (! is_array($entete) || ! is_array($contenu)) {
            throw new JwsInvalide('JWS illisible.');
        }
        if (($entete['alg'] ?? null) !== 'ES256' || ! is_array($entete['x5c'] ?? null) || $entete['x5c'] === []) {
            throw new JwsInvalide('En-tête JWS inattendu.');
        }

        $chaine = array_map(fn ($der): string => $this->pem((string) $der), $entete['x5c']);
        $this->verifierChaine($chaine, $this->dateSignature($contenu));

        $signature = $this->signatureDer($this->b64url($signatureB64));
        $cle = openssl_pkey_get_public($chaine[0]);
        if ($cle === false || openssl_verify($enteteB64.'.'.$contenuB64, $signature, $cle, OPENSSL_ALGO_SHA256) !== 1) {
            throw new JwsInvalide('Signature JWS invalide.');
        }

        return $contenu;
    }

    /** @param list<string> $chaine certificats PEM, de la feuille à la racine */
    private function verifierChaine(array $chaine, int $date): void
    {
        $racine = end($chaine);
        $empreinte = openssl_x509_fingerprint($racine, 'sha256');
        $racinesApple = array_map(fn (string $chemin): string|false => is_file($chemin) ? openssl_x509_fingerprint((string) file_get_contents($chemin), 'sha256') : false, (array) config('services.apple.racines', []));

        $estApple = $empreinte !== false && in_array($empreinte, $racinesApple, true);
        $estXcode = ! $estApple && (bool) config('services.apple.accepte_xcode');
        if (! $estApple && ! $estXcode) {
            throw new JwsInvalide('Racine de certificats inconnue.');
        }
        if ($estApple && count($chaine) !== 3) {
            throw new JwsInvalide('Chaîne de certificats incomplète.');
        }

        foreach ($chaine as $i => $certificat) {
            $infos = openssl_x509_parse($certificat);
            if ($infos === false || $date < ($infos['validFrom_time_t'] ?? PHP_INT_MAX) || $date > ($infos['validTo_time_t'] ?? 0)) {
                throw new JwsInvalide('Certificat hors de sa période de validité.');
            }
            $signataire = $chaine[$i + 1] ?? $certificat;
            $cle = openssl_pkey_get_public($signataire);
            if ($cle === false || openssl_x509_verify($certificat, $cle) !== 1) {
                throw new JwsInvalide('Chaîne de certificats rompue.');
            }
        }

        if ($estApple && (! $this->aExtension($chaine[1], self::OID_INTERMEDIAIRE) || ! $this->aExtension($chaine[0], self::OID_FEUILLE))) {
            throw new JwsInvalide('Certificats sans les marqueurs de l’App Store.');
        }
    }

    private function aExtension(string $certificat, string $oid): bool
    {
        $infos = openssl_x509_parse($certificat, false);

        return is_array($infos) && array_key_exists($oid, $infos['extensions'] ?? []);
    }

    /** Date de signature du contenu (secondes) ; à défaut, maintenant. */
    private function dateSignature(array $contenu): int
    {
        $ms = $contenu['signedDate'] ?? null;

        return is_numeric($ms) ? intdiv((int) $ms, 1000) : time();
    }

    private function pem(string $der64): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($der64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private function b64url(string $valeur): string
    {
        $brut = base64_decode(strtr($valeur, '-_', '+/').str_repeat('=', (4 - strlen($valeur) % 4) % 4), true);
        if ($brut === false) {
            throw new JwsInvalide('Base64 invalide.');
        }

        return $brut;
    }

    /** Signature ES256 brute (R‖S, 64 octets) en DER pour OpenSSL. */
    private function signatureDer(string $brute): string
    {
        if (strlen($brute) !== 64) {
            throw new JwsInvalide('Signature ES256 inattendue.');
        }
        $entier = function (string $octets): string {
            $octets = ltrim($octets, "\x00");
            if ($octets === '' || ord($octets[0]) > 0x7F) {
                $octets = "\x00".$octets;
            }

            return "\x02".chr(strlen($octets)).$octets;
        };
        $corps = $entier(substr($brute, 0, 32)).$entier(substr($brute, 32));

        return "\x30".chr(strlen($corps)).$corps;
    }
}
