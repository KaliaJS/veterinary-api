<?php

namespace Tests\Feature\Patients;

use App\Enums\StatutIntervention;
use App\Enums\StatutSejour;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\Sejour;
use App\Models\StaySheet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Case « Les interventions et les hospitalisations de l'animal sont listées
 * dans Soins et interventions, sans rien écrire en base » (notes/todo.md,
 * dépôt web, 06.11.2026) : une lecture par animal, de toutes les cliniques
 * de son organisation, jamais d'une autre.
 */
class PatientCareTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private Patient $bourbon;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'fuseau_horaire' => 'Europe/Zurich']);
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Les Tilleuls']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->bourbon = Patient::factory()->for($client)->create(['nom' => 'Bourbon', 'espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function soins(?string $jeton = null): array
    {
        return $this->getJson("/api/v1/patients/{$this->bourbon->id}/soins", $this->enTetes($jeton ?? $this->jeton))->assertOk()->json('data');
    }

    public function test_les_interventions_et_les_sejours_de_l_animal_sont_lus_du_plus_recent_au_plus_ancien(): void
    {
        $chirurgien = Employe::factory()->dans($this->clinique)->create(['nom' => 'Dr Paul Keller']);
        $castration = Intervention::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'chirurgien_id' => $chirurgien->id,
            'procedure' => 'Castration', 'prevu_le' => '2026-11-10 07:30:00', 'statut' => StatutIntervention::Prevu,
        ]);
        $detartrage = Intervention::factory()->create([
            'clinique_id' => $this->soeur->id, 'patient_id' => $this->bourbon->id, 'procedure' => 'Détartrage',
            'prevu_le' => '2026-03-02 08:00:00', 'statut' => StatutIntervention::Fini, 'consentement_signe_le' => '2026-03-01 09:00:00',
        ]);
        $sejour = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'motif' => 'Gastro-entérite',
            'box' => 3, 'statut' => StatutSejour::Surveillance, 'admis_le' => '2026-11-06 16:00:00', 'veterinaire_id' => $chirurgien->id,
        ]);
        $ancien = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'motif' => 'Surveillance post-opératoire',
            'box' => 1, 'admis_le' => '2025-05-02 09:00:00', 'sorti_le' => '2025-05-04 11:00:00',
        ]);

        $lignes = $this->soins();

        $this->assertSame([['intervention', $castration->id], ['sejour', $sejour->id], ['intervention', $detartrage->id], ['sejour', $ancien->id]], array_map(fn (array $x): array => [$x['type'], $x['id']], $lignes));
        $this->assertSame('Castration', $lignes[0]['titre']);
        $this->assertSame('prevu', $lignes[0]['statut']);
        $this->assertSame('Dr Paul Keller', $lignes[0]['chirurgien']);
        $this->assertSame('2026-11-10', $lignes[0]['jour']);
        $this->assertFalse($lignes[0]['consentement_recueilli']);
        $this->assertSame($this->clinique->id, $lignes[0]['clinique_id']);
        // Le séjour en cours : son box, son vétérinaire, ses jours comptés par le serveur
        $this->assertSame('Gastro-entérite', $lignes[1]['titre']);
        $this->assertTrue($lignes[1]['en_cours']);
        $this->assertSame(3, $lignes[1]['box']);
        $this->assertSame('surv', $lignes[1]['statut']);
        $this->assertSame('Dr Paul Keller', $lignes[1]['veterinaire']);
        $this->assertSame(2, $lignes[1]['jours']);
        // L'intervention d'une clinique sœur : lue, avec sa clinique
        $this->assertSame($this->soeur->id, $lignes[2]['clinique_id']);
        $this->assertSame('Les Tilleuls', $lignes[2]['clinique']);
        $this->assertTrue($lignes[2]['consentement_recueilli']);
        // Le séjour fini : sorti, trois jours
        $this->assertFalse($lignes[3]['en_cours']);
        $this->assertSame(3, $lignes[3]['jours']);
    }

    public function test_la_lecture_n_ecrit_rien_en_base(): void
    {
        // Un séjour en cours : `GET /sejours?patient_id=` en ouvrirait la feuille du jour
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'admis_le' => now()->subDay()]);
        Intervention::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id]);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            // Le jeton note son dernier usage (Sanctum) : ce n'est pas le dossier
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) && ! str_contains($query->sql, 'personal_access_tokens')) {
                $writes[] = $query->sql;
            }
        });
        $this->assertCount(2, $this->soins());

        $this->assertSame([], $writes);
        $this->assertSame(0, StaySheet::query()->withoutGlobalScopes()->count());
    }

    public function test_une_autre_organisation_ne_lit_ni_l_animal_ni_ses_soins(): void
    {
        $autre = Clinique::factory()->create();
        // Une intervention d'une clinique d'ailleurs qui viserait cet animal (base abîmée) : jamais servie
        Intervention::factory()->create(['clinique_id' => $autre->id, 'patient_id' => $this->bourbon->id, 'procedure' => 'Ailleurs']);
        Sejour::factory()->create(['clinique_id' => $autre->id, 'patient_id' => $this->bourbon->id, 'motif' => 'Ailleurs']);
        $this->assertSame([], $this->soins());

        ['jeton' => $jetonAilleurs] = $this->employeAvecJeton($autre);
        $this->getJson("/api/v1/patients/{$this->bourbon->id}/soins", $this->enTetes($jetonAilleurs))->assertNotFound();
    }

    public function test_sans_le_droit_patients_rien_n_est_lu(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $this->getJson("/api/v1/patients/{$this->bourbon->id}/soins", $this->enTetes($jeton))->assertForbidden();
    }
}
