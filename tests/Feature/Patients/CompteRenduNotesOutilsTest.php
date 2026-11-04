<?php

namespace Tests\Feature\Patients;

use App\Enums\DomainePermission;
use App\Enums\StatutEnregistrement;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\DerogationPermission;
use App\Models\EnregistrementConsultation;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les lignes notées par les calculateurs (vérification du 04.11.2026, G1) :
 * gardées à part du texte SOAP, ajoutées côté serveur l'une après l'autre.
 * Le brouillon d'un enregistrement, qui réécrit S, O, A et P, ne les efface
 * jamais ; signé, le compte rendu les reçoit en complément.
 */
class CompteRenduNotesOutilsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private RendezVous $rendezVous;

    private string $jeton;

    private User $user;

    private string $uri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);
        ['jeton' => $this->jeton, 'user' => $this->user] = $this->employeAvecJeton($this->clinique);
        $this->uri = "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu";
    }

    public function test_les_lignes_s_ajoutent_l_une_apres_l_autre_sans_toucher_au_texte(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : poids 8,4 kg. Débit total 110 mL/h.'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.tool_notes', 'Fluidothérapie : poids 8,4 kg. Débit total 110 mL/h.')
            ->assertJsonPath('data.o', null);
        $this->putJson($this->uri, ['o' => 'T° 38,6.'], $entetes)->assertOk();
        $this->postJson($this->uri.'/outils', ['texte' => 'Calculateur de doses : Méloxicam 0,84 mg.'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.o', 'T° 38,6.')
            ->assertJsonPath('data.tool_notes', "Fluidothérapie : poids 8,4 kg. Débit total 110 mL/h.\nCalculateur de doses : Méloxicam 0,84 mg.");

        $this->assertSame(1, CompteRendu::where('rendez_vous_id', $this->rendezVous->id)->count());
    }

    /** Le scénario du vérificateur : enregistrement prêt, ligne notée, puis brouillon de l'enregistrement enregistré */
    public function test_le_brouillon_d_un_enregistrement_n_efface_pas_une_ligne_notee(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => $this->rendezVous->id,
            'user_id' => $this->user->id,
            'statut' => StatutEnregistrement::Pret->value,
            'brouillon' => ['s' => 'Abattu.', 'o' => 'Déshydraté à 5 %.', 'a' => 'Gastro-entérite.', 'p' => 'Perfusion.', 'doutes' => []],
        ]);

        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : débit total 110 mL/h.'], $entetes)->assertOk();
        $this->putJson($this->uri, ['s' => 'Abattu.', 'o' => 'Déshydraté à 5 %.', 'a' => 'Gastro-entérite.', 'p' => 'Perfusion.', 'enregistrement_id' => $enregistrement->id], $entetes)
            ->assertOk()
            ->assertJsonPath('data.o', 'Déshydraté à 5 %.')
            ->assertJsonPath('data.tool_notes', 'Fluidothérapie : débit total 110 mL/h.');
        $this->assertSame(StatutEnregistrement::Valide, EnregistrementConsultation::withoutGlobalScopes()->findOrFail($enregistrement->id)->statut);
    }

    public function test_signe_la_ligne_part_en_complement_avec_le_droit_de_signer(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson($this->uri.'/signer', ['o' => 'Râles.'], $entetes)->assertSuccessful();

        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->postJson($this->uri.'/outils', ['texte' => 'Score corporel : 5 / 9.'], $this->enTetes($asv))->assertForbidden();

        $this->postJson($this->uri.'/outils', ['texte' => 'Score corporel : 5 / 9.'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.tool_notes', null)
            ->assertJsonPath('data.complements.0.texte', 'Score corporel : 5 / 9.');
    }

    public function test_sans_le_domaine_patients_ni_texte_rien(): void
    {
        ['employe' => $employe, 'jeton' => $sansPatients] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Patients, 'est_accordee' => false]);
        $this->postJson($this->uri.'/outils', ['texte' => 'Ligne'], $this->enTetes($sansPatients))->assertForbidden();

        $this->postJson($this->uri.'/outils', ['texte' => '  '], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('texte');
        $this->assertSame(0, CompteRendu::count());
    }

    public function test_un_compte_rendu_qui_n_a_que_des_lignes_notees_se_signe(): void
    {
        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : débit total 110 mL/h.'], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($this->uri.'/signer', [], $this->enTetes($this->jeton))->assertSuccessful();
    }

    /** 2e vérification du 04.11.2026 : le premier texte écrit après une ligne notée ne garde pas une version vide */
    public function test_le_premier_texte_apres_une_ligne_notee_ne_garde_pas_de_version_vide(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : débit total 110 mL/h.'], $entetes)->assertOk();
        $this->putJson($this->uri, ['o' => 'T° 38,6.'], $entetes)->assertOk()->assertJsonPath('data.versions', 0);
        $this->putJson($this->uri, ['o' => 'T° 38,9.'], $entetes)->assertOk()->assertJsonPath('data.versions', 1);
    }

    /** Des lignes notées seules ne font pas un compte rendu rédigé : ni au planning, ni au fil du dossier */
    public function test_une_ligne_notee_seule_ne_fait_pas_un_compte_rendu_redige(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $patient = Patient::factory()->create(['client_id' => Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id])->id]);
        $this->rendezVous->forceFill(['patient_id' => $patient->id, 'client_id' => $patient->client_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30)])->save();

        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : débit total 110 mL/h.'], $entetes)->assertOk();
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}", $entetes)->assertOk()->assertJsonPath('data.a_compte_rendu', false);
        $fil = $this->getJson("/api/v1/patients/{$patient->id}/fil", $entetes)->assertOk()->json('data');
        $visite = collect($fil)->firstWhere('rendez_vous_id', $this->rendezVous->id);
        $this->assertNotNull($visite);
        $this->assertStringNotContainsString('compte rendu en cours', $visite['detail']);
        $this->assertNull($visite['compte_rendu']);

        $this->putJson($this->uri, ['o' => 'T° 38,6.'], $entetes)->assertOk();
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}", $entetes)->assertJsonPath('data.a_compte_rendu', true);
    }

    /**
     * Choix du 04.11.2026 : une ligne notée garde le rendez-vous de la
     * suppression (l'annulation immédiate d'une création, 20 secondes) ;
     * supprimer le rendez-vous effacerait la ligne avec lui.
     */
    public function test_une_ligne_notee_garde_le_rendez_vous_de_la_suppression(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $id = $this->postJson('/api/v1/rendez-vous', ['ligne_planning_id' => $ligne->id, 'starts_at' => aujourdhui()->addDay()->setTime(11, 0)->toISOString(), 'duree_minutes' => 20], $entetes)
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/rendez-vous/{$id}/compte-rendu/outils", ['texte' => 'Calculateur de doses : Méloxicam 0,84 mg.'], $entetes)->assertOk();

        $this->deleteJson("/api/v1/rendez-vous/{$id}", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('rendez_vous');
        $this->assertDatabaseHas('comptes_rendus', ['rendez_vous_id' => $id]);
    }

    public function test_un_rendez_vous_d_une_autre_organisation_repond_introuvable(): void
    {
        $ailleurs = Clinique::factory()->create();
        $leur = RendezVous::factory()->create([
            'clinique_id' => $ailleurs->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $ailleurs->id])->id,
        ]);
        $this->postJson("/api/v1/rendez-vous/{$leur->id}/compte-rendu/outils", ['texte' => 'Ligne'], $this->enTetes($this->jeton))->assertNotFound();
        $this->assertSame(0, CompteRendu::query()->where('rendez_vous_id', $leur->id)->count());
    }

    public function test_une_ligne_est_bornee_a_2000_caracteres(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson($this->uri.'/outils', ['texte' => str_repeat('a', 2001)], $entetes)->assertUnprocessable()->assertJsonValidationErrors('texte');
        $this->postJson($this->uri.'/outils', ['texte' => str_repeat('a', 2000)], $entetes)->assertOk();
    }

    public function test_signees_les_lignes_notees_ne_changent_plus(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson($this->uri.'/outils', ['texte' => 'Fluidothérapie : débit total 110 mL/h.'], $entetes)->assertOk();
        $this->postJson($this->uri.'/signer', [], $entetes)->assertSuccessful();

        $this->expectException(LogicException::class);
        CompteRendu::query()->where('rendez_vous_id', $this->rendezVous->id)->sole()->update(['tool_notes' => 'Autre chose.']);
    }

    /** Des espaces seuls ne font pas un texte : la requête suit la même règle que le modèle */
    public function test_des_espaces_seuls_ne_font_pas_un_compte_rendu_redige(): void
    {
        $entetes = $this->enTetes($this->jeton);
        // Écrit sans passer par l'API (qui rogne les espaces) : un ancien import, une autre voie
        CompteRendu::factory()->create(['rendez_vous_id' => $this->rendezVous->id, 's' => "\n ", 'o' => '   ', 'a' => null, 'p' => null, 'signe_le' => null]);
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}", $entetes)->assertOk()->assertJsonPath('data.a_compte_rendu', false);
        $this->getJson('/api/v1/rendez-vous?per_page=50', $entetes)->assertOk()->assertJsonPath('data.0.a_compte_rendu', false);
    }
}
