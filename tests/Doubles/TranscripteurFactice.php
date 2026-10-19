<?php

namespace Tests\Doubles;

use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\Transcription;
use App\Services\Transcription\TranscriptionImpossible;

/**
 * Double du fournisseur de transcription : garde ce qu'il a reçu (contenu
 * du fichier assemblé, format, mots rares) et rend des segments fixés ;
 * refus programmable (`refus`).
 */
class TranscripteurFactice implements Transcripteur
{
    /** @var list<array{contenu: string, format: string, termes: list<string>}> */
    public array $appels = [];

    public ?TranscriptionImpossible $refus = null;

    public float $duree = 95.2;

    /** @var list<array{voix: int, debut: float, fin: float, texte: string}> */
    public array $segments = [
        ['voix' => 0, 'debut' => 0.5, 'fin' => 4.0, 'texte' => 'Il boite depuis trois jours, surtout le matin.'],
        ['voix' => 1, 'debut' => 12.2, 'fin' => 18.0, 'texte' => 'Chaleur au genou droit, pas de douleur à la flexion.'],
    ];

    public function transcrire(string $chemin, string $format, array $termes): Transcription
    {
        $this->appels[] = ['contenu' => (string) file_get_contents($chemin), 'format' => $format, 'termes' => $termes];
        if ($this->refus) {
            throw $this->refus;
        }

        return new Transcription($this->duree, $this->segments, 'req_'.count($this->appels));
    }
}
