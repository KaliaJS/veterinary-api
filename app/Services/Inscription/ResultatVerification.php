<?php

namespace App\Services\Inscription;

/**
 * Issue de la vérification d'un vétérinaire : vérifié (raison null), ou à
 * vérifier à la main avec la raison — introuvable, nom_different,
 * gln_deja_utilise, medreg_indisponible. La fiche MedReg, si elle existe.
 */
final readonly class ResultatVerification
{
    public const array RAISONS = [
        'introuvable' => 'Ce GLN n’est pas celui d’un vétérinaire dans MedReg.',
        'nom_different' => 'MedReg donne un autre nom pour ce GLN.',
        'gln_deja_utilise' => 'Ce GLN est déjà celui d’un autre compte.',
        'medreg_indisponible' => 'MedReg n’a pas répondu.',
    ];

    public function __construct(
        public ?string $raison,
        public ?FicheMedReg $fiche = null,
    ) {}

    public function estVerifie(): bool
    {
        return $this->raison === null;
    }

    public function explication(): ?string
    {
        return $this->raison === null ? null : self::RAISONS[$this->raison];
    }
}
