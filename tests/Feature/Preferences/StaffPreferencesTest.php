<?php

namespace Tests\Feature\Preferences;

use App\Auth\EmetteurJetons;
use App\Models\Clinique;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Outils en favoris (demande de Jim, 04.11.2026) : chaque personne garde,
 * sur le serveur et pour la clinique ouverte, ses outils favoris (dans
 * l'ordre où elle les a ajoutés) et son choix de masquer les autres. Elle
 * les retrouve sur un autre appareil et sur l'iPhone ; personne d'autre ne
 * les voit ni ne les change, et une autre clinique a les siens.
 */
class StaffPreferencesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private User $user;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['user' => $this->user, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_sans_rien_choisir_aucun_favori_et_rien_de_masque(): void
    {
        $this->getJson('/api/v1/preferences', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => [], 'hide_non_favorite_tools' => false, 'waiting_room' => false]]);
    }

    public function test_un_outil_se_met_en_favori_puis_s_en_retire_dans_l_ordre_des_ajouts(): void
    {
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tool_favorites', ['dose']);
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose', 'fluido', 'dicom']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tool_favorites', ['dose', 'fluido', 'dicom']);
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose', 'dicom']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tool_favorites', ['dose', 'dicom']);

        $this->getJson('/api/v1/preferences', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tool_favorites', ['dose', 'dicom']);
    }

    public function test_le_choix_de_masquer_les_autres_outils_se_garde_puis_se_defait(): void
    {
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['cert'], 'hide_non_favorite_tools' => true], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.hide_non_favorite_tools', true);
        // Afficher de nouveau tous les outils ne touche pas aux favoris
        $this->patchJson('/api/v1/preferences', ['hide_non_favorite_tools' => false], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => ['cert'], 'hide_non_favorite_tools' => false, 'waiting_room' => false]]);
    }

    public function test_les_preferences_se_retrouvent_sur_un_autre_appareil(): void
    {
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['amicus', 'dent'], 'hide_non_favorite_tools' => true], $this->enTetes($this->jeton))
            ->assertOk();

        // L'iPhone de la même personne : un autre jeton, pour la même clinique
        $iphone = app(EmetteurJetons::class)->emettre($this->user, $this->clinique->id, 'iPhone')['jeton']->plainTextToken;

        $this->getJson('/api/v1/preferences', $this->enTetes($iphone))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => ['amicus', 'dent'], 'hide_non_favorite_tools' => true, 'waiting_room' => false]]);
    }

    public function test_chacun_ne_voit_et_ne_change_que_les_siennes(): void
    {
        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose'], 'hide_non_favorite_tools' => true], $this->enTetes($this->jeton))
            ->assertOk();

        // La collègue ne voit rien des choix de l'autre
        $this->getJson('/api/v1/preferences', $this->enTetes($collegue))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => [], 'hide_non_favorite_tools' => false, 'waiting_room' => false]]);

        // Et ses propres choix ne changent pas ceux de l'autre
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['gesta'], 'hide_non_favorite_tools' => false], $this->enTetes($collegue))
            ->assertOk()
            ->assertJsonPath('data.tool_favorites', ['gesta']);
        $this->getJson('/api/v1/preferences', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => ['dose'], 'hide_non_favorite_tools' => true, 'waiting_room' => false]]);
    }

    public function test_chaque_clinique_a_les_siennes(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->user->employes()->firstOrFail()->cliniques()->syncWithoutDetaching([$soeur->id]);
        $jetonSoeur = app(EmetteurJetons::class)->emettre($this->user, $soeur->id)['jeton']->plainTextToken;

        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['bcs'], 'hide_non_favorite_tools' => true], $this->enTetes($this->jeton))
            ->assertOk();

        $this->getJson('/api/v1/preferences', $this->enTetes($jetonSoeur))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => [], 'hide_non_favorite_tools' => false, 'waiting_room' => false]]);

        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['antidote']], $this->enTetes($jetonSoeur))
            ->assertOk();
        $this->getJson('/api/v1/preferences', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => ['bcs'], 'hide_non_favorite_tools' => true, 'waiting_room' => false]]);
    }

    public function test_seules_les_cles_d_outils_connues_sont_acceptees(): void
    {
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose', 'inconnu']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tool_favorites.1']);
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose', 'dose']], $this->enTetes($this->jeton))
            ->assertUnprocessable();
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => 'dose'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tool_favorites']);
        $this->patchJson('/api/v1/preferences', ['hide_non_favorite_tools' => 'peut-être'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hide_non_favorite_tools']);

        $this->getJson('/api/v1/preferences', $this->enTetes($this->jeton))
            ->assertExactJson(['data' => ['tool_favorites' => [], 'hide_non_favorite_tools' => false, 'waiting_room' => false]]);
    }

    /**
     * Deux premiers enregistrements en même temps (vérification du
     * 04.11.2026) : l'autre requête insère sa ligne entre la lecture et
     * l'insertion de celle-ci. L'index unique ne doit pas donner une 500 :
     * la ligne de l'autre est reprise et modifiée.
     */
    public function test_deux_premiers_enregistrements_simultanes_ne_cassent_pas(): void
    {
        $isInserted = false;
        DB::listen(function (QueryExecuted $query) use (&$isInserted): void {
            if ($isInserted || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'staff_preferences')) {
                return;
            }
            $isInserted = true;
            DB::table('staff_preferences')->insert([
                'user_id' => $this->user->id,
                'clinique_id' => $this->clinique->id,
                'tool_favorites' => json_encode(['gesta']),
                'hide_non_favorite_tools' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['tool_favorites' => ['dose'], 'hide_non_favorite_tools' => true, 'waiting_room' => false]]);

        $this->assertTrue($isInserted);
        $this->assertSame(1, DB::table('staff_preferences')->where('user_id', $this->user->id)->count());
    }

    public function test_sans_jeton_rien(): void
    {
        $this->getJson('/api/v1/preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/preferences', ['tool_favorites' => ['dose']])->assertUnauthorized();
    }
}
