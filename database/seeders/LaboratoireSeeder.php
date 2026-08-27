<?php

namespace Database\Seeders;

use App\Enums\DrapeauResultatLabo;
use App\Enums\PieceNumerotee;
use App\Enums\PrioriteAnalyse;
use App\Enums\StatutAnalyse;
use App\Models\Analyse;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\PanelLabo;
use App\Models\Patient;
use App\Models\SequenceNumerotation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Laboratoire du front (labs.js — buildLabs) : douze demandes à Deux
 * Rives, numéros A-YY-0141 à 0152, mêmes statuts, panels et priorités,
 * même sélection de patients (patients[(i × 11 + 5) % len]). Les
 * valeurs de résultats sont plausibles (dans les bornes, quelques
 * drapeaux) sans parité RNG — comme le bloc, le raccord fin se fera à
 * la bascule.
 */
class LaboratoireSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();

        $veterinaires = [
            Employe::where('nom', 'Dr Anna Meyer')->firstOrFail(),
            Employe::where('nom', 'Dr Rachel Solal')->firstOrFail(),
            Employe::where('nom', 'Dr Kofi Adjei')->firstOrFail(),
        ];
        $meyer = $veterinaires[0];

        $panels = PanelLabo::where('organisation_id', $organisation->id)->with('parametres')->get()->keyBy('code');
        $patients = Patient::where('organisation_id', $organisation->id)->orderBy('id')->get();

        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $annee = now()->format('y');
        $numeroLigne = 0;

        foreach ($this->demandes() as $i => [$jours, $heure, $statut, $codes, $priorite]) {
            [$heures, $minutes] = explode(':', $heure);

            $analyse = Analyse::create([
                'clinique_id' => $deuxRives->id,
                'patient_id' => $patients[(($i + 1) * 11 + 5) % $patients->count()]->id,
                'numero' => sprintf('A-%s-%04d', $annee, 141 + $i),
                'statut' => $statut,
                'priorite' => $priorite,
                'preleve_le' => $aujourdhui->subDays($jours)->setTime((int) $heures, (int) $minutes)->utc(),
                'prescripteur_id' => $veterinaires[($i + 1) % 3]->id,
                ...$statut === StatutAnalyse::Valide ? [
                    'valide_par_id' => $meyer->id,
                    'valide_le' => $aujourdhui->subDays($jours)->setTime((int) $heures + 2, 0)->utc(),
                ] : [],
            ]);

            foreach ($codes as $code) {
                $panel = $panels[$code];

                $analysePanel = $analyse->panels()->create([
                    'panel_labo_id' => $panel->id,
                    'code' => $panel->code,
                    'nom' => $panel->nom,
                    'tube' => $panel->tube,
                ]);

                foreach ($panel->parametres as $parametre) {
                    $valeur = $statut === StatutAnalyse::Attente
                        ? null
                        : $this->valeur($numeroLigne++, (float) $parametre->borne_basse, (float) $parametre->borne_haute);

                    $analyse->resultats()->create([
                        'analyse_panel_id' => $analysePanel->id,
                        'position' => $parametre->position,
                        'parametre' => $parametre->nom,
                        'unite' => $parametre->unite,
                        'borne_basse' => $parametre->borne_basse,
                        'borne_haute' => $parametre->borne_haute,
                        'valeur' => $valeur,
                        'drapeau' => $valeur === null ? null : DrapeauResultatLabo::depuis($valeur, (float) $parametre->borne_basse, (float) $parametre->borne_haute),
                    ]);
                }
            }
        }

        SequenceNumerotation::create([
            'organisation_id' => $organisation->id,
            'piece' => PieceNumerotee::AnalyseLabo,
            'annee' => now()->year,
            'prochain' => 153,
        ]);
    }

    /**
     * Valeur plausible, déterministe : environ un paramètre sur sept
     * au-dessus des bornes, un sur onze en dessous (proportions du
     * générateur du front), le reste dans les bornes.
     */
    private function valeur(int $index, float $borneBasse, float $borneHaute): float
    {
        if ($index % 7 === 3) {
            return round($borneHaute * 1.18, 2);
        }

        if ($index % 11 === 5) {
            return $borneBasse === 0.0 ? round($borneHaute * 1.4, 2) : round($borneBasse * 0.72, 2);
        }

        $fraction = (($index * 37) % 80 + 10) / 100;

        return round($borneBasse + ($borneHaute - $borneBasse) * $fraction, 2);
    }

    /**
     * Les douze demandes du front : jours en arrière, heure locale,
     * statut, panels, priorité.
     *
     * @return list<array{int, string, StatutAnalyse, list<string>, PrioriteAnalyse}>
     */
    private function demandes(): array
    {
        return [
            [0, '11:42', StatutAnalyse::AValider, ['nfs', 'bioch'], PrioriteAnalyse::Urgente],
            [0, '10:15', StatutAnalyse::Cours, ['t4'], PrioriteAnalyse::Standard],
            [0, '09:30', StatutAnalyse::Attente, ['urine'], PrioriteAnalyse::Standard],
            [0, '08:55', StatutAnalyse::AValider, ['iono', 'bioch'], PrioriteAnalyse::Standard],
            [0, '08:20', StatutAnalyse::Cours, ['coag'], PrioriteAnalyse::Urgente],
            [1, '17:10', StatutAnalyse::AValider, ['nfs'], PrioriteAnalyse::Standard],
            [1, '15:02', StatutAnalyse::Valide, ['bioch', 'iono'], PrioriteAnalyse::Standard],
            [1, '11:44', StatutAnalyse::Valide, ['nfs', 't4'], PrioriteAnalyse::Standard],
            [1, '09:12', StatutAnalyse::Attente, ['bioch'], PrioriteAnalyse::Standard],
            [2, '16:20', StatutAnalyse::Valide, ['urine'], PrioriteAnalyse::Standard],
            [2, '10:05', StatutAnalyse::Valide, ['nfs', 'bioch', 'iono'], PrioriteAnalyse::Urgente],
            [3, '14:30', StatutAnalyse::Valide, ['t4'], PrioriteAnalyse::Standard],
        ];
    }
}
