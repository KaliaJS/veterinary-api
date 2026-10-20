<?php

namespace Database\Seeders;

use App\Enums\CanalDevis;
use App\Enums\ModePrescription;
use App\Enums\MoyenPaiement;
use App\Enums\PieceNumerotee;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\SequenceNumerotation;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Caisse et devis du front (invoices.js — CINV_SEED, devis.js —
 * QDEV_SEED) : factures du jour et de la veille, clôture Z de la
 * veille, devis du cycle complet. Les séquences FC / DV / Z repartent
 * après les numéros seedés.
 */
class FacturationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $annee = now()->year;

        $lanes = LignePlanning::where('clinique_id', $deuxRives->id)
            ->whereIn('nom', ['Dr Meyer', 'Dr Solal', 'Dr Adjei'])
            ->get()
            ->keyBy(fn (LignePlanning $ligne): string => $ligne->nom);

        $patient = fn (string $nom): ?Patient => Patient::where('organisation_id', $organisation->id)->where('nom', $nom)->first();
        $produit = fn (string $reference): Produit => Produit::where('reference', $reference)->firstOrFail();

        // [n°, jours, heure, patient, espèce, propriétaire, lane, statut, moyen, assureur, lignes, prescriptions]
        $factures = [
            ['0341', 0, '08:25', 'Nala', 'CT', 'Mme Lefèvre', 'Dr Meyer', StatutFacture::Payee, MoyenPaiement::Twint, 'Animalia',
                [['Vaccination — acte', 48, TypeLigneFacture::Acte, null], ['Vaccin CHPPiL · 1 dose', 42, TypeLigneFacture::Medicament, 'MED-0009']], []],
            ['0342', 0, '08:55', 'Gaspard', 'CH', 'M. Ourdane', 'Dr Meyer', StatutFacture::Payee, MoyenPaiement::Tpe, 'SantéVet',
                [['Consultation standard', 85, TypeLigneFacture::Acte, null], ['Meloxicam 1,5 mg/ml · 100 ml', 24.9, TypeLigneFacture::Medicament, 'MED-0114']],
                [['Meloxicam 1,5 mg/ml', '0,1 ml / 10 kg — 1×/jour, 5 jours', ModePrescription::Stock]]],
            ['0343', 0, '09:40', 'Zia', 'CH', 'Mme Bruel', 'Dr Meyer', StatutFacture::Payee, MoyenPaiement::Especes, null,
                [['Consultation standard', 85, TypeLigneFacture::Acte, null]],
                [['Oclacitinib 16 mg', '1 cp matin et soir — 14 jours', ModePrescription::Pharmacie]]],
            ['0344', 0, '10:05', 'Praline', 'CT', 'M. Vidal', 'Dr Solal', StatutFacture::Payee, MoyenPaiement::Tpe, null,
                [['Contrôle post-opératoire', 45, TypeLigneFacture::Acte, null]], []],
            ['0338', 1, '17:20', 'Marcel', 'CH', 'M. Fontanet', 'Dr Adjei', StatutFacture::Due, null, null,
                [['Consultation d’urgence', 140, TypeLigneFacture::Acte, null], ['Radiographie — 2 clichés', 120, TypeLigneFacture::Acte, null]], []],
            ['0340', 0, '11:30', 'Sushi', 'CT', 'Mme Diaz', 'Dr Solal', StatutFacture::Due, null, null,
                [['Consultation de suivi', 55, TypeLigneFacture::Acte, null]], []],
        ];

        foreach ($factures as [$numero, $jours, $heure, $nomPatient, $espece, $proprietaire, $lane, $statut, $moyen, $assureur, $lignes, $prescriptions]) {
            $fiche = $patient($nomPatient);
            $emiseLe = now()->subDays($jours)->setTimeFromTimeString($heure);

            $facture = Facture::create([
                'organisation_id' => $organisation->id,
                'clinique_id' => $deuxRives->id,
                'numero' => "FC-{$annee}-{$numero}",
                'emise_le' => $emiseLe,
                'patient_id' => $fiche?->id,
                'client_id' => $fiche?->client_id,
                'patient_name' => $fiche?->nom ?? $nomPatient,
                'species' => $fiche?->espece?->value ?? $espece,
                'owner_name' => $fiche?->client?->nom_affiche ?? $proprietaire,
                'ligne_planning_id' => $lanes[$lane]?->id,
                'statut' => $statut,
                'moyen_paiement' => $moyen,
                'payee_le' => $statut === StatutFacture::Payee ? $emiseLe->copy()->addMinutes(10) : null,
                'stock_sorti' => true,
            ]);

            foreach ($lignes as [$libelle, $prix, $type, $reference]) {
                $facture->lignes()->create([
                    'libelle' => $libelle,
                    'quantite' => 1,
                    'prix_chf' => $prix,
                    'type' => $type,
                    'produit_id' => $reference === null ? null : $produit($reference)->id,
                ]);
            }

            foreach ($prescriptions as [$nom, $posologie, $mode]) {
                $facture->prescriptions()->create(['nom' => $nom, 'posologie' => $posologie, 'mode' => $mode]);
            }

            if ($assureur !== null) {
                $facture->decompte()->create(['assureur' => $assureur]);
            }
        }

        // Clôture de la veille — les factures du jour restent à clôturer.
        ClotureCaisse::create([
            'clinique_id' => $deuxRives->id,
            'numero' => "Z-{$annee}-041",
            'arretee_le' => now()->subDay()->setTimeFromTimeString('18:32'),
            'user_id' => User::where('email', 'a.meyer@deuxrives.ch')->first()?->id,
            'total_tpe' => 692.4,
            'total_twint' => 118.0,
            'total_especes' => 254.9,
            'fond' => 200,
            'especes_comptees' => 452.9,
            'ecart' => -2,
            'depot' => 252.9,
            'nouveau_fond' => 200,
            'note' => 'Écart de -2.00 CHF non résolu — signalé à la comptabilité.',
        ]);

        // [n°, jours, patient, motif, lignes, remise, aléa, acompte, statut, canal, validité relative]
        $devis = [
            ['018', 0, 'Isis', 'Ostéosynthèse fémur droit',
                [['Chirurgie — forfait bloc', 1, 420], ['Radiographie — 2 clichés', 2, 120], ['Analyses laboratoire — profil standard', 1, 96], ['Meloxicam 1,5 mg/ml · 100 ml', 1, 24.9]],
                0, 15, 30, StatutDevis::Accepte, CanalDevis::Portail, 28],
            ['017', 0, 'Pilou', 'Détartrage + polissage sous anesthésie',
                [['Détartrage + polissage', 1, 260], ['Analyses laboratoire — profil standard', 1, 96]],
                5, 0, 20, StatutDevis::Envoye, CanalDevis::Portail, 29],
            ['016', 1, 'Nougat', 'Bilan boiterie — imagerie + consultation',
                [['Consultation standard', 1, 85], ['Radiographie — 2 clichés', 1, 120]],
                0, 0, 0, StatutDevis::Brouillon, null, null],
            ['015', 1, 'Câline', 'Extraction dentaire multiple',
                [['Détartrage + polissage', 1, 260], ['Chirurgie — forfait bloc', 1, 420]],
                0, 20, 30, StatutDevis::Refuse, CanalDevis::Papier, 26],
            ['012', 35, 'Marcel', 'Échographie abdominale de contrôle',
                [['Radiographie — 2 clichés', 1, 120], ['Consultation de suivi', 1, 55]],
                0, 0, 0, StatutDevis::Envoye, CanalDevis::Papier, -5],
        ];

        foreach ($devis as [$numero, $jours, $nomPatient, $motif, $lignes, $remise, $alea, $acompte, $statut, $canal, $validiteRelative]) {
            $fiche = $patient($nomPatient) ?? Patient::where('organisation_id', $organisation->id)->firstOrFail();

            $unDevis = Devis::create([
                'organisation_id' => $organisation->id,
                'numero' => "DV-{$annee}-{$numero}",
                'emis_le' => now()->subDays($jours),
                'patient_id' => $fiche->id,
                'motif' => $motif,
                'remise_pct' => $remise,
                'alea_pct' => $alea,
                'acompte_pct' => $acompte,
                'statut' => $statut,
                'canal' => $canal,
                'valide_jusquau' => $validiteRelative === null ? null : now()->addDays($validiteRelative),
                'signe_le' => $statut === StatutDevis::Accepte ? now()->subDay()->setTimeFromTimeString('18:42') : null,
                'signe_par' => $statut === StatutDevis::Accepte ? 'Signature électronique — espace client' : null,
            ]);

            foreach ($lignes as [$libelle, $quantite, $prix]) {
                $unDevis->lignes()->create([
                    'libelle' => $libelle,
                    'quantite' => $quantite,
                    'prix_chf' => $prix,
                    'type' => TypeLigneFacture::Acte,
                ]);
            }
        }

        foreach ([
            [PieceNumerotee::Facture, 345],
            [PieceNumerotee::Devis, 19],
            [PieceNumerotee::ClotureCaisse, 42],
        ] as [$piece, $prochain]) {
            SequenceNumerotation::create([
                'organisation_id' => $organisation->id,
                'piece' => $piece,
                'annee' => $annee,
                'prochain' => $prochain,
            ]);
        }
    }
}
