<?php

namespace Tests\Feature\Patients;

use App\Http\Controllers\Api\V1\PatientTimelineController;
use App\Models\Analyse;
use App\Models\AnalysePanel;
use App\Models\AnalyseResultat;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\PanelLabo;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\Produit;
use App\Models\ReleveOphtalmologique;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le dossier patient à onglets (case de notes/todo.md, dépôt web : « Les
 * filtres et la recherche des onglets portent sur toutes les lignes, pas
 * seulement les pages lues ») : le fil d'une sorte se filtre (`filtre`) et
 * se cherche (`q`) côté serveur, sur toute l'histoire de l'animal, avec le
 * même curseur que le fil entier.
 */
class PatientTimelineSearchTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $bourbon;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(10, 0));
        $this->clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Borel']);
        $this->bourbon = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id, 'nom' => 'Bourbon', 'espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function fil(array $requete): array
    {
        return $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?".http_build_query($requete), $this->enTetes($this->jeton))->assertOk()->json();
    }

    /** Tout le fil demandé, page après page */
    private function toutLeFil(array $requete): Collection
    {
        $vus = collect();
        $curseur = [];
        do {
            $page = $this->fil([...$requete, ...$curseur]);
            $vus = $vus->merge($page['data']);
            $suivant = $page['meta']['suivant'];
            $curseur = $suivant ? ['avant' => $suivant, 'sauter' => $page['meta']['sauter']] : [];
        } while ($suivant !== null && $vus->count() < 500);

        return $vus;
    }

    private function vaccin(string $nom, int $joursAvant, ?int $echeanceDans = null): Vaccination
    {
        return Vaccination::factory()->create([
            'patient_id' => $this->bourbon->id,
            'nom' => $nom,
            'marque' => $nom.' marque',
            'injectee_le' => now()->subDays($joursAvant)->toDateString(),
            'echeance_le' => $echeanceDans === null ? null : now()->addDays($echeanceDans)->toDateString(),
        ]);
    }

    private function ordonnance(int $joursAvant, string $medicament, string $duree, int $renouvellements = 0, string $statut = 'active'): Ordonnance
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->bourbon->id,
            'signee_le' => now()->subDays($joursAvant)->toDateString(),
            'renouvellements' => $renouvellements,
            'statut' => $statut,
        ]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => Produit::factory()->medicament()->create(['organisation_id' => $this->bourbon->organisation_id, 'nom' => $medicament])->id,
            'duree' => $duree,
        ]);

        return $ordonnance;
    }

    public function test_la_recherche_trouve_une_ligne_au_dela_de_la_premiere_page_sans_tenir_compte_de_la_casse(): void
    {
        foreach (range(1, 40) as $i) {
            $this->vaccin('Carré', $i);
        }
        $lepto = $this->vaccin('Leptospirose', 200);

        $page = $this->fil(['type' => 'vaccin', 'q' => 'LEPTO']);

        $this->assertSame([$lepto->id], collect($page['data'])->where('source', 'vaccination')->pluck('id')->values()->all());
        $this->assertNull($page['meta']['suivant']);
    }

    public function test_la_recherche_d_une_pesee_par_son_poids_ou_sa_date(): void
    {
        foreach (range(1, 35) as $i) {
            Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 20 + $i / 10, 'pesee_le' => now()->subDays($i)->toDateString()]);
        }
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 31.7, 'pesee_le' => '2025-03-12']);

        $this->assertSame(['31,7 kg'], collect($this->fil(['type' => 'poids', 'q' => '31,7'])['data'])->pluck('titre')->all());
        $this->assertSame(['31,7 kg'], collect($this->fil(['type' => 'poids', 'q' => '12.03.2025'])['data'])->pluck('titre')->all());
        $this->assertSame(['31,7 kg'], collect($this->fil(['type' => 'poids', 'q' => '12 MARS 2025'])['data'])->pluck('titre')->all());
    }

    public function test_un_filtre_se_pagine_sur_toute_l_histoire_sans_rien_perdre(): void
    {
        // 34 pesées de l'année, à cheval sur la page, et 20 plus anciennes
        foreach (range(1, 34) as $i) {
            Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 20 + $i / 10, 'pesee_le' => now()->subDays($i * 5)->toDateString()]);
        }
        foreach (range(1, 20) as $i) {
            Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 15, 'pesee_le' => now()->subYears(2)->subDays($i)->toDateString()]);
        }

        $an = $this->toutLeFil(['type' => 'poids', 'filtre' => 'an']);
        $this->assertCount(34, $an);
        $this->assertCount(34, $an->pluck('id')->unique());
        $this->assertCount(54, $this->toutLeFil(['type' => 'poids', 'filtre' => 'trois']));
        // L'écart reste celui avec la pesée précédente, même filtrée
        $this->assertNotNull($an->last()['ecart_kg']);
    }

    public function test_les_filtres_des_vaccins(): void
    {
        $ancienne = $this->vaccin('Rage', 400, 20);
        $recente = $this->vaccin('Rage', 30, 300);
        $this->vaccin('Leptospirose', 20, -5);

        $current = collect($this->fil(['type' => 'vaccin', 'filtre' => 'current'])['data']);
        $this->assertSame(['vaccination'], $current->pluck('source')->unique()->values()->all());
        $this->assertNotContains($ancienne->id, $current->pluck('id'));
        $this->assertContains($recente->id, $current->pluck('id'));

        $renewed = collect($this->fil(['type' => 'vaccin', 'filtre' => 'renewed'])['data']);
        $this->assertSame([$ancienne->id], $renewed->pluck('id')->all());

        $due = collect($this->fil(['type' => 'vaccin', 'filtre' => 'due'])['data']);
        $this->assertSame(['rappel'], $due->pluck('source')->unique()->values()->all());
        $this->assertCount(2, $due);
    }

    public function test_les_filtres_des_prescriptions(): void
    {
        $enCours = $this->ordonnance(2, 'Metacam', '10 jours', 1);
        $finie = $this->ordonnance(60, 'Cerenia', '5 jours');
        $close = $this->ordonnance(3, 'Amoxicilline', '10 jours', 0, 'stop');

        $ids = fn (string $filtre): array => collect($this->fil(['type' => 'prescription', 'filtre' => $filtre])['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$enCours->id], $ids('active'));
        $this->assertSame([$enCours->id], $ids('renew'));
        $this->assertSame(collect([$finie->id, $close->id])->sort()->values()->all(), $ids('closed'));
        // La recherche porte sur les médicaments
        $this->assertSame([$finie->id], collect($this->fil(['type' => 'prescription', 'q' => 'cerENIA'])['data'])->pluck('id')->all());
    }

    public function test_les_filtres_des_examens(): void
    {
        $radio = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'libelle' => 'Thorax', 'realise_le' => now()->subDays(3)]);
        $normale = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'preleve_le' => now()->subDays(4)]);
        $horsNormes = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'preleve_le' => now()->subDays(5)]);
        $labo = PanelLabo::factory()->create(['organisation_id' => $this->bourbon->organisation_id, 'code' => 'bio', 'nom' => 'Biochimie']);
        $panel = AnalysePanel::query()->create(['analyse_id' => $horsNormes->id, 'panel_labo_id' => $labo->id, 'code' => 'bio', 'nom' => 'Biochimie', 'tube' => 'SEC']);
        AnalyseResultat::query()->create(['analyse_id' => $horsNormes->id, 'analyse_panel_id' => $panel->id, 'position' => 1, 'parametre' => 'Créatinine', 'unite' => 'µmol/l', 'borne_basse' => 44, 'borne_haute' => 133, 'valeur' => 142, 'drapeau' => 'H']);
        $releve = ReleveOphtalmologique::factory()->create(['patient_id' => $this->bourbon->id, 'releve_le' => now()->subDays(6)->toDateString()]);

        $lignes = fn (string $filtre): array => collect($this->fil(['type' => 'examen', 'filtre' => $filtre])['data'])->map(fn ($x) => $x['source'].':'.$x['id'])->sort()->values()->all();

        $this->assertSame(['imagerie:'.$radio->id], $lignes('imaging'));
        $this->assertSame(collect(['analyse:'.$normale->id, 'analyse:'.$horsNormes->id])->sort()->values()->all(), $lignes('lab'));
        $this->assertSame(['releve_oph:'.$releve->id], $lignes('eye'));
        $this->assertSame(['analyse:'.$horsNormes->id], $lignes('out'));
    }

    public function test_un_filtre_inconnu_ou_sans_sorte_est_refuse(): void
    {
        $url = "/api/v1/patients/{$this->bourbon->id}/fil?";
        $this->getJson($url.'filtre=due', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('filtre');
        $this->getJson($url.'type=poids&filtre=due', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('filtre');
        $this->getJson($url.'type=vaccin&filtre=perdu', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('filtre');
        $this->getJson($url.'q='.str_repeat('a', 101), $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    /** Vérification du 07.11.2026 : une recherche sans sorte lirait toutes les sources de l'animal, elle est refusée */
    public function test_une_recherche_sans_sorte_est_refusee(): void
    {
        $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?q=rage", $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?type=vaccin&q=rage", $this->enTetes($this->jeton))->assertOk();
    }

    /** Filtré ou cherché, chaque source est lue jusqu'à un plafond (une histoire d'animal n'en approche pas) */
    public function test_la_lecture_filtree_garde_un_plafond(): void
    {
        $this->assertGreaterThanOrEqual(500, PatientTimelineController::NARROWED_LIMIT);
        foreach (range(1, 6) as $i) {
            $this->vaccin('Rage', $i * 10);
        }
        PatientTimelineController::$narrowedLimitForTests = 4;
        try {
            $this->assertCount(4, $this->toutLeFil(['type' => 'vaccin', 'q' => 'rage']));
        } finally {
            PatientTimelineController::$narrowedLimitForTests = null;
        }
    }
}
