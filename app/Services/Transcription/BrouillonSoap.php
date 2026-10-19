<?php

namespace App\Services\Transcription;

/**
 * Brouillon de compte rendu : les quatre rubriques (codes du compte rendu,
 * `s` `o` `a` `p`, vides si rien n'a été dit) et les doutes de la
 * transcription à vérifier par le vétérinaire.
 */
final readonly class BrouillonSoap
{
    /** @param  list<string>  $aVerifier */
    public function __construct(
        public string $s,
        public string $o,
        public string $a,
        public string $p,
        public array $aVerifier = [],
    ) {}

    /** @return array{s: string, o: string, a: string, p: string, a_verifier: list<string>} */
    public function toArray(): array
    {
        return ['s' => $this->s, 'o' => $this->o, 'a' => $this->a, 'p' => $this->p, 'a_verifier' => $this->aVerifier];
    }
}
