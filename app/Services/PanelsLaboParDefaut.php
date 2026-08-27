<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\PanelLabo;

/**
 * Référentiel de panels d'analyses créé à la naissance d'une
 * organisation — un point de départ modifiable, comme le catalogue de
 * prestations. Données de parité front (LAB_PANELS) : panels, tubes,
 * paramètres, unités et bornes de référence.
 */
class PanelsLaboParDefaut
{
    /** @var array<string, array{string, string, list<array{string, string, float, float}>}> code => [nom, tube, [[paramètre, unité, borne basse, borne haute], …]] */
    public const array PANELS = [
        'nfs' => ['NFS · Hémogramme', 'EDTA', [
            ['Hématocrite', '%', 37, 55],
            ['Hémoglobine', 'g/dl', 12, 18],
            ['Érythrocytes', 'T/l', 5.5, 8.5],
            ['Leucocytes', 'G/l', 6, 17],
            ['Neutrophiles', 'G/l', 3, 11.5],
            ['Lymphocytes', 'G/l', 1, 4.8],
            ['Plaquettes', 'G/l', 200, 500],
        ]],
        'bioch' => ['Biochimie complète', 'SEC', [
            ['Urée', 'mmol/l', 2.5, 9.6],
            ['Créatinine', 'µmol/l', 44, 159],
            ['ALAT', 'U/l', 10, 125],
            ['PAL', 'U/l', 23, 212],
            ['Protéines totales', 'g/l', 52, 82],
            ['Albumine', 'g/l', 23, 40],
            ['Glucose', 'mmol/l', 4.1, 7.9],
        ]],
        'iono' => ['Ionogramme', 'HÉPARINE', [
            ['Sodium', 'mmol/l', 144, 160],
            ['Potassium', 'mmol/l', 3.5, 5.8],
            ['Chlore', 'mmol/l', 109, 122],
            ['Calcium total', 'mmol/l', 1.98, 3],
        ]],
        't4' => ['T4 totale + TSH', 'SEC', [
            ['T4 totale', 'nmol/l', 15, 50],
            ['TSH', 'ng/ml', 0.03, 0.5],
        ]],
        'urine' => ['Analyse urinaire', 'URINE', [
            ['Densité urinaire', '', 1015, 1045],
            ['pH', '', 5.5, 7.5],
            ['Protéines', 'g/l', 0, 0.3],
            ['Glucose', 'mmol/l', 0, 0.1],
        ]],
        'coag' => ['Coagulation (PT / aPTT)', 'CITRATE', [
            ['Temps de prothrombine', 's', 6, 11],
            ['aPTT', 's', 10, 17],
            ['Fibrinogène', 'g/l', 1.5, 4],
        ]],
    ];

    public static function creer(Organisation $organisation): void
    {
        foreach (self::PANELS as $code => [$nom, $tube, $parametres]) {
            // Hors scope global : la création du référentiel ne dépend
            // jamais du contexte clinique de la requête courante.
            $panel = PanelLabo::withoutGlobalScope('organisation')->firstOrCreate(
                ['organisation_id' => $organisation->id, 'code' => $code],
                ['nom' => $nom, 'tube' => $tube],
            );

            foreach ($parametres as $position => [$parametre, $unite, $borneBasse, $borneHaute]) {
                $panel->parametres()->firstOrCreate(
                    ['nom' => $parametre],
                    [
                        'position' => $position,
                        'unite' => $unite === '' ? null : $unite,
                        'borne_basse' => $borneBasse,
                        'borne_haute' => $borneHaute,
                    ],
                );
            }
        }
    }
}
