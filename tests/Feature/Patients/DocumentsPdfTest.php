<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Documents PDF du dossier (demande de Jim, 28.10.2026 : « proposer la
 * possibilité d'ajouter des documents dans le dossier du patient, des PDF :
 * des rapports, des comptes rendus d'autres collègues ») : le PDF est gardé
 * tel quel, se télécharge, compte dans le stockage ; le propriétaire ne le
 * voit que si la clinique le partage.
 */
class DocumentsPdfTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->patient = Patient::factory()->for($this->client)->create(['nom' => 'Félix']);
    }

    private function ajouter(array $champs): TestResponse
    {
        return $this->post("/api/v1/patients/{$this->patient->id}/documents", $champs, $this->enTetes($this->jeton) + ['Accept' => 'application/json']);
    }

    public function test_un_pdf_rejoint_le_dossier_tel_quel_et_se_telecharge(): void
    {
        $document = $this->ajouter([
            'nom' => 'Compte rendu du Dr Keller, cardiologue',
            'type' => 'compte-rendu externe',
            'fichier' => UploadedFile::fake()->createWithContent('keller.pdf', self::PDF),
        ])->assertCreated()->assertJsonPath('data.a_fichier', true)->json('data');

        $this->assertSame(strlen(self::PDF), $document['taille_octets']);
        $this->assertSame('keller.pdf', $document['nom_fichier']);
        $telechargement = $this->get("/api/v1/patients/{$this->patient->id}/documents/{$document['id']}/fichier", $this->enTetes($this->jeton));
        $telechargement->assertOk();
        $this->assertSame(self::PDF, $telechargement->streamedContent());
    }

    public function test_seul_un_pdf_est_accepte_et_jusqu_a_25_mo(): void
    {
        $this->ajouter(['nom' => 'Photo', 'fichier' => UploadedFile::fake()->createWithContent('photo.pdf', 'pas un pdf du tout')])
            ->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->ajouter(['nom' => 'Énorme', 'fichier' => UploadedFile::fake()->create('enorme.pdf', 26 * 1024, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_le_pdf_compte_dans_le_stockage_de_l_organisation(): void
    {
        $this->ajouter(['nom' => 'Rapport', 'fichier' => UploadedFile::fake()->createWithContent('r.pdf', self::PDF)])->assertCreated();

        $organisation = Organisation::query()->findOrFail($this->clinique->societe->organisation_id);
        $this->assertSame(strlen(self::PDF), $organisation->stockageOctets());
        // Le comptage de la nuit ne le compte pas une seconde fois
        $this->artisan('stockage:compter')->assertSuccessful();
        $this->assertSame(strlen(self::PDF), $organisation->refresh()->stockageOctets());
    }

    public function test_le_proprietaire_ne_le_voit_que_si_la_clinique_le_partage(): void
    {
        ['user' => $user, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);

        $this->ajouter(['nom' => 'Rapport interne du confrère', 'fichier' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF)])
            ->assertCreated()->assertJsonPath('data.visible_proprietaire', false);
        $this->ajouter(['nom' => 'Compte rendu à remettre', 'visible_proprietaire' => '1', 'fichier' => UploadedFile::fake()->createWithContent('b.pdf', self::PDF)])
            ->assertCreated()->assertJsonPath('data.visible_proprietaire', true);
        // Un document sans fichier (certificat) reste visible, comme avant
        $this->postJson("/api/v1/patients/{$this->patient->id}/documents", ['nom' => 'Certificat de bonne santé', 'type' => 'certificat'], $this->enTetes($this->jeton))->assertCreated();

        $titres = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonProprietaire))->assertOk()->json('data'))->pluck('titre')->all();
        $this->assertContains('Compte rendu à remettre (Félix)', $titres);
        $this->assertContains('Certificat de bonne santé (Félix)', $titres);
        $this->assertNotContains('Rapport interne du confrère (Félix)', $titres);
    }

    public function test_une_autre_organisation_ne_telecharge_pas_le_pdf(): void
    {
        ['jeton' => $etranger] = $this->employeAvecJeton(Clinique::factory()->create());
        $id = $this->ajouter(['nom' => 'Rapport', 'fichier' => UploadedFile::fake()->createWithContent('r.pdf', self::PDF)])->json('data.id');

        $this->get("/api/v1/patients/{$this->patient->id}/documents/{$id}/fichier", $this->enTetes($etranger))->assertNotFound();
    }
}
