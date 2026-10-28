<?php

namespace App\Services\Tele;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Serveurs ICE de la téléconsultation (28.10.2026) : le STUN de Cloudflare,
 * et son TURN avec des identifiants temporaires créés ici (la clé ne quitte
 * jamais l'API). Sans clé, ou Cloudflare injoignable : STUN seulement, l'appel
 * passe tant que les deux appareils se joignent directement.
 */
class ServeursIce
{
    public const string STUN = 'stun:stun.cloudflare.com:3478';

    /** @return list<array<string, mixed>> */
    public function pour(): array
    {
        $cle = config('services.cloudflare_turn.key_id');
        $jeton = config('services.cloudflare_turn.api_token');
        if (blank($cle) || blank($jeton)) {
            return [['urls' => [self::STUN]]];
        }

        try {
            $reponse = Http::withToken((string) $jeton)->timeout(5)
                ->post('https://rtc.live.cloudflare.com/v1/turn/keys/'.$cle.'/credentials/generate-ice-servers', [
                    'ttl' => min(4 * 3600, max(600, (int) config('services.cloudflare_turn.ttl', 3600))),
                ])
                ->throw()
                ->json();
            $serveurs = $reponse['iceServers'] ?? null;
            if (is_array($serveurs) && $serveurs !== []) {
                // Une liste ou un seul objet, selon la forme rendue
                return array_is_list($serveurs) ? $serveurs : [$serveurs];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [['urls' => [self::STUN]]];
    }
}
