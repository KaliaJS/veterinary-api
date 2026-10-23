<?php

namespace App\Services\Captcha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Captcha Cloudflare Turnstile (23.10.2026) : un robot ne déclenche pas
 * d'e-mail depuis le formulaire public (demande de rendez-vous sans
 * compte). Le jeton du navigateur est vérifié auprès de Cloudflare, une
 * seule fois. Sans clé secrète configurée, rien ne passe.
 */
class Turnstile
{
    public function verifier(?string $jeton, ?string $ip): bool
    {
        $secret = (string) config('services.turnstile.secret');
        if ($jeton === null || $jeton === '' || $secret === '') {
            return false;
        }

        try {
            $reponse = Http::asForm()->timeout(8)->post((string) config('services.turnstile.url'), [
                'secret' => $secret,
                'response' => $jeton,
                'remoteip' => $ip,
            ]);
        } catch (ConnectionException $erreur) {
            report($erreur);

            return false;
        }

        return $reponse->successful() && $reponse->json('success') === true;
    }
}
