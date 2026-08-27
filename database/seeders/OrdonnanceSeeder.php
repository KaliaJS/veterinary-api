<?php

namespace Database\Seeders;

use App\Enums\PieceNumerotee;
use App\Enums\StatutOrdonnance;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Ordonnance;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\SequenceNumerotation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Registre des ordonnances du front (ordos.js) : cinq prescriptions
 * signées par les vétérinaires seedés, lignes sur le catalogue
 * produits ; la séquence RX repart après les numéros seedés.
 */
class OrdonnanceSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();

        $signataires = [
            'meyer' => Employe::where('nom', 'Dr Anna Meyer')->firstOrFail(),
            'adjei' => Employe::where('nom', 'Dr Kofi Adjei')->firstOrFail(),
            'solal' => Employe::where('nom', 'Dr Rachel Solal')->firstOrFail(),
        ];

        $patients = Patient::where('organisation_id', $organisation->id)->orderBy('id')->get();
        $produit = fn (string $reference): Produit => Produit::where('reference', $reference)->firstOrFail();

        $annee = now()->year;

        // [n°, signataire, jours, lignes [réf, poso, durée], renouvellements, statut, note]
        $registre = [
            [58, 'meyer', 0, [['MED-0114', '0,1 mg/kg — 1× par jour, pendant le repas', '7 jours']], 1, StatutOrdonnance::Active, 'Contrôle si la boiterie persiste au-delà de 7 jours.'],
            [57, 'adjei', 0, [['MED-0087', '1 comprimé matin et soir', '10 jours'], ['MED-0114', '0,1 mg/kg — 1× par jour', '4 jours']], 0, StatutOrdonnance::Active, null],
            [56, 'solal', 1, [['MED-0201', '1 comprimé 2× par jour pendant 14 j, puis 1× par jour', '28 jours']], 2, StatutOrdonnance::Active, 'Dermatite atopique — réévaluation à 1 mois.'],
            [55, 'meyer', 4, [['MED-0166', '2 UI en injection sous-cutanée — 2× par jour après le repas', '30 jours']], 3, StatutOrdonnance::Active, 'Courbe de glycémie au prochain renouvellement.'],
            [54, 'meyer', 9, [['APA-0540', '1 comprimé en une prise unique', 'Prise unique']], 0, StatutOrdonnance::Cloturee, 'Traitement terminé.'],
        ];

        foreach ($registre as $index => [$numero, $signataire, $jours, $lignes, $renouvellements, $statut, $note]) {
            $ordonnance = Ordonnance::create([
                'organisation_id' => $organisation->id,
                'clinique_id' => $deuxRives->id,
                'numero' => sprintf('RX-%d-%03d', $annee, $numero),
                'patient_id' => $patients[($index * 17 + 3) % $patients->count()]->id,
                'employe_id' => $signataires[$signataire]->id,
                'signee_le' => now()->subDays($jours),
                'renouvellements' => $renouvellements,
                'statut' => $statut,
                'note' => $note,
            ]);

            foreach ($lignes as [$reference, $posologie, $duree]) {
                $ordonnance->lignes()->create([
                    'produit_id' => $produit($reference)->id,
                    'posologie' => $posologie,
                    'duree' => $duree,
                ]);
            }
        }

        SequenceNumerotation::create([
            'organisation_id' => $organisation->id,
            'piece' => PieceNumerotee::Ordonnance,
            'annee' => $annee,
            'prochain' => 59,
        ]);
    }
}
