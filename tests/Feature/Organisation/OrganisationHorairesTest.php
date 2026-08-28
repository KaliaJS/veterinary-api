<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Horaires d'une clinique de l'organisation depuis l'écran
 * Organisation : clinique sœur ou nouvellement créée, hors clinique du
 * jeton — mais jamais hors de l'organisation. Domaine `organisation`.
 */
class OrganisationHorairesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    public function test_les_horaires_d_une_clinique_soeur_se_lisent_aux_defauts(): void
    {
        $this->getJson("/api/v1/organisation/cliniques/{$this->cliniqueSoeur->id}/horaires", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fuseau_horaire', $this->cliniqueSoeur->fuseau_horaire)
            ->assertJsonPath('data.ouverture', '08:00')
            ->assertJsonPath('data.fermeture', '19:30')
            ->assertJsonPath('data.dimanche_ferme', true);

        $this->assertDatabaseHas('horaires_ouverture', ['clinique_id' => $this->cliniqueSoeur->id]);
        $this->assertDatabaseMissing('horaires_ouverture', ['clinique_id' => $this->clinique->id]);
    }

    public function test_les_horaires_d_une_clinique_soeur_se_modifient_sans_toucher_la_clinique_du_jeton(): void
    {
        $this->getJson('/api/v1/horaires', $this->enTetes($this->jeton))->assertOk();

        $this->putJson("/api/v1/organisation/cliniques/{$this->cliniqueSoeur->id}/horaires", [
            'ouverture' => '07:30',
            'fermeture' => '18:00',
            'samedi_ferme' => true,
            'pause_active' => false,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouverture', '07:30')
            ->assertJsonPath('data.fermeture', '18:00')
            ->assertJsonPath('data.samedi_ferme', true)
            ->assertJsonPath('data.pause_active', false);

        $ouvertures = HoraireOuverture::withoutGlobalScopes()->pluck('ouverture', 'clinique_id');
        $this->assertStringStartsWith('07:30', $ouvertures[$this->cliniqueSoeur->id]);
        $this->assertStringStartsWith('08:00', $ouvertures[$this->clinique->id]);

        // La lecture « clinique du jeton » n'a pas bougé.
        $this->getJson('/api/v1/horaires', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouverture', '08:00');
    }

    public function test_les_horaires_d_une_clinique_creee_se_posent_dans_la_foulee(): void
    {
        $id = $this->postJson('/api/v1/organisation/cliniques', [
            'societe_id' => $this->clinique->societe_id,
            'nom' => 'Clinique du Lac',
            'raison_sociale' => 'Clinique Vétérinaire du Lac',
            'rue' => 'Quai 1',
            'npa' => '1000',
            'ville' => 'Lausanne',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/organisation/cliniques/{$id}/horaires", [
            'ouverture' => '09:00',
            'fermeture' => '17:00',
            'pause_active' => true,
            'pause_debut' => '12:00',
            'pause_fin' => '13:00',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouverture', '09:00')
            ->assertJsonPath('data.pause_debut', '12:00');

        $this->assertSame(1, HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $id)->count());
    }

    public function test_les_invariants_des_horaires_s_appliquent_aussi_depuis_l_organisation(): void
    {
        $this->putJson("/api/v1/organisation/cliniques/{$this->cliniqueSoeur->id}/horaires", [
            'ouverture' => '08:10',
            'fermeture' => '09:00',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ouverture', 'fermeture']);
    }

    public function test_la_clinique_du_jeton_elle_meme_est_atteignable_par_l_organisation(): void
    {
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", [
            'ouverture' => '08:00',
            'fermeture' => '20:00',
            'pause_active' => false,
        ], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/horaires', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermeture', '20:00');
    }

    public function test_une_clinique_d_une_autre_organisation_est_introuvable(): void
    {
        $this->getJson("/api/v1/organisation/cliniques/{$this->cliniqueEtrangere->id}/horaires", $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->putJson("/api/v1/organisation/cliniques/{$this->cliniqueEtrangere->id}/horaires", [
            'ouverture' => '07:00',
            'fermeture' => '18:00',
            'pause_active' => false,
        ], $this->enTetes($this->jeton))->assertNotFound();

        $this->assertDatabaseMissing('horaires_ouverture', ['clinique_id' => $this->cliniqueEtrangere->id]);
    }

    public function test_le_domaine_organisation_est_exige_meme_avec_le_domaine_planning(): void
    {
        // Un vétérinaire porte `planning` (PUT /horaires lui est ouvert)
        // mais pas `organisation` : les cliniques sœurs lui échappent.
        ['jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique);

        $this->putJson('/api/v1/horaires', [
            'ouverture' => '08:00',
            'fermeture' => '19:30',
            'pause_active' => false,
        ], $this->enTetes($jetonVeterinaire))->assertOk();

        $this->getJson("/api/v1/organisation/cliniques/{$this->cliniqueSoeur->id}/horaires", $this->enTetes($jetonVeterinaire))
            ->assertForbidden();
        $this->putJson("/api/v1/organisation/cliniques/{$this->cliniqueSoeur->id}/horaires", [
            'ouverture' => '08:00',
            'fermeture' => '19:30',
            'pause_active' => false,
        ], $this->enTetes($jetonVeterinaire))->assertForbidden();
    }
}
