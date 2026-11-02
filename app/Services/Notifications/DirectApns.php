<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Apple (APNs) en direct, sans paquet (choix de Jim, 02.11.2026), comme le
 * décrit la documentation d'Apple « Sending notification requests to
 * APNs » : HTTP/2 vers api.push.apple.com (api.sandbox.push.apple.com
 * pour les builds de Xcode), un jeton signé ES256 avec la clé .p8 de Jim
 * (APNS_PRIVATE_KEY_BASE64, jamais affichée). Sert la montre (réveil
 * invisible) et l'iPhone (notifications affichées).
 */
class DirectApns implements CanalApns
{
    /** Refus définitifs d'Apple : le jeton ne sert plus */
    private const array JETON_MORT = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    /** Le jeton signé refusé par Apple : il est refait au prochain envoi */
    private const array SIGNATURE_REFUSEE = ['ExpiredProviderToken', 'InvalidProviderToken'];

    public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu, bool $visible = false): array
    {
        if ($jetons === []) {
            return [];
        }
        $signature = $this->autorisation($sujet);
        if ($signature === null) {
            return [];
        }
        $hote = $environnement === 'production' ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        $entetes = [
            'authorization' => 'bearer '.$signature,
            'apns-topic' => $sujet,
            'apns-push-type' => $visible ? 'alert' : 'background',
            // Affichée : tout de suite ; invisible : Apple la groupe pour la batterie
            'apns-priority' => $visible ? '10' : '5',
        ];
        $corps = json_encode($contenu === [] ? ['aps' => new \stdClass] : $contenu, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $reponses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $jeton) => $pool->as($jeton)
                ->withOptions(['version' => 2.0])
                ->withHeaders($entetes)
                ->withBody($corps, 'application/json')
                ->timeout(15)
                ->post($hote.'/3/device/'.$jeton),
            $jetons,
        ));

        return $this->lireReponses($reponses, $sujet);
    }

    /**
     * Le jeton signé, gardé 40 minutes : Apple refuse qu'on le change plus
     * d'une fois toutes les 20 minutes (TooManyProviderTokenUpdates) et
     * l'accepte une heure. Clé illisible : signalée au journal, sans elle.
     */
    public function autorisation(string $sujet): ?string
    {
        $c = config('services.apns');
        $cle = base64_decode((string) $c['cle_base64'], true);
        if ($cle === false || ! str_contains($cle, 'PRIVATE KEY') || ($privee = openssl_pkey_get_private($cle)) === false) {
            report(new RuntimeException('APNs : clé illisible, APNS_PRIVATE_KEY_BASE64 doit être le fichier .p8 encodé en base64.'));

            return null;
        }

        return Cache::remember($this->cleCache($sujet), now()->addMinutes(40), function () use ($c, $privee): string {
            $entete = $this->base64Url((string) json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
            $revendications = $this->base64Url((string) json_encode(['iss' => $c['team_id'], 'iat' => now()->getTimestamp()]));
            $signe = $entete.'.'.$revendications;
            if (! openssl_sign($signe, $der, $privee, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('APNs : la signature du jeton a échoué.');
            }

            return $signe.'.'.$this->base64Url($this->derVersBrut($der));
        });
    }

    /**
     * Les réponses d'Apple : les jetons morts (à oublier) ; une signature
     * refusée est oubliée, refaite au prochain envoi ; le reste au journal.
     *
     * @param  array<string, Response|\Throwable>  $reponses  par jeton
     * @return list<string>
     */
    public function lireReponses(array $reponses, string $sujet): array
    {
        $morts = [];
        foreach ($reponses as $jeton => $reponse) {
            if (! $reponse instanceof Response) {
                report(new RuntimeException('APNs : Apple injoignable ('.class_basename($reponse).').'));

                continue;
            }
            $raison = (string) ($reponse->json('reason') ?? '');
            if ($reponse->status() === 410 || in_array($raison, self::JETON_MORT, true)) {
                $morts[] = (string) $jeton;
            } elseif (in_array($raison, self::SIGNATURE_REFUSEE, true)) {
                Cache::forget($this->cleCache($sujet));
                report(new RuntimeException('APNs : signature refusée ('.$raison.'), refaite au prochain envoi.'));
            } elseif ($reponse->status() !== 200) {
                report(new RuntimeException('APNs : '.$reponse->status().' '.$raison));
            }
        }

        return $morts;
    }

    private function cleCache(string $sujet): string
    {
        return 'apns:jwt:'.config('services.apns.key_id').':'.$sujet;
    }

    private function base64Url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    /** La signature ECDSA d'OpenSSL (DER) en R‖S de 32 octets chacun, comme le veut JWS (ES256) */
    private function derVersBrut(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7F;
        }
        $parties = [];
        for ($i = 0; $i < 2; $i++) {
            $longueur = ord($der[$pos + 1]);
            $valeur = substr($der, $pos + 2, $longueur);
            $parties[] = str_pad(ltrim($valeur, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $longueur;
        }

        return $parties[0].$parties[1];
    }
}
