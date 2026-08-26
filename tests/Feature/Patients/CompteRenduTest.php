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
 * Compte rendu SOAP : un par rendez-vous, upsert au PUT, signé par
 * l'utilisateur du dernier enregistrement.
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
            ->assertJsonPath('data.a', 'Entorse confirmée à la radio.');
    }
}
