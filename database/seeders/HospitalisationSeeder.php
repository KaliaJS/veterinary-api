<?php

namespace Database\Seeders;

use App\Auth\RolesParDefaut;
use App\Enums\StatutSejour;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Sejour;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\ComptesDeDemo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Séjours d'hospitalisation du front (hosp.js — buildStays) : huit
 * séjours répartis entre Deux Rives (boxes 1/2/4/6/7) et Le Lac
 * (boxes 1/2/5), avec plans de soins (produits reliés par de vraies
 * clés) et relevés de constantes. Les patients sont choisis par la
 * même formule d'index que le front ; « Dr Favre » (Le Lac), absent du
 * seed d'équipe, est créé ici — le seed serveur fait foi, comme pour
 * le raccord des deux équipes (docs/branchement.md §2.1).
 */
class HospitalisationSeeder extends Seeder
{
    use ComptesDeDemo;
    use WithoutModelEvents;

    public function run(): void
    {
        $organisation = Organisation::where('nom', 'Deux Rives Vétérinaires')->firstOrFail();
        $deuxRives = Clinique::where('nom', 'Deux Rives')->firstOrFail();
        $leLac = Clinique::where('nom', 'Le Lac')->firstOrFail();

        $favre = $this->creerDrFavre($organisation, $leLac);

        $veterinaires = [
            'Dr Meyer' => Employe::where('nom', 'Dr Anna Meyer')->first(),
            'Dr Solal' => Employe::where('nom', 'Dr Rachel Solal')->first(),
            'Dr Adjei' => Employe::where('nom', 'Dr Kofi Adjei')->first(),
            'Dr Favre' => $favre,
        ];

        $patients = Patient::where('organisation_id', $organisation->id)->orderBy('id')->get();
        // Même sélection que le front : patients[(i * 13 + 2) % len].
        $patient = fn (int $index): Patient => $patients[($index * 13 + 2) % $patients->count()];

        $demo = new HospitalisationDemo;
        foreach ($this->sejours() as [$clinique, $box, $indexPatient, $motif, $statut, $vet, $admis, $sortiePrevue, $perfusion]) {
            $sejour = Sejour::create([
                'clinique_id' => ${$clinique}->id,
                'patient_id' => $patient($indexPatient)->id,
                'box' => $box,
                'motif' => $motif,
                'statut' => $statut,
                'veterinaire_id' => $veterinaires[$vet]?->id,
                'admis_le' => $this->datetime($admis),
                'sortie_prevue' => $sortiePrevue,
                'perfusion' => $perfusion,
            ]);

            // La ronde (03.11.2026) : entrée, traitements et feuilles de chaque
            // jour écrits autour de maintenant (HospitalisationDemo)
            $demo->fill($sejour);
        }
    }

    private function creerDrFavre(Organisation $organisation, Clinique $leLac): Employe
    {
        $favre = Employe::create([
            'organisation_id' => $organisation->id,
            'user_id' => $this->compteDeDemo('Dr Théo Favre', 't.favre@laveyre.ch'),
            'nom' => 'Dr Théo Favre',
            'initiales' => 'TF',
            'role_libelle' => 'Vétérinaire',
            'role_id' => $organisation->roles()->where('nom', RolesParDefaut::VETERINAIRE)->firstOrFail()->id,
            'taux' => 100,
            'contrat' => 'CDI',
            'entree_le' => '2022-05-01',
            'tel' => '079 605 44 18',
            'email' => 't.favre@laveyre.ch',
            'conges_droit' => 25,
            'conges_pris' => 10,
        ]);
        $favre->cliniques()->attach($leLac->id);

        return $favre;
    }

    /** [jours en arrière, minutes locales] → datetime UTC (Europe/Zurich). */
    private function datetime(array $quand): CarbonImmutable
    {
        [$joursEnArriere, $minutes] = $quand;

        return CarbonImmutable::today('Europe/Zurich')
            ->subDays($joursEnArriere)
            ->addMinutes($minutes)
            ->utc();
    }

    /**
     * buildStays du front. Admissions : [jours en arrière, minutes] ;
     * l'entrée, les soins, les traitements et les feuilles sont réécrits
     * autour de maintenant par HospitalisationDemo.
     *
     * @return list<array{string, int, int, string, StatutSejour, string, array{int, int}, string, ?string}>
     */
    private function sejours(): array
    {
        return [
            ['deuxRives', 1, 4, 'Post-op entérotomie (corps étranger)', StatutSejour::Surveillance, 'Dr Meyer', [1, 860], 'Demain matin', 'Ringer lactate · 45 ml/h'],

            ['deuxRives', 2, 9, 'Insuffisance rénale décompensée', StatutSejour::Critique, 'Dr Solal', [0, 460], 'À réévaluer', 'NaCl 0,9 % · 80 ml/h'],

            ['deuxRives', 4, 14, 'Surveillance post-anesthésie (détartrage)', StatutSejour::Stable, 'Dr Adjei', [0, 610], 'Auj. 17:30', null],

            ['deuxRives', 6, 21, 'Gastro-entérite hémorragique : réhydratation', StatutSejour::Surveillance, 'Dr Meyer', [1, 1145], 'Demain', 'Ringer lactate · 60 ml/h'],

            ['deuxRives', 7, 27, 'Fracture tibia : attente ostéosynthèse (bloc demain)', StatutSejour::Stable, 'Dr Meyer', [0, 530], 'Après chirurgie', null],

            ['leLac', 1, 6, 'Pancréatite aiguë : fluides + analgésie', StatutSejour::Surveillance, 'Dr Favre', [1, 990], 'À réévaluer', 'Ringer lactate · 52 ml/h'],

            ['leLac', 2, 11, 'Coupure profonde coussinet : suture + surveillance', StatutSejour::Stable, 'Dr Favre', [0, 560], 'Auj. 18:00', null],

            ['leLac', 5, 17, 'Diabète : équilibrage insuline (courbe de glycémie)', StatutSejour::Surveillance, 'Dr Favre', [2, 495], 'Demain soir', null],
        ];
    }
}
