<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use App\Services\Photos\PhotosCliniques;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Photo d'une clinique, posée par l'administrateur de l'organisation :
 * versions WebP et aperçu flou tirés à l'envoi, servies publiquement
 * (noms imprévisibles). Sans photo, adresse complète et position, la
 * clinique ne paraît pas dans l'annuaire, et l'écran Organisation le dit.
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

        $photo = $this->post("/api/v1/organisation/cliniques/{$soeur->id}/photo", ['photo' => UploadedFile::fake()->image('facade.jpg', 1600, 1000)], $this->entetesJson())
            ->assertOk()
            ->json('data.photo');

        // La grande version WebP, les largeurs pour srcset, l'aperçu flou en data URI
        $this->assertStringEndsWith('-1200.webp', $photo['url']);
        $this->assertStringContainsString('-480.webp 480w', $photo['srcset']);
        $this->assertStringStartsWith('data:image/webp;base64,', $photo['apercu']);
        $this->assertLessThan(1500, strlen($photo['apercu']));

        $premier = $soeur->refresh()->photo_chemin;
        Storage::disk('local')->assertExists([$premier, PhotosCliniques::version($premier, 480), PhotosCliniques::version($premier, 1200)]);
        $this->get($photo['url'])->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame(1200, getimagesizefromstring(Storage::disk('local')->get(PhotosCliniques::version($premier, 1200)))[0]);

        // Remplacée : l'ancienne et ses versions partent
        $this->post("/api/v1/organisation/cliniques/{$soeur->id}/photo", ['photo' => UploadedFile::fake()->image('accueil.png', 800, 600)], $this->entetesJson())->assertOk();
        Storage::disk('local')->assertMissing([$premier, PhotosCliniques::version($premier, 480)]);

        $nouvelle = $soeur->refresh()->photo_chemin;
        $this->deleteJson("/api/v1/organisation/cliniques/{$soeur->id}/photo", [], $this->entetesJson())
            ->assertOk()
            ->assertJsonPath('data.photo', null);
        $this->assertNull($soeur->refresh()->photo_chemin);
        Storage::disk('local')->assertMissing([$nouvelle, PhotosCliniques::version($nouvelle, 1200)]);
    }

    public function test_l_ecran_organisation_dit_ce_qui_manque_pour_paraitre_dans_l_annuaire(): void
    {
        $this->clinique->update(['rue' => 'Rue du Lac 1', 'npa' => '1000', 'ville' => 'Lausanne', 'latitude' => null, 'longitude' => null]);

        $fiche = fn () => collect($this->getJson('/api/v1/organisation/cliniques', $this->entetesJson())->assertOk()->json('data'))->firstWhere('id', $this->clinique->id);

        $this->assertSame(['est_visible' => false, 'manques' => ['photo', 'position']], $fiche()['annuaire']);

        $this->clinique->update(['latitude' => 46.52, 'longitude' => 6.63]);
        $this->post("/api/v1/organisation/cliniques/{$this->clinique->id}/photo", ['photo' => UploadedFile::fake()->image('facade.jpg')], $this->entetesJson())->assertOk();

        $this->assertSame(['est_visible' => true, 'manques' => []], $fiche()['annuaire']);
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

    public function test_l_annuaire_ne_montre_que_les_cliniques_avec_photo_adresse_et_position(): void
    {
        $this->clinique->update(['rue' => 'Rue du Lac 1', 'npa' => '1000', 'ville' => 'Lausanne', 'latitude' => 46.5, 'longitude' => 6.6]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $annuaire = fn () => collect($this->getJson('/api/v1/espace/cliniques?cliniques='.$this->clinique->id, $this->enTetes($jetonProprietaire))->assertOk()->json('data'))
            ->firstWhere('id', $this->clinique->id);

        // Sans photo : hors de l'annuaire, mais toujours servie (créneaux du site de la clinique)
        $this->assertFalse($annuaire()['dans_annuaire']);

        $this->post("/api/v1/organisation/cliniques/{$this->clinique->id}/photo", ['photo' => UploadedFile::fake()->image('facade.jpg')], $this->entetesJson())->assertOk();

        $fiche = $annuaire();
        $this->assertTrue($fiche['dans_annuaire']);
        $this->assertStringContainsString('/photos-cliniques/', $fiche['photo']['url']);
        $this->assertStringStartsWith('data:image/webp;base64,', $fiche['photo']['apercu']);

        // Sans position sur la carte : de nouveau hors de l'annuaire
        $this->clinique->update(['latitude' => null]);
        $this->assertFalse($annuaire()['dans_annuaire']);
    }
}
