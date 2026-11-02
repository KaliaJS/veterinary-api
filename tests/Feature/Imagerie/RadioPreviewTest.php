<?php

namespace Tests\Feature\Imagerie;

use App\Models\ClicheDicom;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FabriqueDicom;
use Tests\TestCase;

/**
 * L'aperçu d'une radio (choix de Jim, 02.11.2026) : une image légère faite
 * par l'app, gardée à côté de l'original intact ; posée une seule fois,
 * relue par toute l'équipe du dossier, jamais par une autre organisation.
 */
class RadioPreviewTest extends TestCase
{
    use EmetDesJetonsApi;
    use FabriqueDicom;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private ExamenImagerie $examen;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->clinique = Clinique::factory()->create();
        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create(['espece' => 'CH']);
        $this->examen = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'prescripteur_id' => $employe->id, 'realise_le' => now()]);
    }

    private function cliche(): ClicheDicom
    {
        $this->post("/api/v1/imagerie/examens/{$this->examen->id}/cliches/fichier", ['fichier' => UploadedFile::fake()->createWithContent('thorax.dcm', $this->dicom(['lignes' => 8, 'colonnes' => 10]))], $this->enTetes($this->jeton) + ['Accept' => 'application/json'])->assertCreated();

        return ClicheDicom::query()->latest('id')->firstOrFail();
    }

    private function url(ClicheDicom $cliche): string
    {
        return "/api/v1/patients/{$this->patient->id}/imagerie/{$this->examen->id}/cliches/{$cliche->id}/apercu";
    }

    private function poser(ClicheDicom $cliche, UploadedFile $image, ?string $jeton = null): TestResponse
    {
        return $this->post($this->url($cliche), ['image' => $image], $this->enTetes($jeton ?? $this->jeton) + ['Accept' => 'application/json']);
    }

    public function test_l_aperçu_se_pose_une_fois_et_se_relit_l_original_reste_intact(): void
    {
        $cliche = $this->cliche();
        $original = Storage::disk('prive')->get($cliche->chemin);
        $this->getJson("/api/v1/patients/{$this->patient->id}/imagerie/{$this->examen->id}/cliches", $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.0.has_preview', false);
        $this->get($this->url($cliche), $this->enTetes($this->jeton))->assertNotFound();

        $this->poser($cliche, UploadedFile::fake()->image('apercu.jpg', 800, 640))->assertOk()->assertJsonPath('data.has_preview', true);
        $premier = Storage::disk('prive')->get('radios/apercus/'.$cliche->id.'.jpg');
        $this->get($this->url($cliche), $this->enTetes($this->jeton))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Le premier gagne : un second aperçu ne remplace rien
        $this->poser($cliche, UploadedFile::fake()->image('autre.jpg', 400, 300))->assertOk();
        $this->assertSame($premier, Storage::disk('prive')->get('radios/apercus/'.$cliche->id.'.jpg'));
        $this->assertSame($original, Storage::disk('prive')->get($cliche->fresh()->chemin));
    }

    public function test_seule_une_image_jpeg_legere_est_acceptee(): void
    {
        $cliche = $this->cliche();
        $this->poser($cliche, UploadedFile::fake()->create('apercu.png', 10, 'image/png'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->poser($cliche, UploadedFile::fake()->image('enorme.jpg', 2400, 2000))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertNull($cliche->fresh()->preview_path);
    }

    public function test_une_autre_organisation_ne_voit_ni_ne_pose_l_aperçu(): void
    {
        ['jeton' => $etranger] = $this->employeAvecJeton(Clinique::factory()->create());
        $cliche = $this->cliche();
        $this->poser($cliche, UploadedFile::fake()->image('apercu.jpg', 800, 640))->assertOk();

        $this->get($this->url($cliche), $this->enTetes($etranger))->assertNotFound();
        $this->poser($cliche, UploadedFile::fake()->image('a.jpg', 800, 640), $etranger)->assertNotFound();
    }
}
