<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Pushok\ApnsResponseInterface;
use Pushok\AuthProvider\Token;
use Pushok\Client;
use Pushok\Notification;
use Pushok\Payload;

/**
 * Apple (APNs) par edamov/pushok : jeton signé avec la clé .p8 de Jim
 * (APNS_PRIVATE_KEY_BASE64, jamais affichée), une connexion par
 * environnement (sandbox pour Xcode, production pour TestFlight et l'App
 * Store). Notification invisible (« background », priorité 5).
 */
class ApnsPushok implements CanalApns
{
    /** Refus définitifs d'Apple : le jeton ne sert plus */
    private const array JETON_MORT = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    /** Le jeton signé refusé par Apple : il est refait au prochain envoi */
    private const array SIGNATURE_REFUSEE = ['ExpiredProviderToken', 'InvalidProviderToken'];

    /**
     * Le jeton signé, gardé 40 minutes : Apple refuse qu'on le change plus
     * d'une fois toutes les 20 minutes (TooManyProviderTokenUpdates) et
     * l'accepte une heure. Clé illisible : signalée au journal, sans elle.
     */
    public function autorisation(string $sujet): ?Token
    {
        $c = config('services.apns');
        $cle = base64_decode((string) $c['cle_base64'], true);
        if ($cle === false || ! str_contains($cle, 'PRIVATE KEY')) {
            report(new \RuntimeException('APNs : clé illisible, APNS_PRIVATE_KEY_BASE64 doit être le fichier .p8 encodé en base64.'));

            return null;
        }
        $options = [
            'key_id' => $c['key_id'],
            'team_id' => $c['team_id'],
            'app_bundle_id' => $sujet,
            'private_key_content' => $cle,
            'private_key_secret' => null,
        ];
        $signe = Cache::remember($this->cleCache($sujet), now()->addMinutes(40), fn (): string => Token::create($options)->get());

        return Token::useExisting($signe, $options);
    }

    private function cleCache(string $sujet): string
    {
        return 'apns:signature:'.config('services.apns.key_id').':'.$sujet;
    }

    public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu): array
    {
        if ($jetons === []) {
            return [];
        }
        $auth = $this->autorisation($sujet);
        if ($auth === null) {
            return [];
        }
        $client = new Client($auth, $environnement === 'production');
        $payload = Payload::create()->setContentAvailability(true)->setPushType('background');
        foreach ($contenu as $champ => $valeur) {
            if ($champ !== 'aps') {
                $payload->setCustomValue($champ, $valeur);
            }
        }
        $client->addNotifications(array_map(fn (string $jeton): Notification => (new Notification($payload, $jeton))->setLowPriority(), $jetons));

        return $this->lireReponses($client->push(), $sujet);
    }

    /**
     * Les réponses d'Apple : les jetons morts (à oublier) ; une signature
     * refusée est oubliée, refaite au prochain envoi ; le reste au journal.
     *
     * @param  iterable<ApnsResponseInterface>  $reponses
     * @return list<string>
     */
    public function lireReponses(iterable $reponses, string $sujet): array
    {
        $morts = [];
        foreach ($reponses as $reponse) {
            if (in_array($reponse->getErrorReason(), self::JETON_MORT, true) || $reponse->getStatusCode() === 410) {
                $morts[] = $reponse->getDeviceToken();
            } elseif (in_array($reponse->getErrorReason(), self::SIGNATURE_REFUSEE, true)) {
                Cache::forget($this->cleCache($sujet));
                report(new \RuntimeException('APNs : signature refusée ('.$reponse->getErrorReason().'), refaite au prochain envoi.'));
            } elseif ($reponse->getStatusCode() !== 200) {
                report(new \RuntimeException('APNs : '.$reponse->getStatusCode().' '.$reponse->getErrorReason()));
            }
        }

        return $morts;
    }
}
