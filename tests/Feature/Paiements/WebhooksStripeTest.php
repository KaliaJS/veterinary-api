<?php

namespace Tests\Feature\Paiements;

use App\Mail\DemandeRendezVousAcceptee;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\EvenementStripe;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\Societe;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Webhooks Stripe (audit du 23.10.2026) : refus, annulation, échec qui
 * arrive après le succès, acompte confirmé sans retour du navigateur,
 * traitement en échec (Stripe renvoie), lecteur en échec, succès d'un
 * autre montant que la facture. Les événements viennent du compte de la
 * société, comme en production (Connect).
 */
class WebhooksStripeTest extends TestCase
{
    use DeposeSansCompte;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const COMPTE = 'acct_deux_rives';

    private PasserellePaiementFactice $stripe;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
        $societe = Societe::factory()->create(['stripe_compte_id' => self::COMPTE]);
        $this->clinique = Clinique::factory()->create(['societe_id' => $societe->id, 'fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_un_paiement_refuse_echoue_avec_le_motif_de_la_banque_et_peut_etre_represente(): void
    {
        $facture = $this->factureDue(80);
        $paiement = $this->payerParTerminal($facture);

        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'requires_payment_method', 'Carte refusée par la banque.');
        $this->webhook('evt_refus', 'payment_intent.payment_failed', [
            'id' => $paiement->stripe_payment_intent_id, 'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'expired_card', 'message' => 'Your card has expired.'],
        ])->assertNoContent();

        // Dit en français d'après le code, jamais le message anglais de Stripe
        $this->assertSame(['echoue', 'La carte a expiré.'], [$paiement->refresh()->statut->value, $paiement->erreur]);
        $this->assertSame('due', $facture->refresh()->statut->value);

        // Le client tend une autre carte : la même intention repart au terminal, sans double débit possible
        $this->assertSame($paiement->id, $this->payerParTerminal($facture)->id);
        $this->assertSame([$paiement->stripe_payment_intent_id, $paiement->stripe_payment_intent_id], array_column($this->stripe->presentations, 'intention'));
        $this->assertCount(1, $this->stripe->intentions);

        // Sans motif de la banque : un message par défaut
        $autre = $this->payerParTerminal($this->factureDue(40));
        $this->webhook('evt_refus_2', 'payment_intent.payment_failed', ['id' => $autre->stripe_payment_intent_id, 'status' => 'requires_payment_method'])->assertNoContent();
        $this->assertSame(['echoue', 'Paiement refusé par la banque.'], [$autre->refresh()->statut->value, $autre->erreur]);
    }

    public function test_un_paiement_annule_chez_stripe_est_annule_et_la_facture_reste_a_encaisser(): void
    {
        $facture = $this->factureDue(60);
        $paiement = Paiement::query()->findOrFail($this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'twint'], $this->enTetes($this->jeton))
            ->assertCreated()->json('data.id'));

