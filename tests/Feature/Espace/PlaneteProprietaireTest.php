<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Patient;
use App\Paiements\PasserelleAbonnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Jeu de la planète : boutique (objet acheté une fois, prélevé TTC sur la
 * carte, facture reflétée) et décès d'un animal (tombe, rappels coupés,
 * annulable), pour les seuls animaux du compte.
 */
class PlaneteProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_objet_s_achete_une_fois_avec_la_carte_du_compte(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/espace/planete', $entetes)
            ->assertOk()->assertJsonCount(6, 'data.articles')->assertJsonPath('data.articles.0.cle', 'balle')->assertJsonPath('data.articles.0.prix', 0.5)->assertJsonPath('data.possedes', []);
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();

        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)
            ->assertOk()->assertJsonPath('data.possedes', ['niche']);
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('article');
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'licorne'], $entetes)->assertUnprocessable()->assertJsonValidationErrors('article');
        $this->getJson('/api/v1/espace/abonnement', $entetes)
            ->assertOk()->assertJsonPath('data.factures.0.libelle', 'Niche pour votre planète')->assertJsonPath('data.factures.0.montant', 2);
    }

    public function test_le_deces_d_un_animal_se_declare_et_s_annule(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $rex = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Rex', 'rappels_coupes' => false]);
        $autre = Patient::factory()->create();

        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", ['decede_le' => today()->addDay()->toDateString()], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", [], $entetes)->assertOk()->assertJsonPath('data.decede_le', today()->toDateString());
        $this->assertDatabaseHas('patients', ['id' => $rex->id, 'rappels_coupes' => true]);
        $this->getJson("/api/v1/espace/animaux/{$rex->id}", $entetes)->assertOk()->assertJsonPath('data.decede_le', today()->toDateString());

        $this->deleteJson("/api/v1/espace/animaux/{$rex->id}/deces", [], $entetes)->assertOk()->assertJsonPath('data.decede_le', null);
        $this->postJson("/api/v1/espace/animaux/{$autre->id}/deces", [], $entetes)->assertNotFound();
    }
}
