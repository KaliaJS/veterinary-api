<?php

namespace App\Services\Tele;

use App\Events\SignalVisio;
use App\Models\SessionTeleconsultation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mise en relation WebRTC (28.10.2026) : chaque message passe par l'API, qui
 * le diffuse sur le canal de la session (aucun message entre clients).
 */
class SignalisationVisio
{
    public const array TYPES = ['pret', 'offre', 'reponse', 'candidat', 'fin'];

    /** Au-delà, le message part par référence (Reverb sur Laravel Cloud : 10 Ko au plus) */
    private const int TAILLE_MAX = 8000;

    /** @param  array<string, mixed>  $donnees */
    public function envoyer(SessionTeleconsultation $session, string $de, string $type, array $donnees): void
    {
        abort_unless($session->estJoignable(), 410, 'Cet appel est terminé.');

        $reference = null;
        if (strlen((string) json_encode($donnees)) > self::TAILLE_MAX) {
            $reference = (string) Str::uuid();
            Cache::put('visio:signal:'.$reference, ['session' => $session->id, 'donnees' => $donnees], now()->addMinutes(10));
        }

        try {
            SignalVisio::dispatch($session->id, $de, $type, $donnees, $reference);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'La connexion en direct est indisponible : réessayez dans un instant.');
        }
    }

    /** @return array<string, mixed> */
    public function lire(SessionTeleconsultation $session, string $reference): array
    {
        $signal = Cache::get('visio:signal:'.$reference);
        abort_if(! is_array($signal) || ($signal['session'] ?? null) !== $session->id, 404, 'Message introuvable.');

        return $signal['donnees'];
    }
}