        // QR expiré : Stripe annule l'intention
        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'canceled');
        $this->webhook('evt_annule', 'payment_intent.canceled', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'canceled'])->assertNoContent();

        $this->assertSame('annule', $paiement->refresh()->statut->value);
        $this->assertSame('due', $facture->refresh()->statut->value);
        // Une nouvelle tentative s'ouvre sans toucher à l'intention annulée
        $this->payerParTerminal($facture);
        $this->assertSame([], $this->stripe->appelsDe('annulerIntention'));
        $this->assertCount(2, $this->stripe->intentions);
    }

    public function test_un_echec_ou_une_annulation_arrivant_apres_le_succes_ne_defait_rien(): void
    {
        $facture = $this->factureDue(80);
        $paiement = $this->payerParTerminal($facture);
        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();
        $payeeLe = $facture->refresh()->payee_le;

        // Stripe ne garantit pas l'ordre : un refus antérieur et une annulation arrivent après
        $this->webhook('evt_refus', 'payment_intent.payment_failed', [
            'id' => $paiement->stripe_payment_intent_id, 'status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Carte refusée.'],
        ])->assertNoContent();
        $this->webhook('evt_annule', 'payment_intent.canceled', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'canceled'])->assertNoContent();
        $this->webhook('evt_lecteur', 'terminal.reader.action_failed', $this->actionEchouee($paiement, 'Carte retirée trop tôt.'))->assertNoContent();

        $this->assertSame(['reussi', null], [$paiement->refresh()->statut->value, $paiement->erreur]);
        $facture->refresh();
        $this->assertSame(['payee', 'tpe'], [$facture->statut->value, $facture->moyen_paiement->value]);
        $this->assertTrue($payeeLe->equalTo($facture->payee_le));
        $this->assertSame([], $this->stripe->rendus);
    }

    public function test_un_succes_apres_un_refus_encaisse_la_facture(): void
    {
        $facture = $this->factureDue(80);
        $paiement = $this->payerParTerminal($facture);

        $this->webhook('evt_refus', 'payment_intent.payment_failed', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'requires_payment_method'])->assertNoContent();
        $this->assertSame('echoue', $paiement->refresh()->statut->value);

        // Une seconde carte sur la même intention passe
        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->assertSame(['reussi', null], [$paiement->refresh()->statut->value, $paiement->erreur]);
        $this->assertSame('payee', $facture->refresh()->statut->value);
    }

    public function test_l_acompte_paye_est_confirme_par_le_webhook_sans_retour_du_navigateur(): void
    {
        $jeton = $this->deposerAvecAcompte();
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $acompte = ['id' => $demande->acompte_intention_id, 'status' => 'succeeded', 'metadata' => ['objet' => 'acompte_rdv', 'demande_id' => (string) $demande->id]];

        // Un refus de carte sur l'acompte ne change rien : le client réessaie dans le parcours
        $this->webhook('evt_refus', 'payment_intent.payment_failed', [...$acompte, 'status' => 'requires_payment_method'], self::COMPTE)->assertNoContent();
        $this->assertSame('a_payer', $demande->refresh()->statut->value);

        // Payé, mais l'événement vient d'un autre compte : ignoré
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->webhook('evt_autre', 'payment_intent.succeeded', $acompte, 'acct_autre')->assertNoContent();
        $this->assertSame('a_payer', $demande->refresh()->statut->value);

        // Du compte qui l'a reçu : l'heure est réservée, la confirmation part
        $this->webhook('evt_ok', 'payment_intent.succeeded', $acompte, self::COMPTE)->assertNoContent();
        $demande->refresh();
        $this->assertSame('acceptee', $demande->statut->value);
        $this->assertNotNull($demande->acompte_paye_le);
        $this->assertSame(132, $demande->acompte_frais_centimes);
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch'));

        // Renvoyé par Stripe, puis le navigateur revient quand même : un seul rendez-vous
        $this->webhook('evt_ok_bis', 'payment_intent.succeeded', $acompte, self::COMPTE)->assertNoContent();
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');
        $this->assertSame(1, RendezVous::withoutGlobalScopes()->count());
        $this->assertSame([], $this->stripe->rendus);
    }

    public function test_un_traitement_en_echec_libere_l_evenement_pour_que_stripe_le_renvoie(): void
    {
        $societe = $this->clinique->societe;
        $this->stripe->etatsComptes[self::COMPTE] = ['paiements' => false, 'virements' => true, 'a_completer' => true];
        $this->stripe->erreurs['lireComptePaiement'] = new ErreurPaiement(ErreurPaiement::GENERIQUE);

        // Stripe injoignable pendant le traitement : erreur, l'événement n'est pas marqué traité
        $this->webhook('evt_compte', 'account.updated', ['id' => self::COMPTE], self::COMPTE)->assertServerError();
        $this->assertFalse(EvenementStripe::query()->whereKey('evt_compte')->exists());
        $this->assertTrue($societe->refresh()->paiements_actifs);

        // Renvoyé : traité, puis ignoré s'il revient encore
        unset($this->stripe->erreurs['lireComptePaiement']);
        $this->webhook('evt_compte', 'account.updated', ['id' => self::COMPTE], self::COMPTE)->assertNoContent();
        $this->webhook('evt_compte', 'account.updated', ['id' => self::COMPTE], self::COMPTE)->assertNoContent();

        $this->assertFalse($societe->refresh()->paiements_actifs);
        $this->assertTrue($societe->paiements_a_completer);
        $this->assertCount(2, $this->stripe->appelsDe('lireComptePaiement'));
        $this->assertTrue(EvenementStripe::query()->whereKey('evt_compte')->exists());
    }

    public function test_un_lecteur_en_echec_fait_echouer_la_tentative_avec_son_message(): void
    {
        $facture = $this->factureDue(80);
        $paiement = $this->payerParTerminal($facture);

        // D'un autre compte : ignoré
        $this->webhook('evt_autre', 'terminal.reader.action_failed', $this->actionEchouee($paiement, 'insufficient_funds'), 'acct_autre')->assertNoContent();
        $this->assertSame('en_attente', $paiement->refresh()->statut->value);

        // Le motif vient du code, en français : le message de Stripe n'est jamais montré
        $this->webhook('evt_lecteur', 'terminal.reader.action_failed', $this->actionEchouee($paiement, 'insufficient_funds'))->assertNoContent();
        $this->assertSame(['echoue', 'Fonds insuffisants sur la carte.'], [$paiement->refresh()->statut->value, $paiement->erreur]);
        $this->assertSame('due', $facture->refresh()->statut->value);

        // Sans message du lecteur : un message par défaut ; une intention inconnue ne fait rien
        $autre = $this->payerParTerminal($this->factureDue(40));
        $this->webhook('evt_lecteur_2', 'terminal.reader.action_failed', $this->actionEchouee($autre, null))->assertNoContent();
        $this->assertSame(['echoue', 'Le paiement n’a pas abouti sur le terminal.'], [$autre->refresh()->statut->value, $autre->erreur]);
        $this->webhook('evt_lecteur_3', 'terminal.reader.action_failed', ['id' => 'tmr_comptoir', 'action' => ['process_payment_intent' => ['payment_intent' => 'pi_autre_app']]])->assertNoContent();
    }

    public function test_un_succes_d_un_autre_montant_que_la_facture_est_rendu_et_le_reste_apres_acompte_encaisse(): void
    {
        // La facture a baissé pendant l'attente (ligne retirée par un autre chemin) : le paiement est rendu
        $facture = $this->factureDue(80);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 20, 'quantite' => 1]);
        $paiement = $this->payerParTerminal($facture);
        $facture->lignes()->where('prix_chf', 20)->delete();
        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_ecart', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded', 'amount_received' => 10000])->assertNoContent();

        $this->assertSame('rembourse', $paiement->refresh()->statut->value);
        $this->assertSame('due', $facture->refresh()->statut->value);
        $this->assertSame([[$paiement->stripe_payment_intent_id, self::COMPTE, 10000]], array_map(fn (array $rendu) => [$rendu['intention'], $rendu['compte'], $rendu['centimes']], $this->stripe->rendus));

        // L'acompte payé en ligne est déduit : le paiement du reste n'est pas un écart, il encaisse
        $apresAcompte = $this->factureDue(120);
        $apresAcompte->update(['acompte' => 30]);
        $reste = $this->payerParTerminal($apresAcompte);
        $this->assertSame(9000, $this->stripe->intentions[$reste->stripe_payment_intent_id]['centimes']);
        $this->stripe->conclure($reste->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_reste', 'payment_intent.succeeded', ['id' => $reste->stripe_payment_intent_id, 'status' => 'succeeded', 'amount_received' => 9000])->assertNoContent();

        $this->assertSame('reussi', $reste->refresh()->statut->value);
        $this->assertSame('payee', $apresAcompte->refresh()->statut->value);
        $this->assertCount(1, $this->stripe->rendus);
    }

    private function factureDue(float $montant): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant, 'quantite' => 1]);

        return $facture;
    }

    private function payerParTerminal(Facture $facture): Paiement
    {
        $terminal = TerminalPaiement::query()->firstOrCreate(
            ['stripe_reader_id' => 'tmr_comptoir'],
            ['clinique_id' => $this->clinique->id, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e'],
        );

        return Paiement::query()->findOrFail($this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", [
            'moyen' => 'tpe',
            'terminal_id' => $terminal->id,
        ], $this->enTetes($this->jeton))->assertSuccessful()->json('data.id'));
    }

    /** @return array<string, mixed> Le lecteur tel que Stripe le décrit après l'échec de son action */
    private function actionEchouee(Paiement $paiement, ?string $code): array
    {
        return ['id' => 'tmr_comptoir', 'object' => 'terminal.reader', 'action' => array_filter([
            'type' => 'process_payment_intent',
            'status' => 'failed',
            'failure_code' => $code,
            'failure_message' => $code ? 'Card declined (in English, never shown).' : null,
            'process_payment_intent' => ['payment_intent' => $paiement->stripe_payment_intent_id],
        ])];
    }

    /** Dépôt sans compte avec un acompte de 30 CHF ; renvoie le jeton de suivi */
    private function deposerAvecAcompte(): string
    {
        Mail::fake();
        $this->clinique->update(['acompte_centimes' => 3000, 'rdv_sans_compte' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $mardi = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);

        return $this->postJson('/api/v1/public/demandes', [
            'code' => $this->codeDeDemande($this->clinique->id, 'pineau@exemple.ch'),
            'clinique_id' => $this->clinique->id, 'patient_name' => 'Louison', 'espece' => 'CH', 'nom_proprietaire' => 'Mme Pineau',
            'email' => 'pineau@exemple.ch', 'telephone' => '079 512 44 08', 'motif' => 'Vaccin annuel', 'mode' => 'place',
            'debut_souhaite' => $mardi->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.statut', 'a_payer')->json('data.jeton');
    }

    /** @param  array<string, mixed>  $objet */
    private function webhook(string $id, string $type, array $objet, ?string $compte = self::COMPTE): TestResponse
    {
        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'signature-valide',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(array_filter(['id' => $id, 'type' => $type, 'account' => $compte, 'data' => ['object' => $objet]])));
    }
}
