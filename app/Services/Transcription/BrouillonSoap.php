<?php

namespace App\Services\Transcription;

/**
 * Brouillon de compte rendu : les quatre rubriques (codes du compte rendu,
 * `s` `o` `a` `p`, vides si rien n'a été dit) et les doutes du rédacteur.
 * Un doute porte sur un passage exact d'une rubrique (souligné à l'écran,
 * le vétérinaire accepte la proposition ou remet ce qui a été entendu), ou
 * sur toute la rubrique quand `passage` est vide (une note).
 */
final readonly class BrouillonSoap
{
    /** @param  list<array{rubrique: string, passage: string, entendu: string, raison: string}>  $doutes */
    public function __construct(
        public string $s,
        public string $o,
        public string $a,
        public string $p,
        public array $doutes = [],
    ) {}

    /**
     * Garde les doutes utilisables : rubrique connue, raison donnée, et un
     * passage qui figure vraiment dans sa rubrique (sinon le doute devient
     * une note de la rubrique).
     *
     * @param  array<int, mixed>  $doutes
     */
    public static function avecDoutes(string $s, string $o, string $a, string $p, array $doutes): self
    {
        $rubriques = ['s' => $s, 'o' => $o, 'a' => $a, 'p' => $p];
        $retenus = [];
        foreach ($doutes as $doute) {
            $rubrique = (string) ($doute['rubrique'] ?? '');
            $raison = trim((string) ($doute['raison'] ?? ''));
            if (! isset($rubriques[$rubrique]) || $raison === '') {
                continue;
            }
            $passage = trim((string) ($doute['passage'] ?? ''));
            $retenus[] = [
                'rubrique' => $rubrique,
                'passage' => $passage !== '' && str_contains($rubriques[$rubrique], $passage) ? $passage : '',
                'entendu' => trim((string) ($doute['entendu'] ?? '')),
                'raison' => $raison,
            ];
        }

        return new self($s, $o, $a, $p, $retenus);
    }

    /** @return array{s: string, o: string, a: string, p: string, doutes: list<array{rubrique: string, passage: string, entendu: string, raison: string}>} */
    public function toArray(): array
    {
        return ['s' => $this->s, 'o' => $this->o, 'a' => $this->a, 'p' => $this->p, 'doutes' => $this->doutes];
    }
}
