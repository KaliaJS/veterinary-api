<?php

namespace App\Support;

use App\Enums\CategorieProduit;
use App\Models\Produit;

/**
 * La convention Médvét et les remises à titre de stocks (OMédV, état au
 * 25.11.2025 ; 09.11.2026) : les catégories d'exploitation de l'annexe 1
 * ch. 2 et leur nombre minimal de visites par an, les usages d'un stock et
 * leurs durées maximales (art. 11 al. 2), les antimicrobiens qui n'y vont
 * jamais (art. 11 al. 2 let. a et b, annexe 5). Rien d'inventé : le texte
 * de l'ordonnance, ses catégories et ses chiffres.
 */
final class Medvet
{
    /** Annexe 1 ch. 2 : la catégorie de l'exploitation, ses visites minimales par an, et les espèces qu'elle concerne (null : toutes) */
    public const array CATEGORIES = [
        'porcelets' => ['label' => 'Porcs, élevage de porcelets (200 places ou plus)', 'visits' => 2, 'species' => ['PO']],
        'truies' => ['label' => 'Porcs, truies (50 places ou plus)', 'visits' => 2, 'species' => ['PO']],
        'porcs_engraissement' => ['label' => 'Porcs d’engraissement (100 places ou plus), sans prophylaxie antibiotique à la mise en place', 'visits' => 2, 'species' => ['PO']],
        'porcs_engraissement_prophylaxie' => ['label' => 'Porcs d’engraissement (100 places ou plus), avec prophylaxie antibiotique à la mise en place', 'visits' => 4, 'species' => ['PO']],
        'veaux_engraissement' => ['label' => 'Veaux d’engraissement (25 places ou plus), sans prophylaxie antibiotique à la mise en place', 'visits' => 2, 'species' => ['BV']],
        'veaux_engraissement_prophylaxie' => ['label' => 'Veaux d’engraissement (25 places ou plus), avec prophylaxie antibiotique à la mise en place', 'visits' => 4, 'species' => ['BV']],
        'autres_bovins_porcs' => ['label' => 'Autres bovins et porcs', 'visits' => 1, 'species' => ['BV', 'PO']],
        'volailles' => ['label' => 'Volailles (poulets de chair, poules pondeuses)', 'visits' => 1, 'species' => ['VO']],
        'petits_ruminants' => ['label' => 'Petits ruminants', 'visits' => 1, 'species' => ['OV', 'CP']],
        'animaux_sauvages' => ['label' => 'Animaux sauvages', 'visits' => 1, 'species' => null],
        'poissons' => ['label' => 'Poissons', 'visits' => 1, 'species' => null],
        'lapins' => ['label' => 'Lapins', 'visits' => 1, 'species' => ['LP']],
        'autres' => ['label' => 'Autres', 'visits' => 1, 'species' => null],
    ];

    /**
     * Les principes actifs des familles de l'annexe 5, par leur nom (DCI, en
     * latin ou en français) : ce qui vérifie un article sans code ATC ou une
     * association (QJ01RA). Une fluoroquinolone se reconnaît à « floxacin ».
     */
    private const array ANNEX5_SUBSTANCES = [
        'floxacin', 'flumequin', 'acide oxolinique', 'oxolinic',
        'erythromycin', 'erythromycine', 'tylosin', 'tilmicosin', 'tulathromycin', 'gamithromycin', 'tildipirosin', 'tylvalosin',
        'spiramycin', 'azithromycin', 'clarithromycin', 'roxithromycin',
        'ceftiofur', 'cefquinom', 'cefovecin', 'cefoperazon', 'ceftriaxon', 'cefotaxim', 'cefpodoxim', 'ceftazidim', 'cefepim',
    ];

    /** Art. 11 al. 2 : l'usage d'une remise à titre de stocks et sa durée maximale, en mois */
    public const array STOCK_PURPOSES = [
        'prophylaxie' => ['label' => 'Prophylaxie', 'months' => 4],
        'traitement' => ['label' => 'Traitement de cas', 'months' => 3],
        'anesthesie' => ['label' => 'Anesthésie pour l’écornage ou la castration précoce', 'months' => 3],
        'antiparasitaire' => ['label' => 'Lutte antiparasitaire', 'months' => 12],
    ];

    /**
     * Un antimicrobien (art. 11 al. 2 let. a) : par son code ATC
     * (antibactériens systémiques, intramammaires, intra-utérins,
     * intestinaux), ou s'il est une préparation antibiotique du SI ABV.
     */
    public static function isAntimicrobial(Produit $produit): bool
    {
        $atc = self::atc($produit);
        // Antibactériens systémiques, intramammaires, intra-utérins, gynécologiques, intestinaux, cutanés, ophtalmiques, auriculaires
        foreach (['J01', 'J51', 'G51AA', 'G01A', 'A07A', 'D06A', 'S01A', 'S02A', 'S03A'] as $prefixe) {
            if ($atc !== '' && str_starts_with($atc, $prefixe)) {
                return true;
            }
        }

        return $produit->categorie === CategorieProduit::Medicament && $produit->isabvPreparation() !== null;
    }

    /**
     * Annexe 5 : céphalosporine de troisième et de quatrième génération,
     * macrolide, fluoroquinolone, par leur code ATC (systémique ou
     * intramammaire).
     */
    public static function isAnnex5(Produit $produit): bool
    {
        $atc = self::atc($produit);
        foreach (['J01DD', 'J01DE', 'J01FA', 'J01MA', 'J51DD', 'J51DE', 'J51FA', 'J51MA'] as $prefixe) {
            if ($atc !== '' && str_starts_with($atc, $prefixe)) {
                return true;
            }
        }
        // Sans code ATC, ou une association : ses principes actifs et son nom
        $textes = mb_strtolower(implode(' ', [$produit->nom, ...$produit->catalogueSubstances()]));
        foreach (self::ANNEX5_SUBSTANCES as $substance) {
            if (str_contains($textes, $substance)) {
                return true;
            }
        }

        return false;
    }

    /** Le code ATC sans le « Q » vétérinaire : QJ01MA90 devient J01MA90 */
    private static function atc(Produit $produit): string
    {
        $atc = strtoupper(preg_replace('/\s+/', '', (string) $produit->atc_code));

        return str_starts_with($atc, 'Q') ? substr($atc, 1) : $atc;
    }
}
