<?php

namespace Tests\Feature\Hospitalisation;

use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\StayTreatment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Hospitalisation, la ronde sur tout le séjour (cases de Jim du
 * 03.11.2026, notes/todo.md). Chaque test suit le texte d'une case :
 * la feuille de chaque jour (06:00 à 06:00), les médicaments et qui les a
 * donnés, la douleur sur l'échelle de l'espèce, les observations, le
 * nombre de jours, le séjour jour par jour, les jours sans fin.
 * Il est 14:05 le mardi 3 novembre 2026 à la clinique.
 */
class RoundSheetTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $vet;

    private string $jeton;

    private string $jetonAsv;

    private Patient $chien;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:05', 'Europe/Zurich'));

        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        ['employe' => $this->vet, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Rachel Solal']);
        ['jeton' => $this->jetonAsv] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller']);

        $this->chien = $this->patient(Espece::Chien, 'Tao');
    }

    private function patient(Espece $espece, string $nom): Patient
    {
        return Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create(['espece' => $espece, 'nom' => $nom]);
    }

    /** Entré le dimanche 1er novembre à 09:15 : il est au jour 3 */
    private function sejour(?Patient $patient = null, string $admis = '2026-11-01 09:15', int $box = 2): Sejour
    {
        return Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => ($patient ?? $this->chien)->id,
            'box' => $box,
            'motif' => 'Insuffisance rénale décompensée',
            'admis_le' => CarbonImmutable::parse($admis, 'Europe/Zurich')->utc(),
        ]);
    }

    /** Maropitant à 10:00 et 22:00, depuis l'entrée */
    private function traitement(Sejour $sejour, array $plus = []): StayTreatment
    {
        return StayTreatment::create([
            'sejour_id' => $sejour->id,
            'label' => 'Maropitant',
            'dose' => '1 mg/kg IV',
            'times' => [600, 1320],
            'starts_at' => $sejour->admis_le,
            ...$plus,
        ]);
    }

    private function ronde(string $du, string $au, ?string $jeton = null): array
    {
        return $this->getJson("/api/v1/hospitalisation/ronde?from={$du}&to={$au}", $this->enTetes($jeton ?? $this->jeton))
            ->assertOk()
            ->json('data');
    }

    private function stay(array $ronde, Sejour $sejour): array
    {
        return collect($ronde['stays'])->firstWhere('id', $sejour->id);
    }

    // ————— Une nouvelle feuille chaque matin ; les jours passés restent lisibles —————

    public function test_la_feuille_du_jour_s_ouvre_d_elle_meme_avec_les_traitements_en_cours(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);

        $ronde = $this->ronde('2026-11-01', '2026-11-03');

        $this->assertSame('2026-11-03', $ronde['today']);
        $tao = $this->stay($ronde, $sejour);
        // Une feuille par jour depuis l'entrée ; aujourd'hui ouverte, les autres closes
        $this->assertSame(
            [['2026-11-01', 'closed'], ['2026-11-02', 'closed'], ['2026-11-03', 'open']],
            collect($tao['sheets'])->map(fn (array $f) => [$f['day'], $f['state']])->all(),
        );
        // Les administrations du jour viennent du traitement en cours
        $aujourdhui = collect($tao['cares'])->where('sheet_day', '2026-11-03')->values();
        $this->assertSame([600, 1320], $aujourdhui->pluck('minute')->all());
        $this->assertTrue($aujourdhui->every(fn (array $c) => $c['is_medication'] && $c['label'] === 'Maropitant' && $c['dose'] === '1 mg/kg IV'));

        // Relire ne double rien
        $this->ronde('2026-11-01', '2026-11-03');
        $this->assertSame(6, SejourSoin::query()->where('sejour_id', $sejour->id)->count());
    }

    public function test_la_feuille_va_de_06_00_a_06_00(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour, ['times' => [1320, 120]]);

        $tao = $this->stay($this->ronde('2026-11-03', '2026-11-04'), $sejour);

        // 02:00 du mercredi appartient encore à la feuille du mardi
        $nuit = collect($tao['cares'])->first(fn (array $c) => $c['day'] === '2026-11-04' && $c['minute'] === 120);
        $this->assertSame('2026-11-03', $nuit['sheet_day']);

        // À 05:30 le mercredi, la feuille du jour est encore celle du mardi
        $this->travelTo(CarbonImmutable::parse('2026-11-04 05:30', 'Europe/Zurich'));
        $this->assertSame('2026-11-03', $this->ronde('2026-11-03', '2026-11-04')['today']);
    }

    public function test_les_jours_passes_se_lisent_et_un_oubli_s_y_note_apres_coup(): void
    {
        $sejour = $this->sejour();
        $hier = SejourSoin::create(['sejour_id' => $sejour->id, 'jour' => '2026-11-02', 'prevu_a' => 720, 'acte' => 'Prise de sang']);

        $tao = $this->stay($this->ronde('2026-11-02', '2026-11-02'), $sejour);
        $this->assertSame('Prise de sang', collect($tao['cares'])->firstWhere('id', $hier->id)['label']);

        // Décision de Jim (03.11.2026) : la note tardive est permise, sans
        // limite, avec l'heure réelle du geste ; sans elle, rien n'est noté
        $entetes = $this->enTetes($this->jeton);
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$hier->id}/faire", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$hier->id}/omettre", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$hier->id}/faire", ['time' => 735], $entetes)->assertOk();
        $this->assertTrue(collect($this->stay($this->ronde('2026-11-02', '2026-11-02'), $sejour)['cares'])->firstWhere('id', $hier->id)['is_late_note']);
    }

    // ————— Les médicaments du jour, et qui les a donnés —————

    public function test_un_medicament_donne_dit_a_quelle_heure_et_par_qui(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);
        $this->ronde('2026-11-03', '2026-11-03');
        $dix = SejourSoin::query()->whereDate('jour', '2026-11-03')->where('prevu_a', 600)->sole();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:07', 'Europe/Zurich'));
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$dix->id}/faire", [], $this->enTetes($this->jetonAsv))->assertOk();

        $soin = collect($this->stay($this->ronde('2026-11-03', '2026-11-03'), $sejour)['cares'])->firstWhere('id', $dix->id);
        $this->assertSame('done', $soin['status']);
        $this->assertSame('10:07', $soin['done_time']);
        $this->assertSame('Julie Muller', $soin['by']);
    }

    public function test_un_medicament_non_donne_garde_la_raison_et_qui_l_a_note(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);
        $this->ronde('2026-11-03', '2026-11-03');
        $dix = SejourSoin::query()->whereDate('jour', '2026-11-03')->where('prevu_a', 600)->sole();

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/soins/{$dix->id}/omettre", ['reason' => 'Vomit, à redonner ce soir'], $this->enTetes($this->jeton))->assertOk();

        $soin = collect($this->stay($this->ronde('2026-11-03', '2026-11-03'), $sejour)['cares'])->firstWhere('id', $dix->id);
        $this->assertSame('skipped', $soin['status']);
        $this->assertSame('Dr Rachel Solal', $soin['by']);
        $this->assertSame('Vomit, à redonner ce soir', $soin['reason']);
        $this->assertSame('14:05', $soin['done_time']);
    }

    public function test_un_traitement_se_prescrit_avec_son_produit_du_stock_et_ses_heures(): void
    {
        $sejour = $this->sejour();
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Cerenia 10 mg/ml']);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements", [
            'label' => 'Maropitant',
            'product_id' => $produit->id,
            'dose' => '1 mg/kg IV',
            // Médicaments à la dose (décision de Jim, 03.11.2026)
            'dose_amount' => 1,
            'product_content' => 20,
            'product_dose_unit' => 'ml',
            'times' => [600, 1320],
        ], $this->enTetes($this->jeton))->assertCreated()
            ->assertJsonPath('data.product.name', 'Cerenia 10 mg/ml')
            ->assertJsonPath('data.by', 'Dr Rachel Solal');

        // Il est 14:05 : seule la prise de 22:00 reste à donner aujourd'hui
        $aujourdhui = collect($this->stay($this->ronde('2026-11-03', '2026-11-03'), $sejour)['cares'])->where('is_medication', true);
        $this->assertSame([1320], $aujourdhui->pluck('minute')->values()->all());
        $this->assertSame($produit->id, $aujourdhui->first()['product']['id']);
    }

    public function test_un_traitement_arrete_ne_prevoit_plus_rien(): void
    {
        $sejour = $this->sejour();
        $traitement = $this->traitement($sejour);
        $this->ronde('2026-11-03', '2026-11-03');

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements/{$traitement->id}/arreter", [], $this->enTetes($this->jeton))->assertOk();

        $tao = $this->stay($this->ronde('2026-11-03', '2026-11-06'), $sejour);
        // La prise de 22:00 n'est plus prévue, ni les jours suivants ; celle de 10:00 reste lisible
        $this->assertSame([600], collect($tao['cares'])->where('sheet_day', '2026-11-03')->pluck('minute')->values()->all());
        $this->assertSame([], $tao['planned']);
    }

    // ————— La douleur selon les échelles reconnues, avec le seuil de traitement —————

    public function test_la_douleur_du_chien_se_note_sur_la_glasgow_abregee(): void
    {
        $sejour = $this->sejour();

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/douleurs", ['score' => 8], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.scale', 'cmps_sf')
            ->assertJsonPath('data.max', 24)
            ->assertJsonPath('data.threshold', 6)
            ->assertJsonPath('data.is_above', true)
            ->assertJsonPath('data.time', '14:05')
            ->assertJsonPath('data.by', 'Dr Rachel Solal');

        $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}", $this->enTetes($this->jeton))
            ->assertJsonPath('data.pain_scale.label', 'Glasgow abrégée (CMPS-SF)')
            ->assertJsonPath('data.pain_scale.threshold', 6);

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/douleurs", ['score' => 25], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    public function test_le_chat_a_les_grimaces_et_les_autres_le_colorado(): void
    {
        $chat = $this->sejour($this->patient(Espece::Chat, 'Perle'), box: 4);
        $lapin = $this->sejour($this->patient(Espece::Lapin, 'Bandit'), box: 1);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/hospitalisation/sejours/{$chat->id}/douleurs", ['score' => 3], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.scale', 'fgs')
            ->assertJsonPath('data.max', 10)
            ->assertJsonPath('data.threshold', 4)
            ->assertJsonPath('data.is_above', false);

        $this->postJson("/api/v1/hospitalisation/sejours/{$lapin->id}/douleurs", ['score' => 2], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.scale', 'colorado')
            ->assertJsonPath('data.max', 4)
            ->assertJsonPath('data.threshold', 2)
            ->assertJsonPath('data.is_above', true);

        $this->postJson("/api/v1/hospitalisation/sejours/{$lapin->id}/douleurs", ['score' => 5], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    // ————— Manger ou pas, hydratation, urine, selles, vomissements, avec l'heure et qui l'a noté —————

    public function test_une_observation_garde_l_heure_et_qui_l_a_notee(): void
    {
        $sejour = $this->sejour();

        foreach (['eats' => 'moitié', 'drinks' => 'peu', 'urine' => 'oui', 'stool' => 'molles', 'vomit' => 'une fois'] as $kind => $value) {
            $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/observations", ['kind' => $kind, 'value' => $value], $this->enTetes($this->jetonAsv))
                ->assertCreated()
                ->assertJsonPath('data.kind', $kind)
                ->assertJsonPath('data.value', $value)
                ->assertJsonPath('data.time', '14:05')
                ->assertJsonPath('data.by', 'Julie Muller');
        }

        $observations = $this->stay($this->ronde('2026-11-03', '2026-11-03'), $sejour)['observations'];
        $this->assertSame(['Mange', 'Boit', 'Urine', 'Selles', 'Vomit'], collect($observations)->pluck('label')->all());

        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/observations", ['kind' => 'dort', 'value' => 'oui'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    // ————— Changer de jour en faisant défiler la ronde de côté —————

    public function test_la_ronde_se_lit_sur_n_importe_quels_jours(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);

        // Avant l'entrée : rien
        $tao = $this->stay($this->ronde('2026-10-20', '2026-10-31'), $sejour);
        $this->assertSame([], $tao['cares']);
        $this->assertSame([], $tao['sheets']);

        $this->getJson('/api/v1/hospitalisation/ronde?from=2026-11-05&to=2026-11-01', $this->enTetes($this->jeton))->assertUnprocessable();
        $this->getJson('/api/v1/hospitalisation/ronde?from=2026-01-01&to=2026-12-31', $this->enTetes($this->jeton))->assertUnprocessable();
    }

    // ————— Les jours vont sans fin dans le futur (soins et médicaments prévus) —————

    public function test_les_jours_a_venir_montrent_les_medicaments_prevus(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);

        $tao = $this->stay($this->ronde('2026-11-09', '2026-11-10'), $sejour);

        $this->assertSame(
            [['2026-11-09', 600], ['2026-11-09', 1320], ['2026-11-10', 600], ['2026-11-10', 1320]],
            collect($tao['planned'])->map(fn (array $p) => [$p['day'], $p['minute']])->all(),
        );
        $this->assertSame('Maropitant', $tao['planned'][0]['label']);
        $this->assertSame(['planned', 'planned'], collect($tao['sheets'])->pluck('state')->all());
        // Rien n'est écrit pour les jours à venir
        $this->assertSame(0, SejourSoin::query()->whereDate('jour', '>', '2026-11-04')->count());
    }

    // ————— Le nombre de jours depuis l'entrée de l'animal —————

    public function test_le_sejour_dit_depuis_combien_de_jours_l_animal_est_la(): void
    {
        $tao = $this->sejour();
        $bandit = $this->sejour($this->patient(Espece::Lapin, 'Bandit'), '2026-11-02 16:40', 1);
        $perle = $this->sejour($this->patient(Espece::Chat, 'Perle'), '2026-11-03 09:30', 4);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/hospitalisation/sejours/{$tao->id}", $entetes)
            ->assertJsonPath('data.day_number', 3)
            ->assertJsonPath('data.since_label', 'depuis 3 jours');
        $this->getJson("/api/v1/hospitalisation/sejours/{$bandit->id}", $entetes)
            ->assertJsonPath('data.day_number', 2)
            ->assertJsonPath('data.since_label', 'depuis hier');
        $this->getJson("/api/v1/hospitalisation/sejours/{$perle->id}", $entetes)
            ->assertJsonPath('data.day_number', 1)
            ->assertJsonPath('data.since_label', 'depuis ce matin');
    }

    // ————— « depuis 3 jours » liste son séjour jour par jour ; qui a soigné chaque jour —————

    public function test_le_sejour_jour_par_jour_dit_qui_a_soigne_et_resume_la_journee(): void
    {
        $sejour = $this->sejour();
        $this->traitement($sejour);
        $this->ronde('2026-11-01', '2026-11-03');
        $hier = SejourSoin::query()->whereDate('jour', '2026-11-02')->orderBy('prevu_a')->get();
        $hier[0]->forceFill(['fait' => true, 'fait_le' => now(), 'done_by_name' => 'Julie Muller'])->save();
        $hier[1]->forceFill(['fait' => true, 'fait_le' => now(), 'done_by_name' => 'Dr Anna Meyer'])->save();
        $sejour->painScores()->create(['day' => '2026-11-02', 'minute' => 480, 'scale' => 'cmps_sf', 'score' => 7, 'max' => 24, 'threshold' => 6, 'by_name' => 'Dr Rachel Solal']);
        $sejour->painScores()->create(['day' => '2026-11-02', 'minute' => 1200, 'scale' => 'cmps_sf', 'score' => 4, 'max' => 24, 'threshold' => 6, 'by_name' => 'Dr Anna Meyer']);
        $sejour->observations()->create(['day' => '2026-11-02', 'minute' => 720, 'kind' => 'eats', 'value' => 'moitié', 'by_name' => 'Julie Muller']);

        $jours = $this->getJson("/api/v1/hospitalisation/sejours/{$sejour->id}/jours", $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data');

        // Du plus récent au plus ancien, demain en tête
        $this->assertSame(['2026-11-04', '2026-11-03', '2026-11-02', '2026-11-01'], collect($jours['days'])->pluck('day')->all());
        $this->assertSame([4, 3, 2, 1], collect($jours['days'])->pluck('number')->all());
        $this->assertSame(['planned', 'open', 'closed', 'closed'], collect($jours['days'])->pluck('state')->all());

        $lundi = $jours['days'][2];
        // Dans l'ordre de la journée : la douleur de 08:00, le médicament de 10:00, la douleur de 20:00
        $this->assertSame(['Dr Rachel Solal', 'Julie Muller', 'Dr Anna Meyer'], $lundi['carers']);
        $this->assertSame('Douleur 7 puis 4 sur 24 · 2 médicaments sur 2 · mange : moitié', $lundi['summary']);
        $this->assertSame('2 médicaments prévus', $jours['days'][0]['summary']);

        $this->assertSame('09:15', $jours['admission']['time']);
    }
}
