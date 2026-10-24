<?php

namespace Tests\Doubles;

use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\Transcription;
use App\Services\Transcription\TranscriptionImpossible;

/**
 * Double du fournisseur de transcription : garde ce qu'il a reçu (contenu
 * du fichier assemblé, format, mots rares) et rend des segments fixés ;
 * refus programmable (`refus`), geste pendant l'appel (`pendant`).
 */
class TranscripteurFactice implements Transcripteur
{
    /** En-tête d'un fichier MP4 (`ftyp`) : le premier morceau d'un test commence par lui */
    public const string ENTETE = "\x00\x00\x00\x18ftypM4A ";

    /** @var list<array{contenu: string, format: string, termes: list<string>}> */
    public array $appels = [];

    public ?TranscriptionImpossible $refus = null;

    public float $duree = 95.2;

    /** Joué pendant l'appel, comme un geste qui arrive pendant que le fournisseur travaille */
    public ?\Closure $pendant = null;

    /** @var list<array{voix: int, debut: float, fin: float, texte: string}> */
    public array $segments = [
        ['voix' => 0, 'debut' => 0.5, 'fin' => 4.0, 'texte' => 'Il boite depuis trois jours, surtout le matin.'],
        ['voix' => 1, 'debut' => 12.2, 'fin' => 18.0, 'texte' => 'Chaleur au genou droit, pas de douleur à la flexion.'],
    ];

    public function transcrire(string $chemin, string $format, array $termes): Transcription
    {
        // L'en-tête audio des tests retiré : le contenu se lit tel qu'envoyé
        $contenu = (string) file_get_contents($chemin);
        $this->appels[] = ['contenu' => str_starts_with($contenu, self::ENTETE) ? substr($contenu, strlen(self::ENTETE)) : $contenu, 'format' => $format, 'termes' => $termes];
        if ($this->refus) {
            throw $this->refus;
        }
        if ($this->pendant) {
            ($this->pendant)();
        }

        return new Transcription($this->duree, $this->segments, 'req_'.count($this->appels));
    }
}
