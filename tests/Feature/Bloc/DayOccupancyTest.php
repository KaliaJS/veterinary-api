<?php

namespace Tests\Feature\Bloc;

use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Salle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La bande des jours (demande de Jim, 02.11.2026) : pour chaque jour, le
 * matin (avant midi), midi (12 h à 14 h) et l'après-midi, à l'heure de la
 * clinique, vrais quand le bloc ou le planning y est pris.
 */
class DayOccupancyTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function intervention(string $debutZurich, int $minutes, ?Clinique $clinique = null): Intervention
    {
        $clinique ??= $this->clinique;
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $clinique->id]);
        $patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]))
            ->create();

        return Intervention::factory()->create([
            'clinique_id' => $clinique->id,
            'patient_id' => $patient->id,
            'salle_id' => $salle->id,
            'salle_nom' => $salle->nom,
            'prevu_le' => CarbonImmutable::parse($debutZurich, 'Europe/Zurich')->utc(),
            'duree_minutes' => $minutes,
        ]);
    }

    public function test_le_bloc_dit_matin_midi_et_apres_midi_a_l_heure_de_la_clinique(): void
    {
        $this->intervention('2026-11-02 08:30', 60);   // matin
        $this->intervention('2026-11-03 11:30', 60);   // déborde sur midi
        $this->intervention('2026-11-04 14:40', 60);   // après-midi
        $this->intervention('2026-11-05 23:30', 60);   // finit le lendemain matin

        $this->getJson('/api/v1/bloc/occupation?du=2026-11-01&au=2026-11-06', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => [
                '2026-11-01' => [false, false, false],
                '2026-11-02' => [true, false, false],
                '2026-11-03' => [true, true, false],
                '2026-11-04' => [false, false, true],
                '2026-11-05' => [false, false, true],
                '2026-11-06' => [true, false, false],
            ]]);
    }

    public function test_le_bloc_d_une_autre_clinique_ne_compte_pas(): void
    {
        $this->intervention('2026-11-02 08:30', 60, Clinique::factory()->create());

        $this->getJson('/api/v1/bloc/occupation?du=2026-11-02&au=2026-11-02', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['2026-11-02' => [false, false, false]]]);
    }

    public function test_la_periode_est_bornee(): void
    {
        $this->getJson('/api/v1/bloc/occupation?du=2026-11-02&au=2027-03-01', $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('au');
        $this->getJson('/api/v1/bloc/occupation?du=2026-11-05&au=2026-11-02', $this->enTetes($this->jeton))
            ->assertUnprocessable();
    }

    public function test_le_planning_compte_les_rendez_vous_non_annules(): void
    {
        $rdv = fn (string $debut, string $fin, StatutRendezVous $statut) => RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'starts_at' => CarbonImmutable::parse($debut, 'Europe/Zurich')->utc(),
            'ends_at' => CarbonImmutable::parse($fin, 'Europe/Zurich')->utc(),
            'status' => $statut,
        ]);
        $rdv('2026-11-02 12:15', '2026-11-02 12:35', StatutRendezVous::Prevu);
        $rdv('2026-11-02 16:00', '2026-11-02 16:20', StatutRendezVous::Annule);

        $this->getJson('/api/v1/planning/occupation?du=2026-11-02&au=2026-11-02', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['2026-11-02' => [false, true, false]]]);
    }
}
