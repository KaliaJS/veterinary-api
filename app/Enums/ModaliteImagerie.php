<?php

namespace App\Enums;

/**
 * Modalités d'imagerie (MODC du front) : radio, écho, endoscopie. Les
 * trames de compte rendu (REPORT_TEMPLATES) sont un référentiel
 * applicatif porté par la modalité.
 */
enum ModaliteImagerie: string
{
    case Radiographie = 'DX';
    case Echographie = 'US';
    case Endoscopie = 'ES';

    /** @return array{constatations: string, conclusion: string} */
    public function trame(): array
    {
        return match ($this) {
            self::Radiographie => [
                'constatations' => "Qualité du cliché : \nSilhouette cardiaque : \nChamps pulmonaires : \nSquelette / tissus mous : ",
                'conclusion' => 'Conclusion : ',
            ],
            self::Echographie => [
                'constatations' => "Foie : \nRate : \nReins : \nVessie : \nTube digestif : \nGanglions : ",
                'conclusion' => 'Conclusion : ',
            ],
            self::Endoscopie => [
                'constatations' => "Œsophage : \nEstomac : \nPylore / duodénum : \nBiopsies : ",
                'conclusion' => 'Conclusion : ',
            ],
        };
    }
}
