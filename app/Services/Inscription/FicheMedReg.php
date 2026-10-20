<?php

namespace App\Services\Inscription;

/**
 * Un vétérinaire tel que MedReg le publie : identité, diplôme et
 * autorisations de pratiquer (cantons). Données publiques du registre.
 */
final readonly class FicheMedReg
{
    /** @param  list<string>  $cantons */
    public function __construct(
        public int $id,
        public string $gln,
        public string $prenom,
        public string $nom,
        public ?string $diplome,
        public bool $autorisation,
        public array $cantons,
    ) {}

    /** @return array{prenom: string, nom: string, diplome: ?string, autorisation: bool, cantons: list<string>} */
    public function toArray(): array
    {
        return [
            'prenom' => $this->prenom,
            'nom' => $this->nom,
            'diplome' => $this->diplome,
            'autorisation' => $this->autorisation,
            'cantons' => $this->cantons,
        ];
    }
}
