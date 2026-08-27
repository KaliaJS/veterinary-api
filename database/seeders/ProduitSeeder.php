<?php

namespace Database\Seeders;

use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeFournisseur;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\Lot;
use App\Models\Organisation;
use App\Models\Produit;
use App\Models\SequenceNumerotation;
use App\Models\Stock;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Catalogue produits du front (products.js — PRODUCTS_SEED), stock par
 * clinique (c1 = Deux Rives, c2 = Le Lac), bons de commande et lots de
 * l'écran Stocks (stocks.js). Les séquences de numérotation repartent
 * après les numéros seedés.
 */
class ProduitSeeder extends Seeder
{
    use WithoutModelEvents;

    /** [ref → nom, cat, réf, prix, coût, unité, seuil, boutique, ordonnance, exp MM.YYYY, fournisseur, stock c1, stock c2] */
    private const array CATALOGUE = [
        ['Meloxicam 1,5 mg/ml · 100 ml', 'med', 'MED-0114', 24.9, 13.4, 'flacon', 6, false, true, '10.2026', 'Vetopharm SA', 9, 4],
        ['Amoxicilline 250 mg · 20 cp', 'med', 'MED-0087', 18.5, 9.1, 'boîte', 10, false, true, '03.2027', 'Vetopharm SA', 22, 11],
        ['Oclacitinib 16 mg · 20 cp', 'med', 'MED-0201', 68, 41, 'boîte', 4, false, true, '09.2026', 'Zoevet AG', 3, 5],
        ['Vaccin CHPPiL · 1 dose', 'med', 'MED-0009', 42, 19.8, 'dose', 20, false, true, '12.2026', 'Zoevet AG', 34, 12],
        ['Insuline vétérinaire · 10 ml', 'med', 'MED-0166', 54.5, 33.2, 'flacon', 3, false, true, '11.2026', 'Vetopharm SA', 2, 3],
        ['Croquettes rénales chien · 2 kg', 'ali', 'ALI-0311', 34.9, 21.5, 'sac', 8, true, false, '05.2027', 'NutriVet Sàrl', 14, 6],
        ['Croquettes rénales chat · 1,5 kg', 'ali', 'ALI-0312', 29.9, 18.1, 'sac', 8, true, false, '04.2027', 'NutriVet Sàrl', 5, 0],
        ['Aliment hypoallergénique chien · 2 kg', 'ali', 'ALI-0340', 38.5, 23.9, 'sac', 6, true, false, '06.2027', 'NutriVet Sàrl', 11, 7],
        ['Pâtée convalescence · 12 × 85 g', 'ali', 'ALI-0355', 26.4, 15.2, 'carton', 5, true, false, '01.2027', 'NutriVet Sàrl', 8, 9],
        ['Shampooing chlorhexidine · 250 ml', 'soin', 'SOI-0422', 19.9, 9.8, 'flacon', 6, true, false, '08.2027', 'DermaPet SA', 12, 4],
        ['Nettoyant auriculaire · 125 ml', 'soin', 'SOI-0431', 16.5, 7.9, 'flacon', 6, true, false, '02.2027', 'DermaPet SA', 9, 8],
        ['Dentifrice enzymatique + doigtier', 'soin', 'SOI-0448', 14.9, 6.6, 'kit', 5, true, false, '07.2027', 'DermaPet SA', 4, 2],
        ['Complément articulations · 60 cp', 'soin', 'SOI-0470', 32, 17.4, 'boîte', 4, true, false, '10.2026', 'DermaPet SA', 7, 5],
        ['Pipettes spot-on chien M · ×4', 'apara', 'APA-0521', 28.9, 14.7, 'boîte', 10, true, false, '09.2026', 'Zoevet AG', 6, 3],
        ['Pipettes spot-on chat · ×4', 'apara', 'APA-0522', 26.9, 13.6, 'boîte', 10, true, false, '11.2026', 'Zoevet AG', 0, 0],
        ['Comprimés antipuces chien · 3 cp', 'apara', 'APA-0540', 39.5, 22.8, 'boîte', 6, true, true, '01.2027', 'Zoevet AG', 13, 6],
        ['Vermifuge large spectre · 2 cp', 'apara', 'APA-0555', 21.9, 10.9, 'boîte', 8, true, false, '04.2027', 'Zoevet AG', 18, 10],
        ['Collerette T45', 'mat', 'MAT-0610', 12.5, 5.2, 'pièce', 5, true, false, null, 'MedSupply GmbH', 8, 2],
        ['Seringues 5 ml · ×100', 'mat', 'MAT-0623', 22, 11.5, 'carton', 4, false, false, null, 'MedSupply GmbH', 6, 5],
        ['Compresses stériles · ×100', 'mat', 'MAT-0631', 9.8, 4.1, 'boîte', 8, false, false, null, 'MedSupply GmbH', 15, 3],
        ['Muselière nylon · taille M', 'mat', 'MAT-0650', 17.9, 8.3, 'pièce', 3, true, false, null, 'MedSupply GmbH', 5, 4],
        ['Harpagophytum articulations · 90 gél.', 'phyto', 'PHY-0701', 27.5, 13.1, 'boîte', 4, true, false, '05.2027', 'PhytoVet Lab', 7, 3],
        ['Valériane & passiflore — calmant · 30 ml', 'phyto', 'PHY-0714', 22.9, 10.6, 'flacon', 4, true, false, '02.2027', 'PhytoVet Lab', 6, 5],
        ['Chardon-Marie hépatoprotecteur · 60 cp', 'phyto', 'PHY-0722', 31, 15.4, 'boîte', 3, true, false, '09.2027', 'PhytoVet Lab', 4, 2],
    ];

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $leLac = Clinique::where('nom', 'Le Lac')->firstOrFail();

