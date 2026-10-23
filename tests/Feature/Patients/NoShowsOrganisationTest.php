<?php

namespace Tests\Feature\Patients;

use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les absences (no-shows) d'un client ne se comptent que dans son
 * organisation (23.10.2026) : un rendez-vous d'une autre organisation
 * qui porterait sa fiche n'apparaît jamais dans son compteur.
 */
class NoShowsOrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_une_absence_chez_une_autre_organisation_ne_compte_pas(): void
    {
        $clinique = Clinique::factory()->create();
        $etrangere = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        $patient = Patient::factory()->create(['client_id' => $client->id]);

        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'status' => StatutRendezVous::Noshow]);
        // Donnée d'avant le correctif : la fiche du client sur un rendez-vous d'une autre organisation
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $etrangere->id, 'status' => StatutRendezVous::Noshow]);

        $this->getJson("/api/v1/clients/{$client->id}", $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.no_shows', 1);
    }
}
