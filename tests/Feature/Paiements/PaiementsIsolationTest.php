<?php

namespace Tests\Feature\Paiements;

use App\Auth\ContexteClinique;
use App\Enums\StatutPaiement;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\Societe;
use App\Models\TerminalPaiement;
use App\Paiements\PasserellePaiement;
use Database\Factories\PaiementFactory;
use Database\Factories\TerminalPaiementFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Isolation des paiements de caisse : terminaux et tentatives de paiement
 * sont PAR CLINIQUE (une clinique sœur ne les voit pas plus qu'une autre
 * organisation), le compte de paiement est celui de la SOCIÉTÉ de la
 * clinique du jeton, jamais celui d'une autre société ou organisation.
 */
class PaiementsIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private PasserellePaiementFactice $stripe;

    private Clinique $clinique;

    private Clinique $soeur;

    private Clinique $etrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);

        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->etrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_liste_des_terminaux_ne_montre_que_ceux_de_la_clinique_du_jeton(): void
    {
        $sien = TerminalPaiementFactory::new()->create(['clinique_id' => $this->clinique->id]);
        TerminalPaiementFactory::new()->create(['clinique_id' => $this->soeur->id]);
        TerminalPaiementFactory::new()->create(['clinique_id' => $this->etrangere->id]);

        $this->getJson('/api/v1/facturation/terminaux', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sien->id);
    }

    public function test_le_terminal_d_une_autre_clinique_ne_se_retire_pas(): void
    {
        $chezLaSoeur = TerminalPaiementFactory::new()->create(['clinique_id' => $this->soeur->id]);
        $ailleurs = TerminalPaiementFactory::new()->create(['clinique_id' => $this->etrangere->id]);

        $this->deleteJson("/api/v1/facturation/terminaux/{$chezLaSoeur->id}", [], $this->enTetes($this->jeton))->assertNotFound();
        $this->deleteJson("/api/v1/facturation/terminaux/{$ailleurs->id}", [], $this->enTetes($this->jeton))->assertNotFound();

        $this->assertSame(2, TerminalPaiement::withoutGlobalScopes()->whereKey([$chezLaSoeur->id, $ailleurs->id])->count());
    }

    public function test_le_paiement_d_une_autre_clinique_ne_se_lit_ni_ne_s_annule(): void
    {
        $chezLaSoeur = $this->paiementEnAttente($this->soeur);
        $ailleurs = $this->paiementEnAttente($this->etrangere);
        $entetes = $this->enTetes($this->jeton);

        foreach ([$chezLaSoeur, $ailleurs] as $paiement) {
            $this->getJson("/api/v1/facturation/paiements/{$paiement->id}", $entetes)->assertNotFound();
            $this->postJson("/api/v1/facturation/paiements/{$paiement->id}/annuler", [], $entetes)->assertNotFound();
            $this->postJson("/api/v1/facturation/paiements/{$paiement->id}/simuler-carte", [], $entetes)->assertNotFound();

            $this->assertSame(StatutPaiement::EnAttente, Paiement::withoutGlobalScopes()->findOrFail($paiement->id)->statut);
        }
    }

    public function test_payer_sur_le_terminal_d_une_autre_clinique_est_refuse(): void
    {
        $facture = $this->factureDue($this->clinique);
        $chezLaSoeur = TerminalPaiementFactory::new()->create(['clinique_id' => $this->soeur->id]);
        $ailleurs = TerminalPaiementFactory::new()->create(['clinique_id' => $this->etrangere->id]);

        foreach ([$chezLaSoeur, $ailleurs] as $terminal) {
            $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
                'moyen' => 'tpe',
                'terminal_id' => $terminal->id,
            ], $this->enTetes($this->jeton))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['terminal_id' => 'Ce terminal n’appartient pas à la clinique.']);
        }

        $this->assertSame(0, Paiement::withoutGlobalScopes()->count());
        $this->assertSame([], $this->stripe->presentations);
    }

    public function test_la_facture_d_une_autre_organisation_ne_se_paie_pas_et_celle_d_une_soeur_pas_d_ici(): void
    {
        $terminal = TerminalPaiementFactory::new()->create(['clinique_id' => $this->clinique->id]);
        $etrangere = $this->factureDue($this->etrangere);
        $soeur = $this->factureDue($this->soeur);
        $charge = ['moyen' => 'tpe', 'terminal_id' => $terminal->id];

        $this->postJson("/api/v1/facturation/factures/{$etrangere->id}/paiements", $charge, $this->enTetes($this->jeton))
            ->assertNotFound();
        $this->postJson("/api/v1/facturation/factures/{$soeur->id}/paiements", $charge, $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['facture' => 'Les gestes de caisse se font depuis la clinique d’émission de la facture.']);

        $this->assertSame(0, Paiement::withoutGlobalScopes()->count());
        $this->assertSame([], $this->stripe->intentions);
    }

    public function test_le_compte_de_paiement_est_celui_de_la_societe_de_la_clinique_du_jeton(): void
    {
        // Une autre société de la même organisation a son propre compte
        $autreSociete = Societe::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $cliniqueAutreSociete = Clinique::factory()->create(['societe_id' => $autreSociete->id]);
        ['jeton' => $jetonAutreSociete] = $this->employeAvecJeton($cliniqueAutreSociete);

        $this->getJson('/api/v1/facturation/compte-paiement', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.societe.id', $this->clinique->societe_id);
        $this->getJson('/api/v1/facturation/compte-paiement', $this->enTetes($jetonAutreSociete))
            ->assertOk()
            ->assertJsonPath('data.societe.id', $autreSociete->id);

        // Les composants intégrés (paiements, virements) ouvrent le compte de sa société, jamais un autre
        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['payments']], $this->enTetes($this->jeton))->assertOk();
        $this->postJson('/api/v1/facturation/compte-paiement/session', ['composants' => ['payments']], $this->enTetes($jetonAutreSociete))->assertOk();

        $this->assertSame(
            [$this->clinique->societe->stripe_compte_id, $autreSociete->stripe_compte_id],
            array_column($this->stripe->sessions, 'compte'),
        );
        $this->assertNotContains($this->etrangere->societe->stripe_compte_id, array_column($this->stripe->sessions, 'compte'));
    }

    public function test_le_reglage_des_paiements_integres_ne_touche_que_la_clinique_du_jeton(): void
    {
        ['jeton' => $administrateur] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $administrateurEtranger] = $this->administrateurAvecJeton($this->etrangere);

        $this->putJson('/api/v1/facturation/compte-paiement/integration', ['integres' => false], $this->enTetes($administrateur))
            ->assertOk()
            ->assertJsonPath('data.integres', false);
        $this->putJson('/api/v1/facturation/compte-paiement/integration', ['integres' => false], $this->enTetes($administrateurEtranger))
            ->assertOk();

        $integres = Clinique::withoutGlobalScopes()->pluck('paiements_integres', 'id');
        $this->assertFalse($integres[$this->clinique->id]);
        $this->assertTrue($integres[$this->soeur->id]);
        $this->assertFalse($integres[$this->etrangere->id]);
    }

    private function factureDue(Clinique $clinique): Facture
    {
        app(ContexteClinique::class)->oublier();

        $facture = Facture::factory()->create(['clinique_id' => $clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 85.5, 'quantite' => 1]);

        return $facture;
    }

    /** Une carte en attente sur le terminal (simulé) de la clinique, comme la caisse la lance. */
    private function paiementEnAttente(Clinique $clinique): Paiement
    {
        $terminal = TerminalPaiementFactory::new()->simule()->create(['clinique_id' => $clinique->id]);

        return PaiementFactory::new()->surTerminal($terminal)->create([
            'facture_id' => $this->factureDue($clinique)->id,
            'stripe_compte_id' => $clinique->societe->stripe_compte_id,
        ]);
    }
}
