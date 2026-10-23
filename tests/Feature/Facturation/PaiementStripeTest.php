<?php

namespace Tests\Feature\Facturation;

use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Paiements de caisse par Stripe (docs/data-model.md §6) : carte sur le
 * terminal de la clinique, TWINT par QR ; l'issue arrive par webhook
 * (signé, dédupliqué) ou par l'interrogation de la caisse, et encaisse
 * la facture une seule fois.
 */
class PaiementStripeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private PasserellePaiementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
    }

    public function test_la_carte_part_au_terminal_et_le_webhook_encaisse_la_facture_une_fois(): void
    {
        $facture = $this->factureDue(85.5);
        $terminal = $this->terminal();

        $reponse = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
            'moyen' => 'tpe',
            'terminal_id' => $terminal->id,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'en_attente')
            ->assertJsonPath('data.terminal.libelle', 'Comptoir');

        $intention = Paiement::query()->findOrFail($reponse->json('data.id'))->stripe_payment_intent_id;

        $this->assertSame([['lecteur' => 'tmr_comptoir', 'intention' => $intention]], $this->stripe->presentations);
        $this->assertSame(8550, $this->stripe->intentions[$intention]['centimes']);
        $this->assertSame((string) $facture->id, $this->stripe->intentions[$intention]['metadonnees']['facture_id']);

        $evenement = $this->evenement('evt_1', 'payment_intent.succeeded', ['id' => $intention, 'status' => 'succeeded']);
        $this->webhook($evenement)->assertNoContent();
        $this->webhook($evenement)->assertNoContent();

        $facture->refresh();
        $this->assertSame(StatutFacture::Payee, $facture->statut);
        $this->assertSame('tpe', $facture->moyen_paiement->value);
        $this->assertSame('reussi', Paiement::query()->sole()->statut->value);
    }

    public function test_la_caisse_qui_interroge_voit_le_paiement_reussi_et_la_facture_payee(): void
    {
        $facture = $this->factureDue(40);
        $paiementId = $this->payerParTerminal($facture);

        $this->stripe->conclure(Paiement::query()->findOrFail($paiementId)->stripe_payment_intent_id, 'succeeded');

        $this->getJson("/api/v1/facturation/paiements/{$paiementId}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'reussi')
            ->assertJsonPath('data.facture.statut', 'payee')
            ->assertJsonPath('data.facture.moyen_paiement', 'tpe');
    }

    public function test_une_carte_refusee_fait_echouer_la_tentative_sans_encaisser(): void
    {
        $facture = $this->factureDue(40);
        $paiementId = $this->payerParTerminal($facture);

        $this->stripe->conclure(Paiement::query()->findOrFail($paiementId)->stripe_payment_intent_id, 'requires_payment_method', 'Carte refusée.');

        $this->getJson("/api/v1/facturation/paiements/{$paiementId}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'echoue')
            ->assertJsonPath('data.erreur', 'Carte refusée.');

        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
    }

    public function test_apres_un_refus_la_meme_intention_est_representee_au_terminal(): void
    {
        $facture = $this->factureDue(40);
        $paiementId = $this->payerParTerminal($facture);
        $intention = Paiement::query()->findOrFail($paiementId)->stripe_payment_intent_id;
        $this->stripe->conclure($intention, 'requires_payment_method', 'Carte refusée.');
        $this->getJson("/api/v1/facturation/paiements/{$paiementId}", $this->enTetes($this->jeton))->assertJsonPath('data.statut', 'echoue');

        $this->assertSame($paiementId, $this->payerParTerminal($facture));
        $this->assertSame('en_attente', Paiement::query()->sole()->statut->value);
        $this->assertSame([$intention, $intention], array_column($this->stripe->presentations, 'intention'));
    }

    public function test_un_terminal_hors_ligne_renvoie_un_message_lisible(): void
    {
        $facture = $this->factureDue(40);
        $this->stripe->erreurLecteur = new ErreurPaiement('Le terminal est hors ligne.', 'terminal_reader_offline');

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
            'moyen' => 'tpe',
            'terminal_id' => $this->terminal()->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', 'Le terminal est hors ligne.');

        $this->assertSame('echoue', Paiement::query()->sole()->statut->value);
    }

    public function test_annuler_libere_le_terminal_et_annule_l_intention(): void
    {
        $paiementId = $this->payerParTerminal($this->factureDue(40));

        $this->postJson("/api/v1/facturation/paiements/{$paiementId}/annuler", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'annule');

        $this->assertSame(['tmr_comptoir'], $this->stripe->lecteursLiberes);
    }

    public function test_une_nouvelle_tentative_annule_la_precedente(): void
    {
        $facture = $this->factureDue(40);
        $premiere = $this->payerParTerminal($facture);
        $seconde = $this->payerParTerminal($facture);

        $this->assertNotSame($premiere, $seconde);
        $this->assertSame('annule', Paiement::query()->findOrFail($premiere)->statut->value);
    }

    public function test_twint_renvoie_l_url_a_afficher_en_qr(): void
    {
        $facture = $this->factureDue(30);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.moyen', 'twint')
            ->assertJsonPath('data.statut', 'en_attente')
            ->assertJsonPath('data.url_twint', 'https://pm-redirects.stripe.com/twint/pi_1');
    }

    public function test_une_intention_inconnue_est_ignoree_et_une_signature_invalide_refusee(): void
    {
        $this->webhook($this->evenement('evt_x', 'payment_intent.succeeded', ['id' => 'pi_autre_app']))->assertNoContent();

        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 'fausse'])->assertStatus(400);
    }

    public function test_appairer_deux_terminaux_cree_la_location_de_la_clinique_une_seule_fois(): void
    {
        foreach (['Comptoir', 'Consultation 2'] as $libelle) {
            $this->postJson('/api/v1/facturation/terminaux', [
                'code_appairage' => 'simulated-wpe',
                'libelle' => $libelle,
            ], $this->enTetes($this->jeton))
                ->assertCreated()
                ->assertJsonPath('data.libelle', $libelle)
                ->assertJsonPath('data.en_ligne', true);
        }

        $this->assertCount(1, $this->stripe->locations);
        $this->assertSame('tml_1', $this->clinique->refresh()->stripe_location_id);

        $this->getJson('/api/v1/facturation/terminaux', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_la_liste_des_factures_indique_celles_payees_par_stripe(): void
    {
        $facture = $this->factureDue(40);
        $paiementId = $this->payerParTerminal($facture);
        $this->stripe->conclure(Paiement::query()->findOrFail($paiementId)->stripe_payment_intent_id, 'succeeded');
        $this->getJson("/api/v1/facturation/paiements/{$paiementId}", $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/facturation/factures?a_cloturer=1', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.paye_par_stripe', true);
    }

    public function test_la_facture_ne_change_pas_pendant_qu_un_paiement_attend(): void
    {
        $facture = $this->factureDue(50);
        $this->payerParTerminal($facture);

        $ligne = $facture->lignes()->firstOrFail();
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 3], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('paiements');
        $this->assertSame(1, (int) $ligne->refresh()->quantite);
    }

    public function test_un_paiement_qui_ne_correspond_plus_au_reste_est_rendu_et_la_facture_reste_due(): void
    {
        $facture = $this->factureDue(50);
        $paiement = Paiement::query()->findOrFail($this->payerParTerminal($facture));
        // La facture a changé par un autre chemin pendant l'attente
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 420, 'quantite' => 1]);

        $this->webhook($this->evenement('evt_ecart', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded']))->assertNoContent();

        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
        $this->assertSame('rembourse', $paiement->refresh()->statut->value);
        $this->assertSame([$paiement->stripe_payment_intent_id], $this->stripe->remboursements);
    }

    public function test_l_encaissement_en_especes_annule_le_paiement_en_cours_et_un_paiement_tardif_est_rendu(): void
    {
        $facture = $this->factureDue(40);
        $paiement = Paiement::query()->findOrFail($this->payerParTerminal($facture));

        // Le client paie finalement en espèces : la tentative au terminal est annulée
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'cash'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('annule', $paiement->refresh()->statut->value);
        $this->assertSame(StatutFacture::Payee, $facture->refresh()->statut);

        // Un second paiement qui aboutirait quand même sur la facture payée lui est rendu
        $intention = $this->stripe->creerIntentionTwint(4000, ['facture_id' => (string) $facture->id], 'https://retour', 'cle-tardive')['id'];
        $tardif = Paiement::query()->create([
            'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => 'twint', 'montant' => 40,
            'stripe_payment_intent_id' => $intention, 'statut' => 'en_attente',
        ]);
        $this->webhook($this->evenement('evt_tardif', 'payment_intent.succeeded', ['id' => $intention, 'status' => 'succeeded']))->assertNoContent();
        $this->assertSame('rembourse', $tardif->refresh()->statut->value);
        $this->assertContains($intention, $this->stripe->remboursements);
    }

    private function factureDue(float $montant): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant, 'quantite' => 1]);

        return $facture;
    }

    private function terminal(): TerminalPaiement
    {
        return TerminalPaiement::query()->firstOrCreate(
            ['stripe_reader_id' => 'tmr_comptoir'],
            ['clinique_id' => $this->clinique->id, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e'],
        );
    }

    private function payerParTerminal(Facture $facture): int
    {
        return $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
            'moyen' => 'tpe',
            'terminal_id' => $this->terminal()->id,
        ], $this->enTetes($this->jeton))->assertSuccessful()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $objet
     * @return array<string, mixed>
     */
    private function evenement(string $id, string $type, array $objet): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $objet]];
    }

    /** @param  array<string, mixed>  $evenement */
    private function webhook(array $evenement): TestResponse
    {
        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'signature-valide',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($evenement));
    }
}
