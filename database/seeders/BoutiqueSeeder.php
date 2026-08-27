<?php

namespace Database\Seeders;

use App\Enums\MoyenPaiement;
use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeBoutique;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Facture;
use App\Models\Organisation;
use App\Models\Produit;
use App\Models\SequenceNumerotation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Boutique du front (shop.js) : commandes en ligne C-101…C-104 sur le
 * catalogue seedé, et ventes comptoir des 7 derniers jours — des
 * factures payées de lignes produit, dont les chiffres boutique
 * dérivent. La séquence « C- » repart après les numéros seedés.
 */
class BoutiqueSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $leLac = Clinique::where('nom', 'Le Lac')->firstOrFail();
        $produit = fn (string $reference): Produit => Produit::where('reference', $reference)->firstOrFail();

        // [n°, clinique de retrait, client, statut, minutes écoulées, lignes [réf, qté]]
        $commandes = [
            [104, $deuxRives, 'Marie Aubert', StatutCommandeBoutique::APreparer, 10, [['ALI-0312', 2], ['SOI-0422', 1]]],
            [103, $leLac, 'Paul Kessler', StatutCommandeBoutique::APreparer, 35, [['APA-0555', 1]]],
            [102, $deuxRives, 'Léa Fontanel', StatutCommandeBoutique::Prete, 60 * 17, [['APA-0522', 2]]],
            [101, $leLac, 'Hugo Marti', StatutCommandeBoutique::Retiree, 60 * 25, [['SOI-0470', 1]]],
        ];

        foreach ($commandes as [$numero, $clinique, $client, $statut, $minutes, $lignes]) {
            $commande = CommandeBoutique::create([
                'clinique_id' => $clinique->id,
                'numero' => sprintf('C-%03d', $numero),
                'client_nom' => $client,
                'statut' => $statut,
                'passee_le' => now()->subMinutes($minutes),
                'retiree_le' => $statut === StatutCommandeBoutique::Retiree ? now()->subMinutes($minutes - 30) : null,
            ]);

            foreach ($lignes as [$reference, $quantite]) {
                $commande->lignes()->create([
                    'produit_id' => $produit($reference)->id,
                    'quantite' => $quantite,
                    'prix_chf' => $produit($reference)->prixEffectif(),
                ]);
            }
        }

        // Ventes comptoir des 7 derniers jours (shopSales) — numéros
        // FC- antérieurs à la séquence seedée de la caisse.
        $annee = now()->year;
        $ventes = [
            [320, $deuxRives, 0, MoyenPaiement::Tpe, [['ALI-0312', 2]]],
            [321, $leLac, 0, MoyenPaiement::Twint, [['APA-0522', 1]]],
            [322, $deuxRives, 1, MoyenPaiement::Especes, [['APA-0555', 2]]],
            [323, $leLac, 1, MoyenPaiement::Tpe, [['SOI-0422', 1]]],
            [324, $deuxRives, 2, MoyenPaiement::Tpe, [['SOI-0470', 2]]],
            [325, $leLac, 3, MoyenPaiement::Twint, [['ALI-0312', 3]]],
            [326, $deuxRives, 4, MoyenPaiement::Especes, [['APA-0522', 2]]],
            [327, $leLac, 5, MoyenPaiement::Tpe, [['SOI-0431', 1]]],
            [328, $deuxRives, 6, MoyenPaiement::Tpe, [['PHY-0701', 1]]],
        ];

        foreach ($ventes as [$numero, $clinique, $jours, $moyen, $lignes]) {
            $facture = Facture::create([
                'organisation_id' => $organisation->id,
                'clinique_id' => $clinique->id,
                'numero' => sprintf('FC-%d-%04d', $annee, $numero),
                'emise_le' => now()->subDays($jours)->setTimeFromTimeString('14:30'),
                'statut' => StatutFacture::Payee,
                'moyen_paiement' => $moyen,
                'payee_le' => now()->subDays($jours)->setTimeFromTimeString('14:32'),
                'stock_sorti' => true,
            ]);

            foreach ($lignes as [$reference, $quantite]) {
                $facture->lignes()->create([
                    'produit_id' => $produit($reference)->id,
                    'libelle' => $produit($reference)->nom,
                    'quantite' => $quantite,
                    'prix_chf' => $produit($reference)->prixEffectif(),
                    'type' => TypeLigneFacture::Medicament,
                ]);
            }
        }

        SequenceNumerotation::create([
            'organisation_id' => $organisation->id,
            'piece' => PieceNumerotee::CommandeBoutique,
            'annee' => $annee,
            'prochain' => 105,
        ]);
    }
}
