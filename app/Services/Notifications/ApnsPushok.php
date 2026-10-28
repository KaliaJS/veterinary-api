<?php

namespace App\Services\Notifications;

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

    public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu): array
    {
        $c = config('services.apns');
        $cle = base64_decode((string) $c['cle_base64'], true);
        if ($jetons === [] || $cle === false || $cle === '') {
            return [];
        }
        $auth = Token::create([
            'key_id' => $c['key_id'],
            'team_id' => $c['team_id'],
            'app_bundle_id' => $sujet,
            'private_key_content' => $cle,
            'private_key_secret' => null,
        ]);
        $client = new Client($auth, $environnement === 'production');
        $payload = Payload::create()->setContentAvailability(true)->setPushType('background');
        foreach ($contenu as $champ => $valeur) {
            if ($champ !== 'aps') {
                $payload->setCustomValue($champ, $valeur);
            }
        }
        $client->addNotifications(array_map(fn (string $jeton): Notification => (new Notification($payload, $jeton))->setLowPriority(), $jetons));

        $morts = [];
        foreach ($client->push() as $reponse) {
            if (in_array($reponse->getErrorReason(), self::JETON_MORT, true) || $reponse->getStatusCode() === 410) {
                $morts[] = $reponse->getDeviceToken();
            } elseif ($reponse->getStatusCode() !== 200) {
                report(new \RuntimeException('APNs : '.$reponse->getStatusCode().' '.$reponse->getErrorReason()));
            }
        }

        return $morts;
    }
}
