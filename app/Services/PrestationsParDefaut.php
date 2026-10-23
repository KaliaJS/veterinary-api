<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\Prestation;

/**
 * Catalogue d'actes créé à la naissance d'une organisation — un point
 * de départ modifiable. Fusion des deux catalogues du front (décision
 * ouverte de docs/data-model.md tranchée) : les onze actes de la caisse
 * (ACTES) font foi sur les prix, complétés des quatre actes propres à
 * la fin de consultation (FC_CATALOG). Les huit actes liés à un type de
 * RDV portent le code du type. Les tarifs de séjour d'hospitalisation
 * (forfaits journaliers par état clinique, perfusion, soin) vivent ici
 * aussi : deux catalogues divergents ont déjà produit deux prix pour
 * les mêmes actes, on ne recrée pas le cas avec des constantes PHP.
 */
class PrestationsParDefaut
{
    /** @var array<string, array{string, float}> code => [nom, prix TTC CHF] */
    public const array CATALOGUE = [
        'consult' => ['Consultation standard', 85],
        'suivi' => ['Consultation de suivi', 55],
        'urgence' => ['Consultation d’urgence', 140],
        'vaccin' => ['Vaccination : acte', 48],
        'ctrl' => ['Contrôle post-opératoire', 45],
        'chir' => ['Chirurgie : forfait bloc', 420],
        'imagerie' => ['Radiographie : 2 clichés', 120],
        'tele' => ['Téléconsultation', 55],
        'euth' => ['Accompagnement fin de vie', 180],
        'labo' => ['Analyses laboratoire : profil standard', 96],
        'detart' => ['Détartrage + polissage', 260],
        'inj_sc' => ['Injection sous-cutanée', 25],
        'nfs' => ['Analyse sanguine : NFS', 88],
        'radio1' => ['Radiographie : 1 cliché', 95],
        'griffes' => ['Coupe de griffes', 18],
        'hosp_stable' => ['Hospitalisation : journée, surveillance simple', 65],
        'hosp_surv' => ['Hospitalisation : journée, surveillance rapprochée', 95],
        'hosp_critique' => ['Hospitalisation : journée, soins intensifs', 140],
        'hosp_perfusion' => ['Perfusion continue : journée d\'hospitalisation', 38],
        'hosp_soin' => ['Soin infirmier : hospitalisation', 15],
    ];

    public static function creer(Organisation $organisation): void
    {
        foreach (self::CATALOGUE as $code => [$nom, $prix]) {
            // Hors scope global : la création du catalogue ne dépend
            // jamais du contexte clinique de la requête courante.
            Prestation::withoutGlobalScope('organisation')->firstOrCreate(
                ['organisation_id' => $organisation->id, 'code' => $code],
                ['nom' => $nom, 'prix' => $prix],
            );
        }
    }
}
