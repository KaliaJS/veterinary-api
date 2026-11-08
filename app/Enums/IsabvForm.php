<?php

namespace App\Enums;

/**
 * Les formulaires SI ABV que le logiciel du cabinet peut envoyer
 * (directives techniques de l'OSAV, ch. 2.7 à 2.11) : un traitement oral
 * d'un groupe plus grand que celui de l'OMédV (OG, OGG) ne passe que par
 * l'application web de l'OSAV ; un groupe traité par une autre voie se
 * déclare en traitement individuel (EN) avec le nombre d'animaux (ch. 2.7).
 */
enum IsabvForm: string
{
    /** Traitement individuel, animal de compagnie ou équidé */
    case CompanionTreatment = 'EH';
    /** Traitement individuel, animal de rente */
    case LivestockTreatment = 'EN';
    /** Remise à titre de stocks, animaux de rente */
    case StockSupply = 'AV';
    /** Animaux de zoo et de laboratoire */
    case ZooLab = 'FZL';

    /** Le formulaire d'une espèce ou catégorie d'utilisation de l'OSAV (1 : compagnie, 2 : rente, 5 : zoo et labo) */
    public static function forSpecies(string $speciesCode): ?self
    {
        return match (strtok($speciesCode, '.')) {
            '1' => self::CompanionTreatment,
            '2' => self::LivestockTreatment,
            '5' => self::ZooLab,
            default => null,
        };
    }

    public function isLivestock(): bool
    {
        return $this === self::LivestockTreatment || $this === self::StockSupply;
    }

    /** Le namespace de son XSD (resources/isabv/xsd) */
    public function namespace(): string
    {
        return 'http://xml.admin.ch/Health/VeterinaryPublicHealth/AntibioticsConsumption/Prescription/'.$this->value.'/1';
    }

    /**
     * Le XSD de l'OSAV ; pour AV, la copie corrigée (resources/isabv/README.md) :
     * le schéma publié exige les trois champs du dosage plusieurs fois, contre
     * son commentaire et les directives (« deux des trois »).
     */
    public function xsdPath(): string
    {
        return $this === self::StockSupply
            ? resource_path('isabv/xsd-corrige/isabv_psw_av.xsd')
            : resource_path('isabv/xsd/isabv_psw_'.strtolower($this->value).'.xsd');
    }

    /** La fréquence d'administration se lit dans les codes de ce formulaire */
    public function frequencyCategory(): string
    {
        return $this === self::LivestockTreatment ? 'EN' : 'EH';
    }

    public function label(): string
    {
        return match ($this) {
            self::CompanionTreatment => 'Traitement individuel, animal de compagnie',
            self::LivestockTreatment => 'Traitement individuel, animal de rente',
            self::StockSupply => 'Remise à titre de stocks',
            self::ZooLab => 'Animaux de zoo et de laboratoire',
        };
    }
}
