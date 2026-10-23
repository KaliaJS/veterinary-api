<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Models\FactureProprietaire;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Offre Plus du propriétaire, routes de suivi : réactivation avant la fin
 * de l'année, retrait de la carte, facture à imprimer, règlement d'une
 * facture restée à payer et confirmation bancaire. Chaque route a son cas
 * nominal et son refus (état, appartenance).
 */
class AbonnementProprietaireFacturesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $this->stripe);
    }

    /** Carte enregistrée par le Payment Element (double) */
    private function avecCarte(User $user, string $jeton, string $fin = '4242'): string
    {
        $entetes = $this->enTetes($jeton);
        $this->postJson('/api/v1/espace/abonnement/carte/intention', [], $entetes)->assertOk();
        $carte = $this->stripe->carteConfirmee((string) $user->refresh()->stripe_client_id, 'visa', $fin);
        $this->putJson('/api/v1/espace/abonnement/carte', ['payment_method' => $carte], $entetes)->assertOk();

        return $carte;
    }

    /**
     * Plus souscrit le 20.10.2026, avec une carte
     *
     * @return array{0: User, 1: string}
     */
    private function abonnePlus(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Claire Dubois']);
        $this->avecCarte($user, $jeton);
        $this->postJson('/api/v1/espace/abonnement/plus', [], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.formule', 'plus');

        return [$user, $jeton];
    }

    /** Un an plus tard, avec un jeton neuf (les jetons expirent) */
    private function auRenouvellement(User $user): string
    {
        $this->travelTo(CarbonImmutable::parse('2027-10-20 06:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        return $this->proprietaireAvecJetonPour($user);
    }

    private function proprietaireAvecJetonPour(User $user): string
    {
        return app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;
    }

    public function test_plus_resilie_se_reactive_avant_la_fin_de_l_annee_sans_nouveau_prelevement(): void
    {
        [, $jeton] = $this->abonnePlus();
        $entetes = $this->enTetes($jeton);

        $this->postJson('/api/v1/espace/abonnement/resilier', [], $entetes)
            ->assertOk()->assertJsonPath('data.resilie_le', '2027-10-20');

        $this->postJson('/api/v1/espace/abonnement/reactiver', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.resilie_le', null)
            ->assertJsonPath('data.periode_fin', '2027-10-20')
            ->assertJsonCount(1, 'data.factures');
        $this->assertCount(1, $this->stripe->prelevements);
    }

    public function test_reactiver_sans_abonnement_en_cours_est_refuse(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/espace/abonnement/reactiver', [], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abonnement');
        $this->assertNull($user->refresh()->statut_abonnement);
    }

    public function test_reactiver_exige_une_session(): void
    {
        $this->postJson('/api/v1/espace/abonnement/reactiver')->assertUnauthorized();
        $this->deleteJson('/api/v1/espace/abonnement/carte')->assertUnauthorized();
        $this->getJson('/api/v1/espace/abonnement/factures/1')->assertUnauthorized();
    }

    public function test_la_carte_se_retire_chez_stripe_et_plus_ne_se_souscrit_plus_sans_elle(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $carte = $this->avecCarte($user, $jeton);

        $this->deleteJson('/api/v1/espace/abonnement/carte', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.moyen_paiement', null)
            ->assertJsonPath('data.carte_expire_le', null)
            ->assertJsonPath('data.formule', 'gratuit');

        $this->assertSame([$carte], $this->stripe->detachees);
        $user->refresh();
        $this->assertNull($user->stripe_moyen_paiement_id);
        // Le client Stripe reste : une nouvelle carte s'y rattache
        $this->assertNotNull($user->stripe_client_id);

        // Sans carte : Plus est refusé
        $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        // Retirer quand il n'y a plus de carte : rien n'est détaché de nouveau
        $this->deleteJson('/api/v1/espace/abonnement/carte', [], $entetes)->assertOk();
        $this->assertSame([$carte], $this->stripe->detachees);
    }

    public function test_une_facture_plus_se_lit_a_imprimer_avec_emetteur_et_client(): void
    {
        [$user, $jeton] = $this->abonnePlus();
        $facture = FactureProprietaire::query()->where('user_id', $user->id)->sole();

        $this->getJson("/api/v1/espace/abonnement/factures/{$facture->id}", $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $facture->id)
            ->assertJsonPath('data.numero', $facture->numero)
            ->assertJsonPath('data.statut', 'payee')
            ->assertJsonPath('data.montant', 12)
            ->assertJsonPath('data.lignes.0.libelle', 'Abonnement Veterinary Plus, du 20.10.2026 au 19.10.2027')
            ->assertJsonPath('data.periode_debut', '2026-10-20')
            ->assertJsonPath('data.periode_fin', '2027-10-19')
            ->assertJsonPath('data.carte', 'Visa •••• 4242 · exp. 08/28')
            ->assertJsonPath('data.emetteur.nom', config('plateforme.emetteur.nom'))
            ->assertJsonPath('data.emetteur.assujetti_tva', false)
            ->assertJsonPath('data.client.nom', 'Claire Dubois')
            ->assertJsonPath('data.client.lignes', [$user->email]);
    }

    public function test_la_facture_d_un_autre_compte_est_introuvable(): void
    {
        [$user] = $this->abonnePlus();
        $facture = FactureProprietaire::query()->where('user_id', $user->id)->sole();
        ['jeton' => $autre] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($autre);

        $this->getJson("/api/v1/espace/abonnement/factures/{$facture->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture->id}/regler", [], $entetes)->assertNotFound();
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture->id}/confirmer", [], $entetes)->assertNotFound();
        $this->getJson('/api/v1/espace/abonnement/factures/abc', $entetes)->assertNotFound();

        $this->assertCount(1, $this->stripe->prelevements);
    }

    public function test_une_confirmation_bancaire_active_plus_une_fois_faite(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);
        $this->avecCarte($user, $jeton);
        $this->stripe->actionRequise = true;

        $facture = $this->postJson('/api/v1/espace/abonnement/plus', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'gratuit')
            ->assertJsonPath('data.factures.0.statut', 'a_payer')
            ->assertJsonPath('paiement.client_secret', fn (string $secret) => str_ends_with($secret, '_secret'))
            ->json('paiement.facture_id');

        // La banque attend encore : rien ne change, la confirmation est redemandée
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/confirmer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'gratuit')
            ->assertJsonPath('paiement.facture_id', $facture);

        $this->stripe->confirmer($this->stripe->dernierPrelevement()['id']);
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/confirmer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.periode_fin', '2027-10-20')
            ->assertJsonPath('data.factures.0.statut', 'payee')
            ->assertJsonPath('paiement', null);

        // Confirmer de nouveau ne paie pas deux fois
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/confirmer", [], $entetes)
            ->assertOk()->assertJsonPath('paiement', null);
        $this->assertCount(1, $this->stripe->prelevements);
        $this->assertNotNull(FactureProprietaire::query()->findOrFail($facture)->paye_le);
    }

    public function test_une_facture_refusee_au_renouvellement_se_regle_depuis_l_espace(): void
    {
        Mail::fake();
        [$user] = $this->abonnePlus();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $entetes = $this->enTetes($this->auRenouvellement($user));

        $facture = $this->getJson('/api/v1/espace/abonnement', $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'impaye')
            ->assertJsonPath('data.factures.0.statut', 'a_payer')
            ->json('data.factures.0.id');

        // Toujours refusée : la facture reste à payer
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/regler", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertSame('a_payer', FactureProprietaire::query()->findOrFail($facture)->statut);

        // Nouvelle carte, puis « Régler »
        $this->stripe->refus = null;
        $this->avecCarte($user, $this->proprietaireAvecJetonPour($user), '5556');
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/regler", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.formule', 'plus')
            ->assertJsonPath('data.statut', 'actif')
            ->assertJsonPath('data.periode_fin', '2028-10-20')
            ->assertJsonPath('data.factures.0.statut', 'payee')
            ->assertJsonPath('data.factures.0.carte', 'Visa •••• 5556 · exp. 08/28')
            ->assertJsonPath('paiement', null);
        $this->assertFalse($this->stripe->dernierPrelevement()['hors_session']);
        $this->assertSame(0, $user->refresh()->tentatives);

        // Une facture payée n'attend plus rien
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture}/regler", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('facture');
    }

    public function test_regler_sans_carte_ou_confirmer_sans_paiement_parti_est_refuse(): void
    {
        Mail::fake();
        [$user] = $this->abonnePlus();
        // Le prestataire en panne au renouvellement : aucun prélèvement n'est parti
        $this->stripe->panne = 'Le prestataire de paiement ne répond pas.';
        $entetes = $this->enTetes($this->auRenouvellement($user));
        $facture = FactureProprietaire::query()->where('user_id', $user->id)->where('statut', 'a_payer')->sole();
        $this->assertNull($facture->stripe_paiement_id);

        $this->postJson("/api/v1/espace/abonnement/factures/{$facture->id}/confirmer", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('facture');

        $this->deleteJson('/api/v1/espace/abonnement/carte', [], $entetes)->assertOk();
        $this->stripe->panne = null;
        $this->postJson("/api/v1/espace/abonnement/factures/{$facture->id}/regler", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');
        $this->assertSame('a_payer', $facture->refresh()->statut);
    }
}
