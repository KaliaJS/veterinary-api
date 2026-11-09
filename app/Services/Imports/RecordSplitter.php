<?php

namespace App\Services\Imports;

/**
 * Le découpeur d'un gros PDF reçu (09.11.2026) : à partir du texte de
 * chaque page (et de l'image des pages scannées), il propose les pièces.
 */
interface RecordSplitter
{
    /**
     * @param  list<array{n: int, text: string, image: ?string}>  $pages  `image` : JPEG en base64 d'une page scannée
     * @return list<array{from: int, to: int, title: string, kind: string, date: ?string, source: ?string}>
     *
     * @throws RecordSplitImpossible
     */
    public function split(array $pages): array;
}
