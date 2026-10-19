<?php

namespace App\Services\Transcription;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Deepgram, fichier pré-enregistré (POST /v1/listen) : modèle médical
 * `nova-3-medical` en `multi` (le français, et une autre langue si on en
 * change en cours de route), voix séparées (`diarize_model`), découpage en
 * phrases (`utterances`), mise en forme des nombres. `mip_opt_out` :
 * Deepgram ne garde ni l'audio ni le texte pour entraîner ses modèles.
 * Une erreur temporaire (réseau, 429, 5xx) remonte pour être réessayée.
 */
final class TranscripteurDeepgram implements Transcripteur
{
    public function transcrire(string $chemin, string $format, array $termes): Transcription
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new TranscriptionImpossible('Fichier audio illisible.');
        }

        $reponse = Http::withHeaders(['Authorization' => 'Token '.config('services.deepgram.key')])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(900)
            ->withBody(Utils::streamFor($flux), $format)
            ->post($this->url($termes));

        if ($reponse->status() === 429 || $reponse->serverError()) {
            throw new RuntimeException('Deepgram indisponible (HTTP '.$reponse->status().').');
        }
        if (! $reponse->successful()) {
            throw new TranscriptionImpossible('Deepgram refuse la transcription (HTTP '.$reponse->status().') : '.$this->message($reponse->json()));
        }

        $segments = [];
        foreach ($reponse->json('results.utterances') ?? [] as $phrase) {
            $texte = trim((string) ($phrase['transcript'] ?? ''));
            if ($texte !== '') {
                $segments[] = [
                    'voix' => (int) ($phrase['speaker'] ?? 0),
                    'debut' => round((float) ($phrase['start'] ?? 0), 2),
                    'fin' => round((float) ($phrase['end'] ?? 0), 2),
                    'texte' => $texte,
                ];
            }
        }

        return new Transcription(
            (float) ($reponse->json('metadata.duration') ?? 0),
            $segments,
            $reponse->json('metadata.request_id'),
        );
    }

    /**
     * Les mots rares se répètent (`keyterm=a&keyterm=b`), espaces encodés :
     * un format différent n'échoue pas, il ne sert simplement à rien.
     *
     * @param  list<string>  $termes
     */
    private function url(array $termes): string
    {
        $parametres = [
            'model='.rawurlencode((string) config('services.deepgram.modele')),
            'language='.rawurlencode((string) config('services.deepgram.langue')),
            'diarize_model=latest',
            'utterances=true',
            'smart_format=true',
            'punctuate=true',
            'mip_opt_out=true',
            ...array_map(fn (string $terme) => 'keyterm='.rawurlencode($terme), $termes),
        ];

        return rtrim((string) config('services.deepgram.url'), '/').'/v1/listen?'.implode('&', $parametres);
    }

    /** @param  array<string, mixed>|null  $json */
    private function message(?array $json): string
    {
        return (string) ($json['err_msg'] ?? $json['message'] ?? $json['error'] ?? 'réponse inattendue');
    }
}
