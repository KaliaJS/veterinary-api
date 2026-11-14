<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le solde dû d'un propriétaire et la dette d'un animal relèvent de la
 * facturation (audit C10, Jim, 14.11.2026) : sans ce droit, rien n'en sort,
 * ni le montant, ni l'alerte « solde », ni sa trace dans le filtre, le tri
 * et le compte des alertes. L'administrateur donne Facturation à une
 * assistante qui facture.
 */
class SoldeDuDroitTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private Patient $zorro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation]);
        // Zorro n'a pour toute alerte que sa dette ; Alpha n'en a aucune
        Patient::factory()->for($this->client)->create(['organisation_id' => $organisation, 'nom' => 'Alpha', 'est_agressif' => false, 'a_allergies' => false]);
        $this->zorro = Patient::factory()->for($this->client)->create(['organisation_id' => $organisation, 'nom' => 'Zorro', 'est_agressif' => false, 'a_allergies' => false]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $this->client->id, 'patient_id' => $this->zorro->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 250]);
    }

    public function test_sans_facturation_le_solde_du_d_un_proprietaire_ne_sort_pas(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $this->assertArrayNotHasKey('solde_du', $this->getJson('/api/v1/clients', $this->enTetes($jeton))->assertOk()->json('data.0'));
        $this->assertArrayNotHasKey('solde_du', $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($jeton))->assertOk()->json('data'));
        $this->assertArrayNotHasKey('solde_du', $this->patchJson("/api/v1/clients/{$this->client->id}", ['note' => 'Préfère le matin'], $this->enTetes($jeton))->assertOk()->json('data'));

        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($jetonAsv))->assertOk()->assertJsonPath('data.solde_du', 250);
        $this->getJson('/api/v1/clients', $this->enTetes($jetonAsv))->assertOk()->assertJsonPath('data.0.solde_du', 250);
    }

    public function test_sans_facturation_la_dette_d_un_animal_et_son_alerte_ne_sortent_pas(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $fiche = $this->getJson("/api/v1/patients/{$this->zorro->id}", $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertArrayNotHasKey('dette', $fiche);
        $this->assertNotContains('solde', $fiche['alertes']);
        $liste = collect($this->getJson('/api/v1/patients', $this->enTetes($jeton))->assertOk()->json('data'))->keyBy('nom');
        $this->assertArrayNotHasKey('dette', $liste['Zorro']);
        $this->assertNotContains('solde', $liste['Zorro']['alertes']);
        $client = $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertArrayNotHasKey('dette', collect($client['patients'])->firstWhere('nom', 'Zorro'));

        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->getJson("/api/v1/patients/{$this->zorro->id}", $this->enTetes($jetonAsv))->assertOk()
            ->assertJsonPath('data.dette', 250)
            ->assertJsonPath('data.alertes', ['solde']);
    }

    public function test_sans_facturation_le_filtre_le_tri_et_le_compte_des_alertes_ignorent_la_dette(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $filtre = $this->getJson('/api/v1/patients?filtre=alertes', $this->enTetes($jeton))->assertOk();
        $this->assertSame([], array_column($filtre->json('data'), 'nom'));
        $this->assertSame(0, $filtre->json('meta.facettes.alertes'));
        $this->assertSame(['Alpha', 'Zorro'], array_column($this->getJson('/api/v1/patients?tri=alertes', $this->enTetes($jeton))->assertOk()->json('data'), 'nom'));

        ['jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $filtre = $this->getJson('/api/v1/patients?filtre=alertes', $this->enTetes($jetonAsv))->assertOk();
        $this->assertSame(['Zorro'], array_column($filtre->json('data'), 'nom'));
        $this->assertSame(1, $filtre->json('meta.facettes.alertes'));
        $this->assertSame(['Zorro', 'Alpha'], array_column($this->getJson('/api/v1/patients?tri=alertes', $this->enTetes($jetonAsv))->assertOk()->json('data'), 'nom'));
    }
}
