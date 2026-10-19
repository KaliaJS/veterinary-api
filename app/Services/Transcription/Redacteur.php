<?php

namespace App\Services\Transcription;

/**
 * Rédacteur du brouillon SOAP, remplaçable (Claude aujourd'hui) : il ne
 * reprend que ce qui est dit dans la transcription, une rubrique sans
 * information reste vide. Le vétérinaire relit et valide toujours.
 */
interface Redacteur
{
    /**
     * @param  list<array{partie: int, voix: int, debut: float, texte: string}>  $segments
     * @param  array<string, string>  $contexte  dossier (nom, espèce, motif…), pour comprendre, jamais recopié
     *
     * @throws RedactionImpossible refus définitif
     */
    public function rediger(array $segments, array $contexte): BrouillonSoap;
}
