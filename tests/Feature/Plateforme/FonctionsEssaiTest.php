<?php

namespace Tests\Feature\Plateforme;

use App\Models\Clinique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fonctions en essai (Laravel Pennant, côté API seulement, 27.10.2026) :
 * un modérateur active une fonction pour un compte, une organisation ou
 * tout le monde, et la retire. La session sert les fonctions actives pour
 * la personne et l'organisation de sa clinique ; une route en essai est
 * refusée à qui ne l'a pas.
 */
class FonctionsEssaiTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plateforme.fonctions_essai' => ['essai_demo' => 'Démo en essai', 'autre' => 'Autre essai']]);
    }

    /** @return array<string, string> */
    private function moderateur(): array
    {
        ['user' => $moderateur, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Jim Modérateur']);
        $this->artisan('plateforme:moderateur', ['email' => $moderateur->email])->assertSuccessful();

        return $this->enTetes($jeton);
    }

    /** @return list<string> */
    private function fonctionsDe(string $jeton): array
    {
        $this->app['auth']->forgetGuards();
        $fonctions = $this->getJson('/api/v1/auth/moi', $this->enTetes($jeton))->assertOk()->json('data.fonctions');
        $this->app['auth']->forgetGuards();

        return $fonctions;
    }

    public function test_une_fonction_s_active_pour_un_compte(): void
    {
        $jim = $this->moderateur();
        ['jeton' => $marie, 'user' => $compteMarie] = $this->employeAvecJeton();
        $compteMarie->forceFill(['email' => 'marie@lac.ch', 'name' => 'Marie Favre'])->save();
        ['jeton' => $paul] = $this->employeAvecJeton();

        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'compte', 'email' => 'Marie@Lac.ch'], $jim)->assertOk();

        $this->assertSame(['essai_demo'], $this->fonctionsDe($marie));
        $this->assertSame([], $this->fonctionsDe($paul));
        $fonction = collect($this->getJson('/api/v1/plateforme/fonctions', $jim)->assertOk()->json('data'))->firstWhere('nom', 'essai_demo');
        $this->assertSame('Démo en essai', $fonction['libelle']);
        $this->assertSame([['id' => $compteMarie->id, 'nom' => 'Marie Favre', 'email' => 'marie@lac.ch']], $fonction['comptes']);
    }

    public function test_une_fonction_s_active_pour_une_organisation(): void
    {
        $jim = $this->moderateur();
        $lac = Clinique::factory()->create();
        ['jeton' => $lea] = $this->employeAvecJeton($lac);
        ['jeton' => $ailleurs] = $this->employeAvecJeton();
        $organisation = $lac->societe->organisation;

        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'organisation', 'organisation_id' => $organisation->id], $jim)->assertOk();

        $this->assertSame(['essai_demo'], $this->fonctionsDe($lea));
        $this->assertSame([], $this->fonctionsDe($ailleurs));
        $fonction = collect($this->getJson('/api/v1/plateforme/fonctions', $jim)->json('data'))->firstWhere('nom', 'essai_demo');
        $this->assertSame([['id' => $organisation->id, 'nom' => $organisation->nom]], $fonction['organisations']);
    }

    public function test_une_fonction_s_ouvre_a_tout_le_monde(): void
    {
        $jim = $this->moderateur();
        ['jeton' => $proprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $employe] = $this->employeAvecJeton();

        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'tous'], $jim)->assertOk();

        $this->assertSame(['essai_demo'], $this->fonctionsDe($proprietaire));
        $this->assertSame(['essai_demo'], $this->fonctionsDe($employe));
        $this->assertTrue(collect($this->getJson('/api/v1/plateforme/fonctions', $jim)->json('data'))->firstWhere('nom', 'essai_demo')['tous']);
    }

    public function test_une_fonction_se_retire_d_un_compte_d_une_organisation_et_de_tout_le_monde(): void
    {
        $jim = $this->moderateur();
        $lac = Clinique::factory()->create();
        ['jeton' => $lea, 'user' => $compteLea] = $this->employeAvecJeton($lac);
        $organisation = $lac->societe->organisation;
        foreach ([['portee' => 'compte', 'email' => $compteLea->email], ['portee' => 'organisation', 'organisation_id' => $organisation->id], ['portee' => 'tous']] as $portee) {
            $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', $portee, $jim)->assertOk();
        }

        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/retirer', ['portee' => 'tous'], $jim)->assertOk();
        $this->assertSame(['essai_demo'], $this->fonctionsDe($lea));
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/retirer', ['portee' => 'organisation', 'organisation_id' => $organisation->id], $jim)->assertOk();
        $this->assertSame(['essai_demo'], $this->fonctionsDe($lea));
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/retirer', ['portee' => 'compte', 'email' => $compteLea->email], $jim)->assertOk();
        $this->assertSame([], $this->fonctionsDe($lea));

        $fonction = collect($this->getJson('/api/v1/plateforme/fonctions', $jim)->json('data'))->firstWhere('nom', 'essai_demo');
        $this->assertFalse($fonction['tous']);
        $this->assertSame([], $fonction['comptes']);
        $this->assertSame([], $fonction['organisations']);
    }

    public function test_l_api_refuse_une_route_en_essai_a_qui_ne_l_a_pas(): void
    {
        Route::middleware(['auth:sanctum', 'fonction:essai_demo'])->get('/api/v1/essai-demo', fn () => response()->json(['ok' => true]));
        $jim = $this->moderateur();
        ['jeton' => $marie, 'user' => $compteMarie] = $this->employeAvecJeton();
        ['jeton' => $paul] = $this->employeAvecJeton();
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'compte', 'email' => $compteMarie->email], $jim)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/essai-demo', $this->enTetes($marie))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/essai-demo', $this->enTetes($paul))
            ->assertForbidden()
            ->assertJsonPath('message', 'Cette fonction est en essai et n’est pas encore ouverte pour vous.');
    }

    public function test_seul_un_moderateur_gere_les_fonctions_en_essai(): void
    {
        ['jeton' => $jeton, 'user' => $compte] = $this->employeAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/plateforme/fonctions', $entetes)->assertForbidden();
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'compte', 'email' => $compte->email], $entetes)->assertForbidden();
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/retirer', ['portee' => 'tous'], $entetes)->assertForbidden();
        $this->assertSame([], $this->fonctionsDe($jeton));
    }

    public function test_une_fonction_inconnue_ou_une_portee_invalide_est_refusee(): void
    {
        $jim = $this->moderateur();

        $this->postJson('/api/v1/plateforme/fonctions/inconnue/activer', ['portee' => 'tous'], $jim)->assertNotFound();
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'compte', 'email' => 'personne@exemple.ch'], $jim)
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Aucun compte à cette adresse.']);
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'organisation', 'organisation_id' => 999999], $jim)
            ->assertUnprocessable()->assertJsonValidationErrors(['organisation_id']);
        $this->postJson('/api/v1/plateforme/fonctions/essai_demo/activer', ['portee' => 'planete'], $jim)
            ->assertUnprocessable()->assertJsonValidationErrors(['portee']);
        $this->assertFalse(User::query()->where('email', 'personne@exemple.ch')->exists());
    }
}
