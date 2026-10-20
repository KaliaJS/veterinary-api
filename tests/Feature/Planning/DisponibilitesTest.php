<?php

namespace Tests\Feature\Planning;

use App\Auth\SousContexteClinique;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Créneaux libres et zones fermées calculés depuis les horaires de la
 * clinique : jour fermé, samedi réduit, pause occupée, tampons et
 * annulés non occupants, aujourd'hui borné à l'heure courante, lignes
 * sous absence bloquante écartées.
 */
class DisponibilitesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    private CarbonImmutable $lundi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->lundi = CarbonImmutable::today()->next('Monday');

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_les_bornes_du_jour_viennent_des_horaires_de_la_clinique(): void
    {
        $this->getJson('/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermee', false)
            ->assertJsonPath('data.ouverture', '08:00')
            ->assertJsonPath('data.fermeture', '19:30')
            ->assertJsonPath('data.pause.debut', '12:30')
            ->assertJsonPath('data.pause.fin', '14:00');
    }

    public function test_le_dimanche_ferme_ne_rend_aucun_creneau(): void
    {
        $dimanche = CarbonImmutable::today()->next('Sunday');

        $this->getJson('/api/v1/planning/disponibilites?date='.$dimanche->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermee', true)
            ->assertJsonPath('data.creneaux', []);
    }

    public function test_le_samedi_suit_la_fermeture_reduite(): void
    {
        $samedi = CarbonImmutable::today()->next('Saturday');

        $this->getJson('/api/v1/planning/disponibilites?date='.$samedi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermee', false)
            ->assertJsonPath('data.fermeture', '15:00');
    }

    public function test_les_creneaux_evitent_les_rendez_vous_et_la_pause_mais_ni_les_tampons_ni_les_annules(): void
    {
        // Les horaires sont des heures LOCALES : tout se pose et
        // s'attend en heure de Zurich, convertie en UTC (le stockage
        // des rendez-vous).
        $local = fn (int $minutes): CarbonImmutable => CarbonImmutable::parse($this->lundi->toDateString(), 'Europe/Zurich')
            ->setTime(intdiv($minutes, 60), $minutes % 60)
            ->utc();

        $rendezVous = fn (int $debutMin, int $finMin) => [
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $local($debutMin),
            'ends_at' => $local($finMin),
        ];

        RendezVous::factory()->create($rendezVous(540, 600)); // 09:00 – 10:00 locales, occupe
        RendezVous::factory()->annule()->create($rendezVous(600, 660)); // annulé : libre
        RendezVous::factory()->tampon()->create($rendezVous(900, 960)); // tampon : libre

        $reponse = $this->getJson(
            '/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(),
            $this->enTetes($this->jeton),
        )->assertOk();

        $creneaux = collect($reponse->json('data.creneaux'))
            ->map(fn (array $creneau): array => [$creneau['debut'], $creneau['fin']])
            ->all();

        $iso = fn (int $minutes): string => $local($minutes)->toISOString();

        $this->assertSame([
            [$iso(480), $iso(540)],   // 08:00 – 09:00 locales
            [$iso(600), $iso(750)],   // 10:00 – 12:30 (pause ensuite)
            [$iso(840), $iso(1170)],  // 14:00 – 19:30
        ], $creneaux);
    }

    public function test_les_horaires_sont_des_heures_locales_du_fuseau_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'America/New_York']);

        $reponse = $this->getJson(
            '/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(),
            $this->enTetes($this->jeton),
        )->assertOk()
            ->assertJsonPath('data.fuseau_horaire', 'America/New_York')
            ->assertJsonPath('data.ouverture', '08:00');

        // « Ouvre à 08:00 » vaut en heure de New York — le créneau est
        // rendu à l'instant UTC correspondant, pas à 08:00Z.
        $this->assertSame(
            CarbonImmutable::parse($this->lundi->toDateString().' 08:00', 'America/New_York')->utc()->toISOString(),
            $reponse->json('data.creneaux.0.debut'),
        );
    }

    public function test_les_creneaux_d_aujourd_hui_commencent_a_l_heure_courante(): void
    {
        // Le jeton du setUp expirerait (24 h) : on en émet un après le
        // voyage dans le temps.
        $this->travelTo($this->lundi->setTime(10, 7));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $reponse = $this->getJson(
            '/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(),
            $this->enTetes($jeton),
        )->assertOk();

        $this->assertSame(
            $this->lundi->setTime(10, 7)->toISOString(),
            $reponse->json('data.creneaux.0.debut'),
        );
    }

    public function test_une_ligne_sous_absence_bloquante_est_ecartee_mais_pas_la_garde(): void
    {
        $ligneAbsente = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $ligneDeGarde = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        Absence::factory()->create([
            'employe_id' => $ligneAbsente->employe_id,
            'debut_le' => $this->lundi,
            'fin_le' => $this->lundi,
        ]);
        Absence::factory()->garde()->create([
            'employe_id' => $ligneDeGarde->employe_id,
            'debut_le' => $this->lundi,
            'fin_le' => $this->lundi,
        ]);

        $reponse = $this->getJson(
            '/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(),
            $this->enTetes($this->jeton),
        )->assertOk();

        $lignes = array_unique(array_column($reponse->json('data.creneaux'), 'ligne_planning_id'));

        $this->assertEqualsCanonicalizing([$this->ligne->id, $ligneDeGarde->id], $lignes);
    }

    public function test_le_prochain_creneau_cree_le_rendez_vous_au_premier_trou_libre(): void
    {
        $this->travelTo($this->lundi->setTime(8, 0));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->lundi->setTime(8, 0),
            'ends_at' => $this->lundi->setTime(9, 0),
        ]);

        $reponse = $this->postJson('/api/v1/rendez-vous/prochain-creneau', [
            'patient_name' => 'Filou',
            'reason' => 'Suivi',
        ], $this->enTetes($jeton))->assertCreated();

        $rendezVous = RendezVous::withoutGlobalScopes()->findOrFail($reponse->json('data.id'));

        $this->assertTrue($rendezVous->starts_at->equalTo($this->lundi->setTime(9, 0)));
        $this->assertTrue($rendezVous->ends_at->equalTo($this->lundi->setTime(9, 20)));
        $this->assertSame('création', $rendezVous->audits()->sole()->champ);
    }

    public function test_le_prochain_creneau_respecte_la_duree_demandee(): void
    {
        $this->travelTo($this->lundi->setTime(8, 0));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        // Seul trou : 08:00 – 08:20.
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->lundi->setTime(8, 20),
            'ends_at' => $this->lundi->setTime(19, 30),
        ]);

        $entetes = $this->enTetes($jeton);

        $this->postJson('/api/v1/rendez-vous/prochain-creneau', ['duree_minutes' => 30], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->postJson('/api/v1/rendez-vous/prochain-creneau', ['duree_minutes' => 20], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.starts_at', $this->lundi->setTime(8, 0)->toJSON());
    }

    public function test_le_prochain_creneau_un_jour_ferme_repond_422(): void
    {
        $dimanche = CarbonImmutable::today()->next('Sunday');

        $this->postJson('/api/v1/rendez-vous/prochain-creneau', [
            'date' => $dimanche->toDateString(),
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_la_creation_sur_une_colonne_bloquee_par_absence_est_refusee_en_422(): void
    {
        Absence::factory()->create([
            'employe_id' => $this->ligne->employe_id,
            'debut_le' => $this->lundi,
            'fin_le' => $this->lundi,
        ]);

        $this->postJson('/api/v1/rendez-vous', [
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->lundi->setTime(10, 0)->toISOString(),
            'duree_minutes' => 20,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ligne_planning_id');
    }

    public function test_la_semaine_calculee_d_un_coup_rend_les_memes_creneaux_que_jour_par_jour(): void
    {
        $ligneAbsente = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        Absence::factory()->create([
            'employe_id' => $ligneAbsente->employe_id,
            'debut_le' => $this->lundi->addDay(),
            'fin_le' => $this->lundi->addDays(2),
        ]);

        // Un rendez-vous par jour ouvré, à une heure différente, plus un annulé.
        foreach (range(0, 4) as $i) {
            $debut = CarbonImmutable::parse($this->lundi->addDays($i)->toDateString(), 'Europe/Zurich')->setTime(9 + $i, 0)->utc();
            RendezVous::factory()->create([
                'clinique_id' => $this->clinique->id,
                'ligne_planning_id' => $this->ligne->id,
                'starts_at' => $debut,
                'ends_at' => $debut->addMinutes(45),
            ]);
        }
        RendezVous::factory()->annule()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $ligneAbsente->id,
            'starts_at' => $this->lundi->setTime(8, 0),
            'ends_at' => $this->lundi->setTime(9, 0),
        ]);

        [$semaine, $jourParJour] = app(SousContexteClinique::class)->pour($this->clinique, function (): array {
            $calculateur = app(CalculateurCreneaux::class);

            return [
                $calculateur->creneauxDesJours($this->lundi, 7, 20),
                array_map(fn (int $i) => $calculateur->creneauxLibres($this->lundi->addDays($i), 20), range(0, 6)),
            ];
        });

        $this->assertCount(7, $semaine);
        $this->assertEquals($jourParJour, $semaine);
        $this->assertEmpty($semaine[6]); // dimanche fermé
        $this->assertNotContains($ligneAbsente->id, array_column($semaine[1]->all(), 'ligne_planning_id'));
        $this->assertContains($ligneAbsente->id, array_column($semaine[0]->all(), 'ligne_planning_id'));
    }
}
