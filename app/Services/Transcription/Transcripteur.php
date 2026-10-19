<?php

namespace App\Services\Transcription;

/**
 * Fournisseur de transcription, remplaçable (Deepgram aujourd'hui) : un
 * fichier audio entier, voix séparées. Le texte ne doit jamais finir dans
 * un journal.
 */
interface Transcripteur
{
    /**
     * @param  list<string>  $termes  mots rares à bien reconnaître (médicaments, races…)
     *
     * @throws TranscriptionImpossible refus définitif (audio illisible, compte bloqué…)
     */
    public function transcrire(string $chemin, string $format, array $termes): Transcription;
}
