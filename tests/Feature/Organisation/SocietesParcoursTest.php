<?php

namespace Tests\Feature\Organisation;

use App\Models\Clinique;
use App\Models\Societe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Sociétés de l'organisation, parcours nominal : la liste (avec le nombre
 * de cliniques de chacune, jamais celles d'une autre organisation), la
 * création puis la modification du nom, de l'IDE (normalisé ou retiré) et
 * de l'inscription à la TVA ; ce que la validation et les droits refusent.
 */
class SocietesParcoursTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_les_societes_de_l_organisation_se_listent_avec_leur_nombre_de_cliniques(): void
    {
        Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $vide = Societe::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Vet Riviera Sàrl']);
        Clinique::factory()->create();

        $this->getJson('/api/v1/organisation/societes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $this->clinique->societe_id)
            ->assertJsonPath('data.0.nombre_cliniques', 2)
            ->assertJsonPath('data.0.assujettie_tva', true)
            ->assertJsonPath('data.1.id', $vide->id)
            ->assertJsonPath('data.1.nom', 'Vet Riviera Sàrl')
            ->assertJsonPath('data.1.ide', $vide->ide)
            ->assertJsonPath('data.1.nombre_cliniques', 0);
    }

    public function test_une_societe_se_cree_puis_se_renomme_change_d_ide_et_de_statut_tva(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $id = $this->postJson('/api/v1/organisation/societes', ['nom' => 'Cabinet du Lac Sàrl'], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.ide', null)
            ->assertJsonPath('data.assujettie_tva', true)
            ->json('data.id');

        $this->assertSame($this->clinique->societe->organisation_id, Societe::query()->findOrFail($id)->organisation_id);
        $this->assertContains($id, array_column($this->getJson('/api/v1/organisation/societes', $entetes)->json('data'), 'id'));

        // IDE saisi en minuscules et entouré d'espaces : enregistré au format du registre
        $this->patchJson("/api/v1/organisation/societes/{$id}", [
            'nom' => 'Cabinet vétérinaire du Lac Sàrl',
            'ide' => '  che-422.618.848 ',
            'assujettie_tva' => false,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.nom', 'Cabinet vétérinaire du Lac Sàrl')
            ->assertJsonPath('data.ide', 'CHE-422.618.848')
            ->assertJsonPath('data.assujettie_tva', false)
            ->assertJsonPath('data.nombre_cliniques', 0);

        // Un IDE vidé retire le numéro ; le reste ne bouge pas
        $this->patchJson("/api/v1/organisation/societes/{$id}", ['ide' => ''], $entetes)
            ->assertOk()
            ->assertJsonPath('data.ide', null)
            ->assertJsonPath('data.nom', 'Cabinet vétérinaire du Lac Sàrl')
            ->assertJsonPath('data.assujettie_tva', false);

        $this->assertNull(Societe::query()->findOrFail($id)->ide);
    }

    public function test_la_creation_et_la_modification_refusent_un_nom_absent_un_ide_faux_ou_un_statut_tva_invalide(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $societe = $this->clinique->societe;
        $ide = $societe->ide;

        $this->postJson('/api/v1/organisation/societes', ['ide' => 'CHE-422.618.848'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->postJson('/api/v1/organisation/societes', ['nom' => 'Mal formé', 'ide' => '422618848'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('ide');

        $this->patchJson("/api/v1/organisation/societes/{$societe->id}", ['ide' => 'CHE-422.618.849'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('ide');
        $this->patchJson("/api/v1/organisation/societes/{$societe->id}", ['nom' => ''], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('nom');
        $this->patchJson("/api/v1/organisation/societes/{$societe->id}", ['assujettie_tva' => 'peut-être'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('assujettie_tva');

        $societe->refresh();
        $this->assertSame($ide, $societe->ide);
        $this->assertNotFalse($societe->assujettie_tva);
        $this->assertSame(1, Societe::query()->where('organisation_id', $societe->organisation_id)->count());
    }

    public function test_un_veterinaire_ne_modifie_pas_une_societe(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $nom = $this->clinique->societe->nom;

        $this->patchJson("/api/v1/organisation/societes/{$this->clinique->societe_id}", ['nom' => 'Renommée sans droit'], $this->enTetes($jeton))
            ->assertForbidden();

        $this->assertSame($nom, $this->clinique->societe->refresh()->nom);
    }
}
