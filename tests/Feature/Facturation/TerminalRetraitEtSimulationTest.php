<?php

namespace Tests\Feature\Facturation;

use App\Auth\RolesParDefaut;
use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\Role;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Terminaux de la caisse : le retrait d'un lecteur (chez Stripe puis chez
 * nous, même s'il n'existe plus là-bas) et la carte de test présentée sur
 * un lecteur simulé, jamais sur un vrai. Le double note les lecteurs
 * supprimés et simulés ; la carte simulée fait aboutir l'intention, comme
 * chez Stripe.
 */
class TerminalRetraitEtSimulationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private PasserellePaiementFactice $stripe;

    /** @var list<string> */
    private array $supprimes = [];

    /** @var list<string> */
    private array $simules = [];

    private ?ErreurPaiement $erreurSuppression = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $test = $this;
        $this->stripe = new class($test) extends PasserellePaiementFactice
        {
            public function __construct(private TerminalRetraitEtSimulationTest $test) {}

            public function supprimerLecteur(string $lecteurId): void
            {
                $this->test->noterSuppression($lecteurId);
            }

            public function simulerCarte(string $lecteurId): void
            {
                $this->test->noterSimulation($lecteurId);
                // Stripe : la carte de test présentée paie l'intention en cours sur ce lecteur
                $presentee = collect($this->presentations)->where('lecteur', $lecteurId)->last();
                if ($presentee) {
                    $this->conclure($presentee['intention'], 'succeeded');
                }
            }
        };
        $this->app->instance(PasserellePaiement::class, $this->stripe);
    }

    public function noterSuppression(string $lecteurId): void
    {
        if ($this->erreurSuppression) {
            throw $this->erreurSuppression;
        }
        $this->supprimes[] = $lecteurId;
    }

    public function noterSimulation(string $lecteurId): void
    {
        $this->simules[] = $lecteurId;
    }

    private function terminal(?Clinique $clinique = null, string $modele = 'bbpos_wisepos_e', string $lecteur = 'tmr_comptoir'): TerminalPaiement
    {
        $clinique ??= $this->clinique;

        return TerminalPaiement::withoutGlobalScopes()->create([
            'clinique_id' => $clinique->id, 'stripe_reader_id' => $lecteur, 'libelle' => 'Comptoir', 'modele' => $modele,
        ]);
    }

    private function factureDue(float $montant): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant, 'quantite' => 1]);

        return $facture;
    }

    private function payerParTerminal(Facture $facture, TerminalPaiement $terminal): int
    {
        return $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
            'moyen' => 'tpe',
            'terminal_id' => $terminal->id,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
    }

    private function assistantAvecJeton(): string
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ASSISTANT)
            ->value('id')]);

        return $jeton;
    }

    /* Retrait d'un terminal */

    public function test_retirer_un_terminal_le_supprime_chez_stripe_puis_de_la_liste(): void
    {
        $terminal = $this->terminal();
        $autre = $this->terminal(lecteur: 'tmr_consultation');

        $this->deleteJson("/api/v1/facturation/terminaux/{$terminal->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertSame(['tmr_comptoir'], $this->supprimes);
        $this->assertModelMissing($terminal);
        $this->getJson('/api/v1/facturation/terminaux', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $autre->id);
    }

    public function test_un_lecteur_deja_supprime_chez_stripe_se_retire_quand_meme(): void
    {
        $terminal = $this->terminal();
        $this->erreurSuppression = new ErreurPaiement('Élément introuvable chez Stripe.', 'resource_missing');

        $this->deleteJson("/api/v1/facturation/terminaux/{$terminal->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertModelMissing($terminal);
    }

    public function test_sans_compte_de_paiement_le_terminal_se_retire_chez_nous_seulement(): void
    {
        $terminal = $this->terminal();
        $this->clinique->societe->forceFill(['stripe_compte_id' => null, 'paiements_actifs' => false])->save();

        $this->deleteJson("/api/v1/facturation/terminaux/{$terminal->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertSame([], $this->supprimes);
        $this->assertModelMissing($terminal);
    }

    public function test_une_erreur_de_stripe_garde_le_terminal_et_renvoie_un_message(): void
    {
        $terminal = $this->terminal();
        $this->erreurSuppression = new ErreurPaiement('Le terminal est occupé.', 'terminal_reader_busy');

        $this->deleteJson("/api/v1/facturation/terminaux/{$terminal->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.terminal.0', 'Le terminal est occupé.');

        $this->assertModelExists($terminal);
    }

    public function test_le_terminal_d_une_clinique_soeur_ou_d_une_autre_organisation_est_introuvable(): void
    {
        $soeur = $this->terminal(Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]), lecteur: 'tmr_soeur');
        $etranger = $this->terminal(Clinique::factory()->create(), lecteur: 'tmr_etranger');

        $this->deleteJson("/api/v1/facturation/terminaux/{$soeur->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->deleteJson("/api/v1/facturation/terminaux/{$etranger->id}", [], $this->enTetes($this->jeton))->assertNotFound();

        $this->assertSame([], $this->supprimes);
        $this->assertModelExists($soeur);
        $this->assertModelExists($etranger);
    }

    public function test_un_role_sans_la_caisse_ne_retire_pas_de_terminal(): void
    {
        $terminal = $this->terminal();

        $this->deleteJson("/api/v1/facturation/terminaux/{$terminal->id}", [], $this->enTetes($this->assistantAvecJeton()))
            ->assertForbidden();

        $this->assertModelExists($terminal);
    }

    /* Carte de test sur un lecteur simulé */

    public function test_la_carte_de_test_sur_un_lecteur_simule_paie_la_facture(): void
    {
        $facture = $this->factureDue(42);
        $paiement = $this->payerParTerminal($facture, $this->terminal(modele: 'simulated_wisepos_e', lecteur: 'tmr_simule'));

        $this->postJson("/api/v1/facturation/paiements/{$paiement}/simuler-carte", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $paiement)
            ->assertJsonPath('data.statut', 'reussi')
            ->assertJsonPath('data.facture.statut', 'payee');

        $this->assertSame(['tmr_simule'], $this->simules);
        $this->assertSame(StatutFacture::Payee, $facture->refresh()->statut);
    }

    public function test_la_carte_de_test_est_refusee_sur_un_vrai_lecteur(): void
    {
        $facture = $this->factureDue(42);
        $paiement = $this->payerParTerminal($facture, $this->terminal());

        $this->postJson("/api/v1/facturation/paiements/{$paiement}/simuler-carte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paiement');

        $this->assertSame([], $this->simules);
        $this->assertSame('en_attente', Paiement::query()->findOrFail($paiement)->statut->value);
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
    }

    public function test_la_carte_de_test_est_refusee_sur_un_paiement_qui_n_attend_plus(): void
    {
        $paiement = $this->payerParTerminal($this->factureDue(42), $this->terminal(modele: 'simulated_wisepos_e', lecteur: 'tmr_simule'));
        $this->postJson("/api/v1/facturation/paiements/{$paiement}/annuler", [], $this->enTetes($this->jeton))->assertOk();

        $this->postJson("/api/v1/facturation/paiements/{$paiement}/simuler-carte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paiement');

        $this->assertSame([], $this->simules);
    }

    public function test_la_carte_de_test_d_une_autre_clinique_ou_sans_la_caisse_est_refusee(): void
    {
        $paiement = $this->payerParTerminal($this->factureDue(42), $this->terminal(modele: 'simulated_wisepos_e', lecteur: 'tmr_simule'));

        $this->postJson("/api/v1/facturation/paiements/{$paiement}/simuler-carte", [], $this->enTetes($this->assistantAvecJeton()))
            ->assertForbidden();

        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);
        $this->postJson("/api/v1/facturation/paiements/{$paiement}/simuler-carte", [], $this->enTetes($jetonSoeur))
            ->assertNotFound();

        $this->assertSame([], $this->simules);
    }
}
