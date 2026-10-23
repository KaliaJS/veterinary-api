<?php

namespace Database\Seeders;

use App\Enums\StatutIntervention;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Salle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Bloc opératoire du front (bloc.js — buildSurgeries) : cinq
 * interventions du jour à Deux Rives, salles Bloc 1 / Bloc 2, avec
 * protocoles anesthésiques, checklists dans l'état dérivé du statut
 * (formule du front) et consentements signés archivés au dossier. Non
 * liées aux RDV `chir` du planning : les deux jeux de seeds du front
 * ne se correspondent pas (patients différents) — raccord à faire à la
 * bascule, comme pour les deux équipes.
 */
class BlocSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();

        $salles = [
            'Bloc 1' => Salle::where('clinique_id', $deuxRives->id)->where('nom', 'Bloc 1')->firstOrFail(),
            'Bloc 2' => Salle::where('clinique_id', $deuxRives->id)->where('nom', 'Bloc 2')->firstOrFail(),
        ];

        $chirurgiens = [
            'Dr Meyer' => Employe::where('nom', 'Dr Anna Meyer')->firstOrFail(),
            'Dr Solal' => Employe::where('nom', 'Dr Rachel Solal')->firstOrFail(),
            'Dr Adjei' => Employe::where('nom', 'Dr Kofi Adjei')->firstOrFail(),
        ];

        $patients = Patient::where('organisation_id', $organisation->id)->orderBy('id')->get();
        // Même sélection que le front : patients[(i * 7 + 3) % len].
        $patient = fn (int $index): Patient => $patients[($index * 7 + 3) % $patients->count()];

        foreach ($this->interventions() as [$minutes, $duree, $indexPatient, $procedure, $chirurgien, $salle, $statut, $protocole]) {
            $intervention = Intervention::create([
                'clinique_id' => $deuxRives->id,
                'patient_id' => $patient($indexPatient)->id,
                'salle_id' => $salles[$salle]->id,
                'salle_nom' => $salle,
                'chirurgien_id' => $chirurgiens[$chirurgien]->id,
                'procedure' => $procedure,
                'prevu_le' => CarbonImmutable::today('Europe/Zurich')->addMinutes($minutes)->utc(),
                'duree_minutes' => $duree,
                'statut' => $statut,
                'protocole_anesthesique' => $protocole,
                // Incision dix minutes après l'heure prévue, une fois démarrée
                'incision_a' => in_array($statut, [StatutIntervention::Cours, StatutIntervention::Reveil, StatutIntervention::Fini], true)
                    ? CarbonImmutable::today('Europe/Zurich')->addMinutes($minutes + 10)->utc()
                    : null,
            ]);

            $this->creerChecklist($intervention, $statut);
        }
    }

    /**
     * Checklist dans l'état dérivé du statut (formule du front) ; un
     * consentement coché est un consentement signé — document archivé
     * au dossier du patient.
     */
    private function creerChecklist(Intervention $intervention, StatutIntervention $statut): void
    {
        $enCoursOuApres = in_array($statut, [StatutIntervention::Cours, StatutIntervention::Reveil, StatutIntervention::Fini], true);

        $coches = [
            'jeun' => $statut !== StatutIntervention::Prevu,
            'consent' => $enCoursOuApres,
            'premed' => $statut !== StatutIntervention::Prevu,
            'iv' => ! in_array($statut, [StatutIntervention::Prevu, StatutIntervention::Preparation], true),
            'atb' => in_array($statut, [StatutIntervention::Cours, StatutIntervention::Fini], true),
            'mat' => $statut !== StatutIntervention::Prevu,
        ];

        foreach (Intervention::CHECKLIST_DE_BASE as $code => $libelle) {
            $intervention->checklist()->create([
                'code' => $code,
                'libelle' => $libelle,
                'est_coche' => $coches[$code],
            ]);
        }

        if ($coches['consent']) {
            $patient = $intervention->patient;

            $document = $patient->documents()->create([
                'nom' => 'Consentement éclairé — '.$intervention->procedure,
                'type' => 'consentement',
                'signataire' => $patient->client->nom_affiche,
                'etabli_le' => today()->toDateString(),
            ]);

            $intervention->update([
                'consentement_signe_le' => $intervention->prevu_le->copy()->subMinutes(45),
                'consentement_document_id' => $document->id,
            ]);
        }
    }

    /**
     * buildSurgeries du front : [minutes, durée, index patient,
     * procédure, chirurgien, salle, statut, protocole].
     *
     * @return list<array{int, int, int, string, string, string, StatutIntervention, list<string>}>
     */
    private function interventions(): array
    {
        return [
            [510, 75, 2, 'Ovariohystérectomie de convenance', 'Dr Meyer', 'Bloc 1', StatutIntervention::Fini, [
                'Prémédication — médétomidine 5 µg/kg + butorphanol 0,2 mg/kg IM',
                'Induction — propofol 4 mg/kg IV titré',
                'Maintien — isoflurane 1,5–2 % · O₂ 100 %',
                'Analgésie — méloxicam 0,2 mg/kg SC en fin d\'intervention',
            ]],
            [630, 45, 9, 'Détartrage ultrasonique + extraction 104', 'Dr Solal', 'Bloc 2', StatutIntervention::Fini, [
                'Prémédication — acépromazine 0,03 mg/kg + morphine 0,3 mg/kg IM',
                'Induction — propofol IV titré',
                'Maintien — isoflurane 1,8 % · intubation',
                'Bloc loco-régional — lidocaïne infra-orbitaire',
            ]],
            [810, 90, 14, 'Exérèse masse cutanée flanc droit + histopathologie', 'Dr Meyer', 'Bloc 1', StatutIntervention::Cours, [
                'Prémédication — dexmédétomidine 3 µg/kg + méthadone 0,2 mg/kg IV',
                'Induction — alfaxalone 2 mg/kg IV',
                'Maintien — isoflurane 1,5 % · monitoring capnographie',
                'Fluides — Ringer lactate 5 ml/kg/h',
            ]],
            [900, 60, 6, 'Cystotomie — retrait de calculs vésicaux', 'Dr Adjei', 'Bloc 2', StatutIntervention::Preparation, [
                'Prémédication — midazolam 0,2 mg/kg + méthadone 0,2 mg/kg IV',
                'Induction — propofol IV titré',
                'Maintien — isoflurane · O₂ 100 %',
                'Antibioprophylaxie — céfazoline 22 mg/kg IV',
            ]],
            [975, 120, 11, 'Ostéosynthèse tibia (plaque LCP 2,7)', 'Dr Meyer', 'Bloc 1', StatutIntervention::Prevu, [
                'Prémédication — dexmédétomidine + méthadone',
                'Induction — kétamine 2 mg/kg + midazolam IV',
                'Maintien — isoflurane · bloc sciatique-fémoral bupivacaïne',
                'Analgésie post-op — CRI fentanyl 12 h',
            ]],
        ];
    }
}
