<?php

namespace Tests\Feature\Organisation;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Enums\RoleLignePlanning;
use App\Enums\TypeSalle;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Role;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/** Premiers pas d'une clinique neuve : étapes déduites des données, confirmations, masquage. */
class PremiersPasTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private array $entetes;

    private int $employeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['latitude' => null, 'longitude' => null]);
        HoraireOuverture::withoutEvents(fn () => HoraireOuverture::create(['clinique_id' => $this->clinique->id, ...HoraireOuverture::DEFAUTS]));
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);
        $this->employeId = $employe->id;
        $this->entetes = $this->enTetes($jeton);
    }

    /** @return array<string, bool> */
    private function faites(): array
    {
        app(ContexteClinique::class)->oublier();
        $etapes = $this->getJson('/api/v1/organisation/premiers-pas', $this->entetes)->assertOk()->json('data.etapes');

        return array_column($etapes, 'fait', 'cle');
    }

    public function test_une_clinique_neuve_a_tout_a_mettre_en_place(): void
    {
        $this->getJson('/api/v1/organisation/premiers-pas', $this->entetes)
            ->assertOk()
            ->assertJsonPath('data.masque', false)
            ->assertJsonPath('data.etapes.0', ['cle' => 'praticiens', 'fait' => false, 'confirmable' => false])
            ->assertJsonPath('data.etapes.2', ['cle' => 'horaires', 'fait' => false, 'confirmable' => true]);

        $this->assertSame(['praticiens', 'salles', 'horaires', 'prestations', 'equipe', 'carte', 'rappels', 'caisse', 'site'], array_keys(array_filter($this->faites(), fn ($fait) => ! $fait)));
    }

    public function test_les_etapes_suivent_les_donnees(): void
    {
        app(ContexteClinique::class)->oublier();
        $salle = Salle::withoutEvents(fn () => Salle::create(['clinique_id' => $this->clinique->id, 'nom' => 'Consultation 1', 'type' => TypeSalle::Consultation]));
        LignePlanning::withoutEvents(fn () => LignePlanning::create([
            'clinique_id' => $this->clinique->id, 'employe_id' => $this->employeId, 'nom' => 'Dr Test',
            'role' => RoleLignePlanning::Consultation, 'groupe' => 'Consultation', 'ordre' => 1,
        ]));
        $this->clinique->update(['latitude' => 46.6, 'longitude' => 6.7]);
        $this->travel(1)->minutes();
        HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $this->clinique->id)->update(['fermeture' => '18:00', 'updated_at' => now()]);

        $faites = $this->faites();
        $this->assertTrue($faites['salles']);
        $this->assertTrue($faites['praticiens']);
        $this->assertTrue($faites['carte']);
        $this->assertTrue($faites['horaires']);
        $this->assertFalse($faites['equipe']);
        $this->assertNotNull($salle->id);
    }

    public function test_les_etapes_confirmables_se_confirment_et_la_liste_se_masque(): void
    {
        $this->putJson('/api/v1/organisation/premiers-pas', ['confirmer' => 'rappels'], $this->entetes)->assertOk();
        app(ContexteClinique::class)->oublier();
        $this->putJson('/api/v1/organisation/premiers-pas', ['confirmer' => 'equipe'], $this->entetes)->assertOk();
        app(ContexteClinique::class)->oublier();
        $this->putJson('/api/v1/organisation/premiers-pas', ['confirmer' => 'salles'], $this->entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('confirmer');

        $faites = $this->faites();
        $this->assertTrue($faites['rappels']);
        $this->assertTrue($faites['equipe']);
        $this->assertFalse($faites['salles']);

        app(ContexteClinique::class)->oublier();
        $this->putJson('/api/v1/organisation/premiers-pas', ['masque' => true], $this->entetes)
            ->assertOk()->assertJsonPath('data.masque', true);
    }

    public function test_reserve_aux_administrateurs(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/organisation/premiers-pas', $this->enTetes($jeton))->assertForbidden();
    }
}
