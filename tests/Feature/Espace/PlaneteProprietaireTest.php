<?php

namespace Tests\Feature\Espace;

use App\Models\AchatPlanete;
use App\Models\Client;
use App\Models\Patient;
use App\Paiements\PasserelleAbonnement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** D26.1c : un objet passé malgré une erreur (500 ou réponse perdue), Stripe injoignable pour vérifier : jamais payé deux fois avant de savoir (26.10.2026). */
    public function test_un_objet_en_doute_n_est_jamais_paye_deux_fois(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $acheter = fn (string $article) => $this->postJson('/api/v1/espace/planete/achats', ['article' => $article], $entetes);

        foreach (['erreur 500' => 'niche', 'réponse perdue' => 'balle'] as $cas => $article) {
            $avant = count($stripe->prelevements);
            $cas === 'erreur 500' ? $stripe->erreurServeur = 500 : $stripe->panne = 'Délai dépassé.';
            $stripe->passeMalgreErreur = true;
            $stripe->listeEnPanne = true;
            $acheter($article)->assertUnprocessable();

            $stripe->erreurServeur = null;
            $stripe->panne = null;
            $stripe->passeMalgreErreur = false;
            $acheter($article)->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
            $this->assertCount($avant + 1, $stripe->prelevements, $cas);

            $stripe->listeEnPanne = false;
            $acheter($article)->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Votre achat précédent vient d’être confirmé : il est réglé.']);
            $this->assertCount($avant + 1, $stripe->prelevements, $cas);
            $this->assertContains($article, $this->getJson('/api/v1/espace/planete', $entetes)->json('data.possedes'), $cas);
        }
    }

    /** Deux clics au même instant sur un achat : le second attend que le premier finisse (26.10.2026). */
    public function test_un_achat_deja_en_cours_n_en_lance_pas_un_second(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();

        $verrou = Cache::lock('abonnement:proprietaire:'.$user->id, 120);
        $this->assertTrue($verrou->get());
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Un paiement est déjà en cours. Patientez un instant.']);
        $this->assertSame([], $stripe->prelevements);
        $verrou->release();
    }

    /** Un même objet acheté par un autre clic pendant que celui-ci attendait le verrou : relu sous le verrou, refusé, rien de prélevé (26.10.2026). */
    public function test_l_objet_est_relu_sous_le_verrou(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $fait = false;
        // Juste après le premier contrôle, l'autre clic achève son achat
        DB::listen(function (QueryExecuted $requete) use (&$fait, $user): void {
            if (! $fait && str_contains($requete->sql, 'from "achats_planete"')) {
                $fait = true;
                AchatPlanete::query()->create(['user_id' => $user->id, 'article' => 'niche']);
            }
        });

        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['article' => 'Cet objet est déjà sur votre planète.']);
        $this->assertTrue($fait);
        $this->assertSame([], $stripe->prelevements);
    }

    /** Un objet resté sans réponse, puis un autre : le second attend ; le premier, renvoyé tel quel, passe une fois ; le second s'achète ensuite (26.10.2026). */
    public function test_un_objet_sans_reponse_puis_un_autre_attend_le_premier(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)->assertUnprocessable();
        $stripe->panne = null;

        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'banc'], $entetes)->assertUnprocessable();
        $this->assertSame([], $stripe->prelevements);

        $this->travel(1)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'banc'], $entetes)->assertOk();
        $this->assertCount(2, $stripe->prelevements);
        $this->assertEqualsCanonicalizing(['niche', 'banc'], AchatPlanete::query()->where('user_id', $user->id)->pluck('article')->map(fn ($a) => $a->value)->all());
    }

    /** Un objet resté sans réponse : renvoyé tel quel une minute plus tard, posé sur la planète, prélevé une fois (26.10.2026). */
    public function test_un_objet_sans_reponse_est_renvoye_et_pose(): void
    {
        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $stripe->carteConfirmee($user->refresh()->stripe_client_id)], $entetes)->assertOk();
        $stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/espace/planete/achats', ['article' => 'niche'], $entetes)->assertUnprocessable();
        $stripe->panne = null;

        $this->travel(1)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();

        $this->assertCount(1, $stripe->prelevements);
        $this->assertTrue(AchatPlanete::query()->where('user_id', $user->id)->where('article', 'niche')->exists());
    }

    public function test_le_deces_d_un_animal_se_declare_et_s_annule(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $rex = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Rex', 'rappels_coupes' => false]);
        $autre = Patient::factory()->create();

        $jour = now('Europe/Zurich')->toDateString();
        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", ['decede_le' => now('Europe/Zurich')->addDay()->toDateString()], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['decede_le' => 'La date ne peut pas être dans le futur.']);
        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", [], $entetes)->assertOk()->assertJsonPath('data.decede_le', $jour);
        $this->assertDatabaseHas('patients', ['id' => $rex->id, 'rappels_coupes' => true]);
        $this->getJson("/api/v1/espace/animaux/{$rex->id}", $entetes)->assertOk()->assertJsonPath('data.decede_le', $jour);

        $this->deleteJson("/api/v1/espace/animaux/{$rex->id}/deces", [], $entetes)->assertOk()->assertJsonPath('data.decede_le', null);
        $this->assertDatabaseHas('patients', ['id' => $rex->id, 'decede_le' => null, 'rappels_coupes' => false]);
        $this->postJson("/api/v1/espace/animaux/{$autre->id}/deces", [], $entetes)->assertNotFound();
    }

    public function test_le_jour_du_deces_est_le_jour_suisse_juste_apres_minuit(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $rex = Patient::factory()->create(['client_id' => $fiche->id]);
        // 00:10 à Zurich, encore la veille en UTC
        $this->travelTo(CarbonImmutable::parse('2026-10-16 22:10:00', 'UTC'));

        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", ['decede_le' => '2026-10-17'], $entetes)->assertOk()->assertJsonPath('data.decede_le', '2026-10-17');
        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", [], $entetes)->assertOk()->assertJsonPath('data.decede_le', '2026-10-17');
        $this->postJson("/api/v1/espace/animaux/{$rex->id}/deces", ['decede_le' => '2026-10-18'], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['decede_le' => 'La date ne peut pas être dans le futur.']);
    }
}
