<?php

namespace Tests\Feature\Paiements;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Paiement;
use App\Models\Role;
use App\Models\Societe;
use App\Models\TerminalPaiement;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Compte de paiement de chaque société (Stripe Connect sans tableau de
 * bord, 23.10.2026) : l'administrateur l'ouvre et s'inscrit dans l'app ;
 * terminal, TWINT et acomptes encaissent sur ce compte, la plateforme
 * prenant 0,5 % sur le terminal ; rien n'encaisse tant qu'il n'est pas actif.
 */
class ComptePaiementSocieteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private PasserellePaiementFactice $stripe;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
        $societe = Societe::factory()->sansComptePaiement()->create(['nom' => 'Deux Rives Sàrl']);
        $this->clinique = Clinique::factory()->create(['societe_id' => $societe->id, 'fuseau_horaire' => 'Europe/Zurich']);
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_sans_compte_actif_ni_terminal_ni_acompte(): void
    {
        $this->getJson('/api/v1/facturation/compte-paiement', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouvert', false)
            ->assertJsonPath('data.societe.nom', 'Deux Rives Sàrl')
            ->assertJsonPath('data.commission_terminal', 0.5)
            ->assertJsonPath('data.peut_gerer', false);

        $this->postJson('/api/v1/facturation/terminaux', ['code_appairage' => 'simulated-wpe', 'libelle' => 'Comptoir'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paiements');

        $this->administrateur();
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", [
            'services' => [], 'especes' => ['CH'], 'affiche_veterinaires' => true, 'acompte_centimes' => 3000,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acompte_centimes');

        // Un acompte réglé auparavant n'est pas demandé tant que le compte n'encaisse pas
        $this->clinique->update(['acompte_centimes' => 3000]);
        $this->assertNull($this->clinique->refresh()->acompteExigible());
    }

    public function test_l_administrateur_ouvre_le_compte_et_les_composants_integres_suivent_les_droits(): void
    {
        $this->postJson('/api/v1/facturation/compte-paiement', [], $this->enTetes($this->jeton))->assertForbidden();
        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['payments', 'payouts']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('composants');

        $this->administrateur();
        $this->stripe->etatsComptes['acct_1'] = ['paiements' => false, 'virements' => false, 'a_completer' => true];
        $this->postJson('/api/v1/facturation/compte-paiement', [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouvert', true)
            ->assertJsonPath('data.paiements_actifs', false)
            ->assertJsonPath('data.a_completer', true)
            ->assertJsonPath('data.peut_gerer', true);
        $this->assertSame('Deux Rives Sàrl', $this->stripe->comptesCrees[0]['nom']);
        $this->assertSame('acct_1', $this->clinique->societe->refresh()->stripe_compte_id);
        // Rouvrir ne recrée rien
        $this->postJson('/api/v1/facturation/compte-paiement', [], $this->enTetes($this->jeton))->assertOk();
        $this->assertCount(1, $this->stripe->comptesCrees);

        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['account_onboarding']], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.client_secret', fn ($secret) => str_starts_with($secret, 'accs_secret_'));
        $this->assertSame(['compte' => 'acct_1', 'composants' => ['account_onboarding']], $this->stripe->sessions[0]);

        // Un vétérinaire (facturation sans organisation) voit paiements et virements, pas l'inscription
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::VETERINAIRE)
            ->value('id')]);
        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['account_management']], $this->enTetes($this->jeton))->assertForbidden();
        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['payments', 'payouts', 'notification_banner']], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_le_terminal_encaisse_sur_le_compte_de_la_societe_avec_la_commission(): void
    {
        $this->clinique->societe->update(['stripe_compte_id' => 'acct_deux_rives', 'paiements_actifs' => true]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 120, 'quantite' => 1]);
        $terminal = TerminalPaiement::query()->create(['clinique_id' => $this->clinique->id, 'stripe_reader_id' => 'tmr_1', 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e']);

        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $terminal->id], $this->enTetes($this->jeton))
            ->assertCreated()->json('data.id');
        $intention = $this->stripe->intentions[Paiement::query()->findOrFail($id)->stripe_payment_intent_id];
        $this->assertSame('acct_deux_rives', $intention['compte']);
        $this->assertSame(12000, $intention['centimes']);
        // 0,5 % de 120 CHF
        $this->assertSame(60, $intention['commission']);

        // TWINT à la caisse : pas de commission tant qu'elle n'est pas réglée
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))
            ->assertCreated()->json('data.id');
        $twint = $this->stripe->intentions[Paiement::query()->findOrFail($id)->stripe_payment_intent_id];
        $this->assertSame(['acct_deux_rives', 0], [$twint['compte'], $twint['commission']]);
    }

    public function test_l_acompte_se_paie_sur_le_compte_de_la_societe(): void
    {
        Mail::fake();
        $this->clinique->societe->update(['stripe_compte_id' => 'acct_deux_rives', 'paiements_actifs' => true]);
        $this->clinique->update(['acompte_centimes' => 3000, 'rdv_sans_compte' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $mardi = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);

        $paiement = $this->postJson('/api/v1/public/demandes', [
            'clinique_id' => $this->clinique->id, 'patient_name' => 'Louison', 'espece' => 'CH', 'nom_proprietaire' => 'Mme Pineau',
            'email' => 'pineau@exemple.ch', 'telephone' => '079 512 44 08', 'motif' => 'Vaccin', 'mode' => 'place',
            'debut_souhaite' => $mardi->toIso8601String(),
        ])->assertCreated()->json('data.paiement');

        $this->assertSame('acct_deux_rives', $paiement['compte']);
        $this->assertSame('acct_deux_rives', collect($this->stripe->intentions)->last()['compte']);
    }

    public function test_un_compte_modifie_chez_le_prestataire_est_relu(): void
    {
        $this->clinique->societe->update(['stripe_compte_id' => 'acct_deux_rives', 'paiements_actifs' => true, 'virements_actifs' => true]);
        $this->stripe->etatsComptes['acct_deux_rives'] = ['paiements' => false, 'virements' => true, 'a_completer' => true];

        $this->call('POST', '/api/v1/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'signature-valide', 'CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 'evt_compte', 'type' => 'account.updated', 'account' => 'acct_deux_rives', 'data' => ['object' => ['id' => 'acct_deux_rives']],
        ]))->assertNoContent();

        $societe = $this->clinique->societe->refresh();
        $this->assertFalse($societe->paiements_actifs);
        $this->assertTrue($societe->paiements_a_completer);
    }

    /** L'employé du jeton devient administrateur : le domaine organisation s'ouvre. */
    private function administrateur(): void
    {
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);
    }
}