        foreach (self::CATALOGUE as [$nom, $categorie, $reference, $prix, $cout, $unite, $seuil, $boutique, $ordonnance, $exp, $fournisseur, $stockC1, $stockC2]) {
            $produit = Produit::create([
                'organisation_id' => $organisation->id,
                'nom' => $nom,
                'categorie' => $categorie,
                'reference' => $reference,
                'prix' => $prix,
                'cout' => $cout,
                'unite' => $unite,
                'seuil_alerte' => $seuil,
                'est_boutique' => $boutique,
                'est_sur_ordonnance' => $ordonnance,
                'peremption_le' => $this->peremptionLe($exp),
                'fournisseur' => $fournisseur,
            ]);

            foreach ([[$deuxRives, $stockC1], [$leLac, $stockC2]] as [$clinique, $quantite]) {
                Stock::create([
                    'produit_id' => $produit->id,
                    'clinique_id' => $clinique->id,
                    'quantite' => $quantite,
                ]);
            }

            // Un lot par détention, à la péremption du produit — la
            // répartition fine par lot est l'affaire des réceptions.
            if ($exp !== null) {
                foreach ([[$deuxRives, $stockC1], [$leLac, $stockC2]] as $index => [$clinique, $quantite]) {
                    if ($quantite > 0) {
                        Lot::create([
                            'produit_id' => $produit->id,
                            'clinique_id' => $clinique->id,
                            'numero_lot' => 'LOT '.substr($exp, 3).chr(65 + $index).substr($reference, -3),
                            'peremption_le' => $this->peremptionLe($exp),
                            'quantite' => $quantite,
                        ]);
                    }
                }
            }
        }

        $annee = now()->year;

        $envoyee = CommandeFournisseur::create([
            'clinique_id' => $deuxRives->id,
            'numero' => "BC-{$annee}-031",
            'fournisseur' => 'Vetopharm SA',
            'statut' => StatutCommandeFournisseur::Envoyee,
            'envoyee_le' => now()->subDay(),
        ]);

        foreach ([['MED-0166', 6], ['MED-0114', 12], ['MED-0087', 20]] as [$reference, $quantite]) {
            $produit = Produit::where('reference', $reference)->firstOrFail();
            $envoyee->lignes()->create(['produit_id' => $produit->id, 'quantite' => $quantite, 'cout' => $produit->cout]);
        }

        $receptionnee = CommandeFournisseur::create([
            'clinique_id' => $leLac->id,
            'numero' => "BC-{$annee}-030",
            'fournisseur' => 'NutriVet Sàrl',
            'statut' => StatutCommandeFournisseur::Receptionnee,
            'envoyee_le' => now()->subDays(6),
            'receptionnee_le' => now()->subDays(2),
        ]);

        foreach ([['ALI-0312', 12], ['ALI-0311', 10], ['ALI-0355', 8]] as [$reference, $quantite]) {
            $produit = Produit::where('reference', $reference)->firstOrFail();
            $receptionnee->lignes()->create(['produit_id' => $produit->id, 'quantite' => $quantite, 'cout' => $produit->cout]);
        }

        // La séquence des bons de commande repart après les numéros seedés.
        SequenceNumerotation::create([
            'organisation_id' => $organisation->id,
            'piece' => PieceNumerotee::CommandeFournisseur,
            'annee' => $annee,
            'prochain' => 32,
        ]);
    }

    private function peremptionLe(?string $exp): ?string
    {
        if ($exp === null) {
            return null;
        }

        [$mois, $annee] = explode('.', $exp);

        return "{$annee}-{$mois}-01";
    }
}
