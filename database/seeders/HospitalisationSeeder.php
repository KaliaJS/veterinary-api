<?php

namespace Database\Seeders;

use App\Auth\RolesParDefaut;
use App\Enums\StatutSejour;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use Carbon\CarbonImmutable;
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
    use WithoutModelEvents;

    /** id front produit → référence catalogue (products.js). */
    private const array PRODUITS = [
        'pr01' => 'MED-0114',
        'pr02' => 'MED-0087',
        'pr05' => 'MED-0166',
        'pr09' => 'ALI-0355',
        'pr20' => 'MAT-0631',
    ];

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

        $produits = [];
        foreach (self::PRODUITS as $cle => $reference) {
            $produits[$cle] = Produit::where('reference', $reference)->firstOrFail()->id;
        }

        foreach ($this->sejours() as [$clinique, $box, $indexPatient, $motif, $statut, $vet, $admis, $sortiePrevue, $perfusion, $soins, $constantes]) {
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

            foreach ($soins as [$minutes, $acte, $produit, $fait]) {
                $sejour->soins()->create([
                    'prevu_a' => $minutes,
                    'acte' => $acte,
                    'produit_id' => $produit === null ? null : $produits[$produit],
                    'fait' => $fait,
                    'fait_le' => $fait ? $this->datetime([0, $minutes]) : null,
                ]);
            }

            foreach ($constantes as [$minutes, $temperature, $fc, $fr]) {
                $sejour->constantes()->create([
                    'mesure_a' => $minutes,
                    'temperature' => $temperature,
                    'fc' => $fc,
                    'fr' => $fr,
                ]);
            }
        }
    }

    private function creerDrFavre(Organisation $organisation, Clinique $leLac): Employe
    {
        $favre = Employe::create([
            'organisation_id' => $organisation->id,
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
     * soins : [minutes, acte, produit, fait] ; constantes typées
     * (« 38,4 °C » → 38.4).
     *
     * @return list<array{string, int, int, string, StatutSejour, string, array{int, int}, string, ?string, list<array{int, string, ?string, bool}>, list<array{int, float, int, int}>}>
     */
    private function sejours(): array
    {
        return [
            ['deuxRives', 1, 4, 'Post-op entérotomie (corps étranger)', StatutSejour::Surveillance, 'Dr Meyer', [1, 860], 'Demain matin', 'Ringer lactate · 45 ml/h', [
                [480, 'Contrôle perfusion + pansement abdominal', null, true],
                [600, 'Méloxicam 0,1 mg/kg IV', 'pr01', true],
                [720, 'Proposition eau + pâtée convalescence', 'pr09', true],
                [870, 'Contrôle T° / douleur (échelle Glasgow)', null, false],
                [1050, 'Amoxicilline 12,5 mg/kg PO', 'pr02', false],
            ], [[480, 38.4, 88, 24], [660, 38.6, 92, 22], [810, 38.5, 90, 20]]],

            ['deuxRives', 2, 9, 'Insuffisance rénale décompensée', StatutSejour::Critique, 'Dr Solal', [0, 460], 'À réévaluer', 'NaCl 0,9 % · 80 ml/h', [
                [510, 'Bilan urée / créatinine (labo)', null, true],
                [660, 'Contrôle diurèse + pesée', null, true],
                [810, 'Antiémétique IV', null, false],
                [960, 'Contrôle électrolytes (iono)', null, false],
                [1110, 'Réévaluation vétérinaire — poursuite fluides', null, false],
            ], [[480, 37.9, 128, 32], [630, 38.1, 120, 30], [780, 38.2, 112, 28]]],

            ['deuxRives', 4, 14, 'Surveillance post-anesthésie (détartrage)', StatutSejour::Stable, 'Dr Adjei', [0, 610], 'Auj. 17:30', null, [
                [640, 'Réveil — contrôle réflexe de déglutition', null, true],
                [760, 'Proposition d\'eau', null, true],
                [900, 'Contrôle T° + gencives', null, false],
                [1020, 'Visite de sortie + consignes au propriétaire', null, false],
            ], [[660, 37.6, 96, 26], [780, 38.2, 92, 24]]],

            ['deuxRives', 6, 21, 'Gastro-entérite hémorragique — réhydratation', StatutSejour::Surveillance, 'Dr Meyer', [1, 1145], 'Demain', 'Ringer lactate · 60 ml/h', [
                [540, 'Contrôle hydratation (pli de peau, TRC)', null, true],
                [720, 'Antiémétique + pansement digestif', null, true],
                [890, 'Repas fractionné — ration digestible', 'pr09', false],
                [1080, 'Contrôle selles + T°', null, false],
            ], [[510, 38.8, 104, 28], [720, 38.9, 100, 26]]],

            ['deuxRives', 7, 27, 'Fracture tibia — attente ostéosynthèse (bloc demain)', StatutSejour::Stable, 'Dr Meyer', [0, 530], 'Après chirurgie', null, [
                [560, 'Analgésie — méthadone 0,2 mg/kg IM', null, true],
                [780, 'Contrôle confort + immobilisation', null, true],
                [1000, 'À jeun à partir de 20:00 (chirurgie demain)', null, false],
            ], [[540, 38.5, 110, 30]]],

            ['leLac', 1, 6, 'Pancréatite aiguë — fluides + analgésie', StatutSejour::Surveillance, 'Dr Favre', [1, 990], 'À réévaluer', 'Ringer lactate · 52 ml/h', [
                [500, 'Contrôle douleur abdominale', null, true],
                [700, 'Antiémétique IV', null, true],
                [880, 'Reprise alimentaire précoce — petite ration', 'pr09', false],
                [1060, 'Contrôle lipase (labo) + T°', null, false],
            ], [[480, 38.7, 116, 30], [750, 38.4, 108, 26]]],

            ['leLac', 2, 11, 'Coupure profonde coussinet — suture + surveillance', StatutSejour::Stable, 'Dr Favre', [0, 560], 'Auj. 18:00', null, [
                [590, 'Contrôle pansement + saignement', null, true],
                [820, 'Renouvellement pansement', 'pr20', false],
                [1040, 'Visite de sortie + ordonnance', null, false],
            ], [[600, 38.3, 94, 22]]],

            ['leLac', 5, 17, 'Diabète — équilibrage insuline (courbe de glycémie)', StatutSejour::Surveillance, 'Dr Favre', [2, 495], 'Demain soir', null, [
                [480, 'Glycémie + insuline', 'pr05', true],
                [660, 'Glycémie de contrôle', null, true],
                [840, 'Glycémie de contrôle', null, false],
                [1020, 'Glycémie + repas', null, false],
                [1200, 'Glycémie du soir + insuline', 'pr05', false],
            ], [[480, 38.2, 88, 20], [660, 38.3, 86, 20]]],
        ];
    }
}
