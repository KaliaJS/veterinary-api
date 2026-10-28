<?php

namespace App\Services\Messages;

use App\Services\Paiements\ComptesPaiement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS par Twilio (décision de Jim, 28.10.2026) : sous le nom « Veterinary »
 * (expéditeur alphanumérique, sans numéro, permis en Suisse sans
 * enregistrement). Le client ne peut pas y répondre : le texte donne le
 * téléphone de la clinique. Appel direct de l'API REST de Twilio par le
 * client HTTP de Laravel, avec une clé d'API (révocable) plutôt que le
 * jeton maître du compte.
 */
class EnvoiSms
{
    public function estConfigure(): bool
    {
        $c = config('services.twilio');

        return filled($c['account_sid'] ?? null) && filled($c['api_key'] ?? null) && filled($c['api_secret'] ?? null);
    }

    /** Numéro au format international s'il reçoit des SMS : mobile suisse (07x), ou étranger (Twilio dira). */
    public static function mobile(?string $numero): ?string
    {
        $international = ComptesPaiement::telephone($numero);
        if ($international === null) {
            return null;
        }

        return ! str_starts_with($international, '+41') || preg_match('/^\+417[5-9]\d{7}$/', $international) ? $international : null;
    }

    /** @return string l'identifiant du message chez Twilio */
    public function envoyer(string $mobile, string $texte): string
    {
        if (! $this->estConfigure()) {
            throw new SmsRefuse('Les SMS ne sont pas configurés (clé Twilio absente).');
        }
        $c = config('services.twilio');

        try {
            $reponse = Http::asForm()
                ->withBasicAuth($c['api_key'], $c['api_secret'])
                ->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", [
                    'To' => $mobile,
                    'From' => $c['expediteur'],
                    'Body' => $texte,
                ]);
        } catch (ConnectionException $e) {
            throw new SmsRefuse('Twilio ne répond pas : '.$e->getMessage(), 0, $e);
        }

        if ($reponse->failed()) {
            throw new SmsRefuse((string) ($reponse->json('message') ?? 'SMS refusé par Twilio ('.$reponse->status().').'), (int) $reponse->json('code'));
        }

        return (string) $reponse->json('sid');
    }
}
