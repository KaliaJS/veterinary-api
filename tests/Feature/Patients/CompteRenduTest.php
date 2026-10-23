<?php

namespace Tests\Feature\Patients;

use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Compte rendu SOAP : un par rendez-vous, upsert au PUT ; versions gardées
 * avant la signature, texte figé après, compléments signés (23.10.2026).
 */
class CompteRenduTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private RendezVous $rendezVous;

    private User $utilisateur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->rendezVous = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
        ]);

        ['user' => $this->utilisateur, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_un_rendez_vous_sans_compte_rendu_repond_introuvable(): void
    {
        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu", $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_le_put_cree_puis_reecrit_le_meme_compte_rendu(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu";

        $creation = $this->putJson($uri, [
            's' => 'Boiterie depuis 3 jours.',
            'o' => 'Douleur au grasset droit.',
            'a' => 'Suspicion d’entorse.',
            'p' => 'Repos 10 jours, AINS.',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.user_id', $this->utilisateur->id);

        $reecriture = $this->putJson($uri, [
            's' => 'Boiterie depuis 3 jours.',
            'o' => 'Douleur au grasset droit.',
            'a' => 'Entorse confirmée à la radio.',
            'p' => 'Repos 3 semaines, contrôle à J21.',
        ], $entetes)->assertOk();

        $this->assertSame($creation->json('data.id'), $reecriture->json('data.id'));
        $this->assertSame(1, CompteRendu::where('rendez_vous_id', $this->rendezVous->id)->count());

        $this->getJson($uri, $entetes)
            ->assertOk()
            ->assertJsonPath('data.a', 'Entorse confirmée à la radio.')
            ->assertJsonPath('data.versions', 1)
            ->assertJsonPath('data.signe_le', null);

        // La version remplacée, avec son rédacteur
        $this->getJson($uri.'/historique', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.a', 'Suspicion d’entorse.')
            ->assertJsonPath('data.0.auteur', $this->utilisateur->name);

        // Le même texte renvoyé ne crée pas de version
        $this->putJson($uri, ['a' => 'Entorse confirmée à la radio.'], $entetes)->assertOk()->assertJsonPath('data.versions', 1);
    }

    public function test_signe_le_texte_ne_change_plus_et_les_complements_s_ajoutent(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $uri = "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu";

        // Vide : rien à signer
        $this->postJson($uri.'/signer', [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('compte_rendu');

        $this->postJson($uri.'/signer', ['s' => 'Toux.', 'o' => 'Râles.', 'a' => 'Bronchite.', 'p' => 'Antibiotique 7 jours.'], $entetes)
            ->assertSuccessful()
            ->assertJsonPath('data.signe_par', $this->utilisateur->name)
            ->assertJsonPath('data.a', 'Bronchite.');
        $this->assertNotNull(CompteRendu::query()->sole()->signe_le);

        // Ni réécrit ni signé deux fois
        $this->putJson($uri, ['a' => 'Pneumonie.'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('compte_rendu');
        $this->postJson($uri.'/signer', ['a' => 'Pneumonie.'], $entetes)->assertUnprocessable();
        $this->assertSame('Bronchite.', CompteRendu::query()->sole()->a);

        // Ce qui vient après : un complément daté et signé
        $this->postJson($uri.'/complements', ['texte' => 'Radiographie du 25.10 : foyer de pneumonie.'], $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data.complements')
            ->assertJsonPath('data.complements.0.signe_par', $this->utilisateur->name);

        // Même hors de l'API, le texte signé et ses compléments ne bougent pas
        $this->expectException(\LogicException::class);
        CompteRendu::query()->sole()->update(['a' => 'Autre chose.']);
    }

    public function test_seul_qui_a_le_droit_de_signer_signe_et_complete(): void
    {
        $uri = "/api/v1/rendez-vous/{$this->rendezVous->id}/compte-rendu";
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');

        // L'ASV rédige le brouillon, sans le signer
        $this->putJson($uri, ['s' => 'Toux depuis hier.'], $this->enTetes($asv))->assertCreated();
        $this->postJson($uri.'/signer', [], $this->enTetes($asv))->assertForbidden();

        $this->postJson($uri.'/signer', [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson($uri.'/complements', ['texte' => 'Appel du propriétaire : mieux.'], $this->enTetes($asv))->assertForbidden();
    }
}
