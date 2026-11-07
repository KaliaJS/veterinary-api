<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Enums\AccesClinique;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le dossier patient : les champs que l'API ne servait pas encore (section
 * « Dossier patient : champs non servis » de notes/todo.md, dépôt web,
 * 06.11.2026). Un test par case, écrit d'après son texte.
 */
class PatientRecordFieldsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Clinique $clinique;

    private Patient $bourbon;

    private Employe $moi;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->bourbon = Patient::factory()->for($client)->create(['nom' => 'Bourbon', 'espece' => 'CH']);
        ['jeton' => $this->jeton, 'employe' => $this->moi] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Anne Meyer']);
    }

    private function fiche(): array
    {
        return $this->getJson("/api/v1/patients/{$this->bourbon->id}", $this->enTetes($this->jeton))->assertOk()->json('data');
    }

    /** Case : la robe de l'animal est servie par l'API (et se saisit) */
    public function test_la_robe_se_saisit_a_la_creation_et_en_modification_et_est_servie(): void
    {
        $cree = $this->postJson('/api/v1/patients', [
            'client_id' => $this->bourbon->client_id, 'nom' => 'Moka', 'espece' => 'CT', 'sexe' => 'Femelle', 'coat' => 'Tigrée brune',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $this->assertSame('Tigrée brune', $cree['coat']);

        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['coat' => 'Bleu merle'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.coat', 'Bleu merle');
        $this->assertSame('Bleu merle', $this->fiche()['coat']);

        // Effacée : plus de robe
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['coat' => null], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.coat', null);
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['coat' => str_repeat('a', 121)], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('coat');
    }

    /** La robe est une description que le vétérinaire constate (07.11.2026) : elle se change même sur une fiche que le propriétaire a réservée, pas l'identité */
    public function test_la_robe_se_change_meme_sur_une_fiche_reservee_par_le_proprietaire(): void
    {
        $this->bourbon->update(['proprietaire_id' => User::factory()->create()->id, 'acces_clinique' => AccesClinique::Lecture]);

        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['coat' => 'Noir'], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.coat', 'Noir');
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['race' => 'Beagle'], $this->enTetes($this->jeton))->assertForbidden();
    }

    /** Les refus de la robe, de la race, de l'assurance et du référent sont dits en français */
    public function test_les_refus_de_la_fiche_sont_en_francais(): void
    {
        $long = str_repeat('a', 300);
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['coat' => $long, 'race' => $long, 'assurance' => $long, 'referring_vet_id' => 'abc'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.coat.0', 'La robe ne doit pas dépasser 120 caractères.')
            ->assertJsonPath('errors.race.0', 'La race ne doit pas dépasser 255 caractères.')
            ->assertJsonPath('errors.assurance.0', 'L’assurance ne doit pas dépasser 120 caractères.')
            ->assertJsonPath('errors.referring_vet_id.0', 'Le vétérinaire référent doit être un vétérinaire de l’organisation.');
        $this->postJson('/api/v1/patients', ['client_id' => $this->bourbon->client_id, 'nom' => 'Moka', 'espece' => 'CT', 'sexe' => 'Femelle', 'coat' => $long, 'race' => $long, 'assurance' => $long], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.coat.0', 'La robe ne doit pas dépasser 120 caractères.')
            ->assertJsonPath('errors.race.0', 'La race ne doit pas dépasser 255 caractères.')
            ->assertJsonPath('errors.assurance.0', 'L’assurance ne doit pas dépasser 120 caractères.');
    }

    /** Le référent est un vétérinaire de l'organisation, jamais un assistant */
    public function test_un_assistant_n_est_pas_un_veterinaire_referent(): void
    {
        $asv = Employe::factory()->dans($this->clinique)->create(['nom' => 'Léa Rochat', 'role_libelle' => 'ASV']);

        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['referring_vet_id' => $asv->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.referring_vet_id.0', 'Le vétérinaire référent doit être un vétérinaire de l’organisation.');
    }

    /** La liste à choisir : les vétérinaires de l'organisation (toutes ses cliniques), pour qui a le droit Patients, sans rien de sensible */
    public function test_les_veterinaires_de_l_organisation_sont_servis_a_qui_a_le_droit_patients(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Les Tilleuls']);
        $keller = Employe::factory()->dans($soeur)->create(['nom' => 'Dr Paul Keller', 'salaire_mensuel' => 9000]);
        Employe::factory()->dans($this->clinique)->create(['nom' => 'Léa Rochat', 'role_libelle' => 'ASV']);
        Employe::factory()->dans($this->clinique)->create(['nom' => 'Dr Suspendu', 'acces_suspendu' => true]);
        app(ContexteClinique::class)->oublier();
        Employe::factory()->create(['nom' => 'Dr Ailleurs']);

        $vets = $this->getJson('/api/v1/patients/veterinaires', $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame(['Dr Anne Meyer', 'Dr Paul Keller'], array_column($vets, 'nom'));
        $this->assertSame(['id' => $keller->id, 'nom' => 'Dr Paul Keller', 'cliniques' => [['id' => $soeur->id, 'nom' => 'Les Tilleuls']]], $vets[1]);

        ['employe' => $sansRole, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $sansRole->update(['role_id' => null]);
        $this->getJson('/api/v1/patients/veterinaires', $this->enTetes($jeton))->assertForbidden();
    }

    /** Un consentement dit « recueilli par » dans le fil, comme dans l'onglet */
    public function test_le_fil_dit_qui_a_recueilli_un_consentement(): void
    {
        $this->bourbon->documents()->create(['nom' => 'Consentement éclairé : castration', 'type' => 'consentement', 'signataire' => 'Claire Borel', 'etabli_le' => '2026-03-10', 'employe_id' => $this->moi->id]);

        $fil = $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?type=document", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertStringContainsString('Claire Borel · recueilli par Dr Anne Meyer', $fil[0]['detail']);
    }

    /** Case : le vétérinaire référent est servi (un employé de l'organisation, au choix) */
    public function test_le_veterinaire_referent_est_un_employe_de_l_organisation_et_est_servi(): void
    {
        // Un collègue d'une clinique sœur : de l'organisation, il peut être le référent
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $collegue = Employe::factory()->dans($soeur)->create(['nom' => 'Dr Paul Keller']);

        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['referring_vet_id' => $collegue->id], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.referring_vet_id', $collegue->id)
            ->assertJsonPath('data.referring_vet.nom', 'Dr Paul Keller');
        $this->assertSame(['id' => $collegue->id, 'nom' => 'Dr Paul Keller'], $this->fiche()['referring_vet']);

        // Un employé d'une autre organisation : refusé
        app(ContexteClinique::class)->oublier();
        $ailleurs = Employe::factory()->create();
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['referring_vet_id' => $ailleurs->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('referring_vet_id');

        // Retiré : plus de référent
        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['referring_vet_id' => null], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.referring_vet', null);
    }

    /** Case : le vétérinaire d'une pesée est servi (l'employé qui l'a faite, gardé à la création) */
    public function test_la_pesee_garde_l_employe_qui_l_a_faite_et_le_sert(): void
    {
        $pesee = $this->postJson("/api/v1/patients/{$this->bourbon->id}/pesees", ['poids_kg' => 28.4], $this->enTetes($this->jeton))
            ->assertCreated()->json('data');
        $this->assertSame($this->moi->id, $pesee['employe_id']);

        $this->assertSame('Dr Anne Meyer', $this->fiche()['pesees'][0]['veterinaire']);
        $fil = $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?type=poids", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame('Dr Anne Meyer', $fil[0]['veterinaire']);

        // La pesée notée par le propriétaire dans son espace : pas de vétérinaire
        $this->bourbon->pesees()->create(['poids_kg' => 29, 'pesee_le' => '2026-01-02', 'source_organisation_id' => null, 'employe_id' => null]);
        $this->assertNull(collect($this->fiche()['pesees'])->firstWhere('pesee_le', '2026-01-02')['veterinaire']);
    }

    /** Case : l'auteur d'un document est servi (l'employé qui l'a ajouté, gardé à la création) */
    public function test_le_document_garde_son_auteur_et_le_sert(): void
    {
        $pdf = $this->post("/api/v1/patients/{$this->bourbon->id}/documents", [
            'nom' => 'Rapport du cardiologue', 'fichier' => UploadedFile::fake()->createWithContent('keller.pdf', self::PDF),
        ], $this->enTetes($this->jeton) + ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame('Dr Anne Meyer', $pdf['auteur']);

        $this->postJson("/api/v1/patients/{$this->bourbon->id}/documents", ['nom' => 'Certificat de bonne santé', 'type' => 'certificat'], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.auteur', 'Dr Anne Meyer');

        $this->assertSame(['Dr Anne Meyer', 'Dr Anne Meyer'], array_column($this->fiche()['documents'], 'auteur'));
        $liste = $this->getJson("/api/v1/patients/{$this->bourbon->id}/documents", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame('Dr Anne Meyer', $liste[0]['auteur']);
        $fil = $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?type=document", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame('Dr Anne Meyer', $fil[0]['auteur']);
    }
}
