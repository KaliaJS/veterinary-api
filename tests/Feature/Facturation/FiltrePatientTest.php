<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'onglet « Factures et devis » du dossier patient (05.11.2026) lit
 * `?patient_id=` : seules les pièces de cet animal, jamais celles d'un
 * autre animal ni d'une autre organisation (vérification du 06.11.2026).
 */
class FiltrePatientTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $bagheera;

    private Patient $jazz;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);
        $this->bagheera = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id]);
        $this->jazz = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_les_factures_d_un_seul_animal(): void
    {
        $siennes = Facture::factory()->count(2)->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bagheera->id]);
        Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->jazz->id]);
        Facture::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => null]);

        $ids = collect($this->getJson("/api/v1/facturation/factures?patient_id={$this->bagheera->id}&per_page=100", $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame($siennes->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_les_devis_d_un_seul_animal(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $sien = Devis::factory()->create(['organisation_id' => $organisation, 'patient_id' => $this->bagheera->id]);
        Devis::factory()->create(['organisation_id' => $organisation, 'patient_id' => $this->jazz->id]);

        $ids = collect($this->getJson("/api/v1/facturation/devis?patient_id={$this->bagheera->id}&per_page=100", $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertSame([$sien->id], $ids);
    }

    public function test_un_animal_d_une_autre_organisation_ne_sert_rien(): void
    {
        $ailleurs = Clinique::factory()->create();
        $etranger = Patient::factory()->create([
            'organisation_id' => $ailleurs->societe->organisation_id,
            'client_id' => Client::factory()->create(['organisation_id' => $ailleurs->societe->organisation_id])->id,
        ]);
        Facture::factory()->create(['clinique_id' => $ailleurs->id, 'patient_id' => $etranger->id]);
        Devis::factory()->create(['organisation_id' => $ailleurs->societe->organisation_id, 'patient_id' => $etranger->id]);

        $this->getJson("/api/v1/facturation/factures?patient_id={$etranger->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/facturation/devis?patient_id={$etranger->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
