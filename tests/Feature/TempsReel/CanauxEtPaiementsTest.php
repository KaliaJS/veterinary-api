<?php

namespace Tests\Feature\TempsReel;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Events\PaiementMisAJour;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Services\Paiements\PaiementsCaisse;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Temps réel par Reverb (règle de Jim, 28.10.2026) : chaque clinique a son
 * canal privé, autorisé par le jeton de la session ; l'issue d'un paiement
 * au terminal ou par TWINT y est diffusée, sans relecture régulière.
 */
class CanauxEtPaiementsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        // Un vrai diffuseur Reverb (signature locale, aucun appel réseau pour l'autorisation)
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        // Les canaux se déclarent sur le diffuseur par défaut, choisi au démarrage
        require base_path('routes/channels.php');
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function autoriser(string $canal, ?string $jeton): TestResponse
    {
        return $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $jeton ? $this->enTetes($jeton) : []);
    }

    public function test_le_canal_de_sa_clinique_est_autorise_par_le_jeton(): void
    {
        $this->autoriser('private-clinique.'.$this->clinique->id, $this->jeton)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_le_canal_d_une_autre_clinique_est_refuse(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->autoriser('private-clinique.'.$soeur->id, $this->jeton)->assertForbidden();
    }

    /** Le canal d'une clinique porte des fiches entières (note de l'accueil comprise) : jamais à un propriétaire. */
    public function test_un_proprietaire_n_ouvre_pas_le_canal_d_une_clinique(): void
    {
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();

        $this->assertContains($this->autoriser('private-clinique.'.$this->clinique->id, $jetonProprietaire)->status(), [401, 403]);
    }

    public function test_sans_jeton_aucun_canal(): void
    {
        $this->autoriser('private-clinique.'.$this->clinique->id, null)->assertUnauthorized();
    }

    public function test_l_issue_d_un_paiement_est_diffusee_sur_le_canal_de_la_clinique(): void
    {
        Event::fake([PaiementMisAJour::class]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 60]);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint,
            'montant' => $facture->loadMissing('lignes')->resteAPayer(), 'stripe_payment_intent_id' => 'pi_essai', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->appliquer($paiement, 'succeeded', null);

        Event::assertDispatched(PaiementMisAJour::class, function (PaiementMisAJour $evenement): bool {
            $canaux = $evenement->broadcastOn();

            return $canaux[0] instanceof PrivateChannel
                && $canaux[0]->name === 'private-clinique.'.$this->clinique->id
                && $evenement->broadcastAs() === 'paiement.mis-a-jour'
                && $evenement->broadcastWith()['statut'] === 'reussi';
        });
    }

    public function test_un_paiement_qui_echoue_au_lecteur_est_diffuse_aussi(): void
    {
        Event::fake([PaiementMisAJour::class]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Tpe,
            'montant' => 10, 'stripe_payment_intent_id' => 'pi_refus', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->echecSurLecteur($paiement, 'Carte refusée');

        Event::assertDispatched(PaiementMisAJour::class, fn (PaiementMisAJour $evenement): bool => $evenement->broadcastWith()['statut'] === 'echoue'
            && $evenement->broadcastWith()['erreur'] === 'Carte refusée');
    }

    public function test_un_nom_de_canal_mal_forme_est_refuse_sans_erreur(): void
    {
        $this->autoriser('private-clinique.'.$this->clinique->id.'abc', $this->jeton)->assertForbidden();
    }

    public function test_l_issue_diffusee_est_courte_sans_la_facture(): void
    {
        Event::fake([PaiementMisAJour::class]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'owner_name' => 'Léa Martin']);
        FactureLigne::factory()->count(60)->create(['facture_id' => $facture->id, 'prix_chf' => 10, 'libelle' => str_repeat('é', 40)]);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint,
            'montant' => $facture->loadMissing('lignes')->resteAPayer(), 'stripe_payment_intent_id' => 'pi_gros', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->appliquer($paiement, 'succeeded', null);

        Event::assertDispatched(PaiementMisAJour::class, function (PaiementMisAJour $evenement): bool {
            $corps = (string) json_encode($evenement->broadcastWith());

            return strlen($corps) < 1000
                && ! str_contains($corps, 'Léa Martin')
                && ! array_key_exists('facture', $evenement->broadcastWith())
                && $evenement->broadcastWith()['statut'] === 'reussi';
        });
        $this->assertSame('payee', $facture->refresh()->statut->value);
    }

    public function test_une_diffusion_en_panne_ne_fait_pas_echouer_le_paiement(): void
    {
        // Reverb injoignable : le diffuseur lève une erreur à chaque envoi
        $this->app->make(BroadcastManager::class)->extend('en-panne', fn () => new class extends NullBroadcaster
        {
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb injoignable');
            }
        });
        config(['broadcasting.connections.en-panne' => ['driver' => 'en-panne'], 'broadcasting.default' => 'en-panne']);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 60]);
        $paiement = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint,
            'montant' => 60, 'stripe_payment_intent_id' => 'pi_panne', 'statut' => StatutPaiement::EnAttente,
        ]);

        app(PaiementsCaisse::class)->appliquer($paiement, 'succeeded', null);

        $this->assertSame('payee', $facture->refresh()->statut->value);
        $this->assertSame('reussi', $paiement->refresh()->statut->value);
    }

    public function test_rien_n_est_diffuse_avant_la_validation_ni_apres_une_annulation(): void
    {
        Event::fake([PaiementMisAJour::class]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);

        DB::transaction(function () use ($facture): void {
            Paiement::query()->create(['facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint, 'montant' => 10, 'stripe_payment_intent_id' => 'pi_tx', 'statut' => StatutPaiement::EnAttente]);
            Event::assertNotDispatched(PaiementMisAJour::class);
        });
        Event::assertDispatchedTimes(PaiementMisAJour::class, 1);

        try {
            DB::transaction(function () use ($facture): void {
                Paiement::query()->create(['facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint, 'montant' => 10, 'stripe_payment_intent_id' => 'pi_tx2', 'statut' => StatutPaiement::EnAttente]);
                throw new \RuntimeException('annulée');
            });
        } catch (\RuntimeException) {
        }
        Event::assertDispatchedTimes(PaiementMisAJour::class, 1);
    }

    public function test_une_sauvegarde_sans_changement_ne_rediffuse_pas(): void
    {
        Event::fake([PaiementMisAJour::class]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $paiement = Paiement::query()->create(['facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint, 'montant' => 10, 'stripe_payment_intent_id' => 'pi_une', 'statut' => StatutPaiement::EnAttente]);

        $paiement->touch();
        $paiement->save();

        Event::assertDispatchedTimes(PaiementMisAJour::class, 1);
    }

    /**
     * Deuxième passe (28.10.2026) : un Reverb muet (adresse qui ne répond
     * pas) ne fait pas attendre un geste 10 secondes par message ; la
     * diffusion abandonne vite, le geste est enregistré.
     */
    public function test_un_reverb_muet_n_attend_que_quelques_secondes(): void
    {
        $options = config('broadcasting.connections.reverb.client_options');

        $this->assertLessThanOrEqual(2, $options['connect_timeout'] ?? 10);
        $this->assertLessThanOrEqual(3, $options['timeout'] ?? 30);
    }
}
