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
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
