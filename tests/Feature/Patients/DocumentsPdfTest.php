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
        // Un vrai PDF (« %PDF- ») de 26 Mo : refusé pour sa taille
        $this->ajouter(['nom' => 'Énorme', 'fichier' => UploadedFile::fake()->createWithContent('enorme.pdf', self::PDF.str_repeat(' ', 26 * 1024 * 1024))])
            ->assertUnprocessable()->assertJsonValidationErrors(['fichier' => 'Ce PDF dépasse 25 Mo.']);
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

    /** Partagé, le propriétaire l'ouvre depuis son espace ; non partagé, ni le titre ni le fichier */
    public function test_le_proprietaire_telecharge_le_pdf_partage_et_seulement_lui(): void
    {
        ['user' => $user, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        $partage = $this->ajouter(['nom' => 'Compte rendu à remettre', 'visible_proprietaire' => '1', 'fichier' => UploadedFile::fake()->createWithContent('b.pdf', self::PDF)])->json('data.id');
        $interne = $this->ajouter(['nom' => 'Rapport interne', 'fichier' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF)])->json('data.id');

        $ligne = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonProprietaire))->json('data'))->firstWhere('id', $partage);
        $this->assertTrue($ligne['fichier']);
        $fichier = $this->get("/api/v1/espace/documents/{$partage}/fichier", $this->enTetes($jetonProprietaire));
        $fichier->assertOk();
        $this->assertSame(self::PDF, $fichier->streamedContent());
        $this->get("/api/v1/espace/documents/{$interne}/fichier", $this->enTetes($jetonProprietaire))->assertNotFound();

        // Le document d'un autre propriétaire : jamais
        ['jeton' => $autre] = $this->proprietaireAvecJeton();
        $this->get("/api/v1/espace/documents/{$partage}/fichier", $this->enTetes($autre))->assertNotFound();
    }

    /** Le PDF ajouté : rangé par organisation, sans signataire (il n'est pas signé par qui l'ajoute), avec qui l'a ajouté */
    public function test_le_pdf_ajoute_est_range_par_organisation_et_dit_qui_l_a_ajoute(): void
    {
        $id = $this->ajouter(['nom' => 'Rapport', 'fichier' => UploadedFile::fake()->createWithContent('r.pdf', self::PDF)])->json('data.id');

        $document = Document::query()->findOrFail($id);
        $this->assertStringStartsWith('documents/'.$this->clinique->societe->organisation_id.'/', $document->chemin);
        $this->assertNull($document->signataire);
        $this->assertNotNull($document->ajoute_par);
    }

    /** Le stockage d'une organisation ne compte que ses DICOM et ses PDF */
    public function test_le_stockage_ne_compte_que_les_fichiers_de_l_organisation(): void
    {
        $autre = Organisation::factory()->create();
        $this->ajouter(['nom' => 'Rapport', 'fichier' => UploadedFile::fake()->createWithContent('r.pdf', self::PDF)])->assertCreated();

        $this->assertSame(0, $autre->stockageOctets());
    }

    /** Un consentement signé compte dès sa signature, sans attendre la nuit */
    public function test_un_consentement_signe_a_sa_taille(): void
    {
        $document = Document::factory()->create(['patient_id' => $this->patient->id, 'chemin' => 'consentements/1-x.png', 'taille_octets' => 4321]);

        $this->assertSame(4321, Organisation::query()->findOrFail($this->clinique->societe->organisation_id)->stockageOctets());
        $this->assertNotNull($document);
    }
}
