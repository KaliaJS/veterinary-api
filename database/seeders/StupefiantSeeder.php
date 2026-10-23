<?php

namespace Database\Seeders;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MouvementStupefiant;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Produit;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Registre des stupéfiants du front (tools.js — STUP_SUBS, STUP_BASE,
 * stupMoves) : quatre substances ajoutées au catalogue (marquées
 * stupéfiant, suivies en ml), les soldes de base repris comme
 * mouvements de report à l'ouverture du registre, puis les quatre
 * mouvements seedés — patients et signataires reliés par de vraies
 * clés. Registre tenu par Deux Rives, comme l'écran.
 */
class StupefiantSeeder extends Seeder
{
    use WithoutModelEvents;

    /** [nom → réf, prix, coût, report STUP_BASE (ml)] */
    private const array SUBSTANCES = [
        ['Méthadone 10 mg/ml', 'MED-0910', 38.5, 21.2, 25],
        ['Kétamine 100 mg/ml', 'MED-0911', 29.9, 16.8, 48],
        ['Buprénorphine 0,3 mg/ml', 'MED-0912', 44.0, 25.6, 9],
        ['Diazépam 5 mg/ml', 'MED-0913', 18.9, 9.4, 14],
    ];

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();

        $meyer = Employe::where('nom', 'Dr Anna Meyer')->first();
        $solal = Employe::where('nom', 'Dr Rachel Solal')->first();
        $adjei = Employe::where('nom', 'Dr Kofi Adjei')->first();

        $produits = [];

        foreach (self::SUBSTANCES as [$nom, $reference, $prix, $cout, $report]) {
            $produits[$nom] = Produit::create([
                'organisation_id' => $organisation->id,
                'nom' => $nom,
                'categorie' => 'med',
                'reference' => $reference,
                'prix' => $prix,
                'cout' => $cout,
                'unite' => 'ml',
                'seuil_alerte' => 1,
                'est_boutique' => false,
                'est_sur_ordonnance' => true,
                'est_stupefiant' => true,
                'fournisseur' => 'Vetopharm SA',
            ]);

            MouvementStupefiant::create([
                'clinique_id' => $deuxRives->id,
                'produit_id' => $produits[$nom]->id,
                'employe_id' => $meyer?->id,
                'quantite' => $report,
                'motif' => "Report d'inventaire : ouverture du registre",
                'created_at' => now()->subDays(30)->setTime(8, 0),
            ]);
        }

        // stupMoves du front, du plus ancien au plus récent.
        $mouvements = [
            ['Buprénorphine 0,3 mg/ml', -0.6, 'Nala', $solal, 'Analgésie post-op', now()->subDays(6)->setTime(15, 25)],
            ['Méthadone 10 mg/ml', 20, null, $meyer, 'Réception grossiste : lot MT-4471', now()->subDays(3)->setTime(11, 0)],
            ['Kétamine 100 mg/ml', -1.2, 'Marcel', $adjei, 'Induction anesthésie', now()->subDay()->setTime(9, 10)],
            ['Méthadone 10 mg/ml', -0.4, 'Isis', $meyer, 'Analgésie pré-op ostéosynthèse', now()->subDay()->setTime(16, 40)],
        ];

        foreach ($mouvements as [$substance, $quantite, $nomPatient, $signataire, $motif, $date]) {
            MouvementStupefiant::create([
                'clinique_id' => $deuxRives->id,
                'produit_id' => $produits[$substance]->id,
                'patient_id' => $nomPatient === null ? null : $this->patient($organisation, $nomPatient)->id,
                'employe_id' => $signataire?->id,
                'quantite' => $quantite,
                'motif' => $motif,
                'created_at' => $date,
            ]);
        }
    }

    /** Le patient homonyme du front, sinon n'importe lequel — la sortie exige une FK. */
    private function patient(Organisation $organisation, string $nom): Patient
    {
        return Patient::where('organisation_id', $organisation->id)->where('nom', $nom)->first()
            ?? Patient::where('organisation_id', $organisation->id)->firstOrFail();
    }
}
