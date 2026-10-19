<?php

namespace App\Services\Transcription;

/**
 * Résultat d'une transcription : durée de l'audio (facturée) et segments
 * dans l'ordre, chacun avec sa voix (numérotée par le fournisseur, à
 * partir de 0), son début et sa fin en secondes.
 */
final readonly class Transcription
{
    /** @param  list<array{voix: int, debut: float, fin: float, texte: string}>  $segments */
    public function __construct(
        public float $duree,
        public array $segments,
        public ?string $requete = null,
    ) {}
}
