<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Photo d'une clinique, posée par l'administrateur de l'organisation :
 * servie publiquement (nom imprévisible) et montrée dans l'annuaire à
 * la place de la vue aérienne.
 */
class PhotoCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->clinique = Clinique::factory()->create();
        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $employe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    private function entetesJson(): array
    {
        return $this->enTetes($this->jeton) + ['Accept' => 'application/json'];
    }

    public function test_l_administrateur_pose_remplace_et_retire_la_photo_d_une_clinique_soeur(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $url = $this->post("/api/v1/organisation/cliniques/{$soeur->id}/photo", ['photo' => UploadedFile::fake()->image('facade.jpg', 1200, 800)], $this->entetesJson())
            ->assertOk()
            ->json('data.photo_url');

        $this->assertNotNull($url);
        $premier = $soeur->refresh()->photo_chemin;
        Storage::disk('local')->assertExists($premier);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        // Remplacée : l'ancien fichier part
        $this->post("/api/v1/organisation/cliniques/{$soeur->id}/photo", ['photo' => UploadedFile::fake()->image('accueil.png', 800, 600)], $this->entetesJson())->assertOk();
        Storage::disk('local')->assertMissing($premier);

        $this->deleteJson("/api/v1/organisation/cliniques/{$soeur->id}/photo", [], $this->entetesJson())
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);
        $this->assertNull($soeur->refresh()->photo_chemin);
    }

    public function test_jamais_la_clinique_d_une_autre_organisation_ni_un_fichier_qui_n_est_pas_une_image(): void
    {
        $etrangere = Clinique::factory()->create();

        $this->post("/api/v1/organisation/cliniques/{$etrangere->id}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')], $this->entetesJson())->assertNotFound();

        $this->post("/api/v1/organisation/cliniques/{$this->clinique->id}/photo", ['photo' => UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf')], $this->entetesJson())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_sans_le_droit_organisation_la_photo_reste_fermee(): void
    {
        ['jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique);

        $this->post("/api/v1/organisation/cliniques/{$this->clinique->id}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')], $this->enTetes($jetonVeterinaire) + ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_l_annuaire_des_proprietaires_montre_la_photo(): void
    {
        $this->clinique->update(['latitude' => 46.5, 'longitude' => 6.6]);
        $this->post("/api/v1/organisation/cliniques/{$this->clinique->id}/photo", ['photo' => UploadedFile::fake()->image('facade.jpg')], $this->entetesJson())->assertOk();

        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();

        $fiche = collect($this->getJson('/api/v1/espace/cliniques', $this->enTetes($jetonProprietaire))->assertOk()->json('data'))
            ->firstWhere('id', $this->clinique->id);

        $this->assertStringContainsString('/photos-cliniques/', $fiche['photo_url']);
    }
}
