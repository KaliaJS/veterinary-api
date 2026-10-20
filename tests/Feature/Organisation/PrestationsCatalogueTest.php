<?php

namespace Tests\Feature\Organisation;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Prestation;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/** Catalogue d'actes géré par les administrateurs : ajouter, changer un prix, supprimer un acte ajouté. */
class PrestationsCatalogueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private array $entetes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);
        $this->entetes = $this->enTetes($jeton);
    }

    private function acte(string $code): Prestation
    {
        return Prestation::withoutGlobalScopes()->where('organisation_id', $this->clinique->societe->organisation_id)->where('code', $code)->firstOrFail();
    }

    public function test_un_acte_s_ajoute_avec_un_code_unique_puis_se_supprime(): void
    {
        $premier = $this->postJson('/api/v1/organisation/prestations', ['nom' => 'Pose de puce électronique', 'prix' => 65], $this->entetes)
            ->assertCreated()
            ->assertJsonPath('data.code', 'pose_de_puce_ele')
            ->assertJsonPath('data.prix', 65)
            ->assertJsonPath('data.systeme', false)
            ->json('data.id');

        app(ContexteClinique::class)->oublier();
        $this->postJson('/api/v1/organisation/prestations', ['nom' => 'Pose de puce électronique', 'prix' => 70], $this->entetes)
            ->assertCreated()
            ->assertJsonPath('data.code', 'pose_de_puce_ele_2');

        app(ContexteClinique::class)->oublier();
        $this->deleteJson("/api/v1/organisation/prestations/$premier", [], $this->entetes)->assertNoContent();
        $this->assertDatabaseMissing('prestations', ['id' => $premier]);
    }

    public function test_un_acte_de_depart_change_de_prix_mais_ne_se_supprime_pas(): void
    {
        $consultation = $this->acte('consult');
        $this->travel(1)->minutes();

        $this->patchJson("/api/v1/organisation/prestations/{$consultation->id}", ['nom' => 'Consultation', 'prix' => 92.5], $this->entetes)
            ->assertOk()
            ->assertJsonPath('data.prix', 92.5)
            ->assertJsonPath('data.systeme', true);

        app(ContexteClinique::class)->oublier();
        $this->deleteJson("/api/v1/organisation/prestations/{$consultation->id}", [], $this->entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prestation');

        app(ContexteClinique::class)->oublier();
        $this->getJson('/api/v1/organisation/premiers-pas', $this->entetes)
            ->assertJsonPath('data.etapes.3', ['cle' => 'prestations', 'fait' => true, 'confirmable' => true]);
    }

    public function test_un_prix_doit_etre_un_montant_positif(): void
    {
        $this->postJson('/api/v1/organisation/prestations', ['nom' => 'Acte', 'prix' => -4], $this->entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prix');
    }

    public function test_le_catalogue_d_une_autre_organisation_est_introuvable(): void
    {
        $ailleurs = Prestation::withoutGlobalScopes()->where('organisation_id', '!=', $this->clinique->societe->organisation_id)->first()
            ?? Prestation::withoutGlobalScopes()->where('organisation_id', Clinique::factory()->create()->societe->organisation_id)->firstOrFail();

        app(ContexteClinique::class)->oublier();
        $this->patchJson("/api/v1/organisation/prestations/{$ailleurs->id}", ['nom' => 'X', 'prix' => 1], $this->entetes)->assertNotFound();
    }

    public function test_reserve_aux_administrateurs(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->postJson('/api/v1/organisation/prestations', ['nom' => 'Acte', 'prix' => 10], $this->enTetes($jeton))->assertForbidden();
    }
}
