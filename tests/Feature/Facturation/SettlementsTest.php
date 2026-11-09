<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\RendezVous;
use App\Models\Settlement;
use App\Models\TerminalPaiement;
use App\Models\User;
use App\Paiements\PasserellePaiement;
use App\Services\Facturation\PatientDebts;
use App\Services\Facturation\Settlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Les règlements des factures (demande de Jim, 09.11.2026) : une ligne
 * par paiement reçu, datée du jour où l'argent est arrivé ; paiement
 * partiel et plusieurs moyens ; une erreur s'annule par une ligne
 * négative, jamais en effaçant ; un remboursement Stripe est une ligne
 * négative et la facture redevient due. Un test par case R1 à R28 de
 * notes/todo.md (web), section « Règlements des factures ».
 */
class SettlementsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private User $caissier;

    private PasserellePaiementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        // L'administrateur par défaut : encaisser et arrêter la caisse
        ['user' => $this->caissier, 'jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
    }

    private function factureDue(float $montant, array $attributs = []): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, ...$attributs]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant, 'quantite' => 1]);

        return $facture->refresh();
    }

    /** @param  array<string, mixed>  $donnees */
    private function encaisser(Facture $facture, array $donnees): TestResponse
    {
        return $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", $donnees, $this->enTetes($this->jeton));
    }

    private function annuler(Facture $facture, int $settlement, ?string $motif = 'Saisi deux fois'): TestResponse
    {
        return $this->postJson("/api/v1/facturation/factures/{$facture->id}/reglements/{$settlement}/annuler", ['motif' => $motif], $this->enTetes($this->jeton));
    }

    /** @return list<float> */
    private function montants(Facture $facture): array
    {
        return Settlement::query()->where('facture_id', $facture->id)->orderBy('id')->pluck('amount')->map(fn ($m): float => (float) $m)->all();
    }

    private function terminal(): TerminalPaiement
    {
        return TerminalPaiement::query()->firstOrCreate(
            ['stripe_reader_id' => 'tmr_comptoir'],
            ['clinique_id' => $this->clinique->id, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e'],
        );
    }

    /** @param  array<string, mixed>  $objet */
    private function webhook(string $id, string $type, array $objet, ?int $created = null): TestResponse
    {
        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'signature-valide',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => $id, 'type' => $type, 'created' => $created, 'data' => ['object' => $objet]]));
    }

    private function aujourdhuiLocal(): string
    {
        return CarbonImmutable::now($this->clinique->fuseau_horaire ?: 'Europe/Zurich')->toDateString();
    }

    /** R1. Une table des règlements, avec chacune de ses colonnes. */
    public function test_r1_une_table_des_reglements_avec_ses_colonnes(): void
    {
        $this->assertTrue(Schema::hasColumns('settlements', [
            'facture_id', 'clinique_id', 'received_on', 'amount', 'method', 'reference',
            'paiement_id', 'cloture_caisse_id', 'recorded_by_id', 'reverses_id', 'reason',
        ]));
        $facture = $this->factureDue(100);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 40, 'recu_le' => '2026-01-05', 'reference' => 'RF18 5390'])->assertOk();
        $ligne = Settlement::query()->where('facture_id', $facture->id)->sole();
        $this->assertSame([$this->clinique->id, '2026-01-05', 40.0, 'virement', 'RF18 5390', $this->caissier->id], [$ligne->clinique_id, $ligne->received_on->toDateString(), (float) $ligne->amount, $ligne->method->value, $ligne->reference, $ligne->recorded_by_id]);
    }

    /** R2. Le reste à payer est le total moins la somme des règlements. */
    public function test_r2_le_reste_est_le_total_moins_les_reglements(): void
    {
        $facture = $this->factureDue(250);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 100])->assertOk()->assertJsonPath('data.reste_a_payer', 150)->assertJsonPath('data.montant_regle', 100);
        $this->encaisser($facture, ['moyen_paiement' => 'tpe', 'montant' => 30])->assertOk()->assertJsonPath('data.reste_a_payer', 120);
    }

    /** R3. Payée quand les règlements atteignent le total, datée du règlement qui la solde. */
    public function test_r3_payee_quand_les_reglements_atteignent_le_total_a_la_date_du_dernier(): void
    {
        $facture = $this->factureDue(300);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 100])->assertOk()->assertJsonPath('data.statut', 'due');
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 200, 'recu_le' => '2026-01-07'])->assertOk()->assertJsonPath('data.statut', 'payee');
        $payeeLe = $facture->refresh()->payee_le->setTimezone($this->clinique->fuseau_horaire ?: 'Europe/Zurich');
        $this->assertSame('2026-01-07', $payeeLe->toDateString());
    }

    /** R4. Une ligne négative qui la fait repasser sous son total : de nouveau due. */
    public function test_r4_une_ligne_negative_la_rend_de_nouveau_due(): void
    {
        $facture = $this->factureDue(80);
        $id = $this->encaisser($facture, ['moyen_paiement' => 'cash'])->assertOk()->assertJsonPath('data.statut', 'payee')->json('data.reglements.0.id');
        $this->annuler($facture, $id)->assertOk()->assertJsonPath('data.statut', 'due')->assertJsonPath('data.reste_a_payer', 80);
        $this->assertNull($facture->refresh()->payee_le);
    }

    /**
     * R5 et R6. La reprise : chaque facture payée reçoit le règlement de son
     * reste à sa date de paiement avec son moyen (et sa clôture) ; l'acompte
     * en ligne devient un règlement « acompte ». Rejoué sur une base vidée.
     */
    public function test_r5_r6_la_reprise_pose_le_reglement_des_factures_payees_et_l_acompte(): void
    {
        $cloture = ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id]);
        $payee = $this->factureDue(150, ['acompte' => 50]);
        $payee->forceFill(['statut' => StatutFacture::Payee, 'moyen_paiement' => MoyenPaiement::Especes, 'payee_le' => CarbonImmutable::parse('2026-03-02 09:00', 'UTC'), 'cloture_caisse_id' => $cloture->id])->save();
        $due = $this->factureDue(90, ['acompte' => 20]);
        DB::table('settlements')->delete();

        $migration = require database_path('migrations/2026_11_09_000823_create_settlements_table.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);

        $lignes = Settlement::query()->where('facture_id', $payee->id)->orderBy('id')->get();
        $this->assertSame([['acompte', 50.0, null], ['cash', 100.0, $cloture->id]], $lignes->map(fn (Settlement $s): array => [$s->method->value, (float) $s->amount, $s->cloture_caisse_id])->all());
        $this->assertSame('2026-03-02', $lignes[1]->received_on->toDateString());
        $this->assertSame([20.0], $this->montants($due));
        $this->assertSame(70.0, $due->refresh()->load('lignes')->resteAPayer());
    }

    /** R7. L'acompte payé à la réservation devient un règlement à la création de la facture, daté de son paiement. */
    public function test_r7_l_acompte_de_la_reservation_est_un_reglement_date_de_son_paiement(): void
    {
        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 4000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => CarbonImmutable::parse('2026-02-20 10:00', 'UTC')]);

        $facture = $this->factureDue(120, ['rendez_vous_id' => $rdv->id, 'acompte' => 40]);

        $ligne = Settlement::query()->where('facture_id', $facture->id)->sole();
        $this->assertSame(['acompte', 40.0, '2026-02-20'], [$ligne->method->value, (float) $ligne->amount, $ligne->received_on->toDateString()]);
        $this->assertSame(80.0, $facture->load('lignes')->resteAPayer());
    }

    /** R8. Un acompte rendu chez Stripe après la création de la facture ajoute une ligne négative (une seule, même rejoué). */
    public function test_r8_un_acompte_rendu_apres_la_facture_ajoute_une_ligne_negative(): void
    {
        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 4000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => now()->subDays(3)]);
        $facture = $this->factureDue(120, ['rendez_vous_id' => $rdv->id, 'acompte' => 40]);

        $this->webhook('evt_a1', 'charge.refunded', ['payment_intent' => 'pi_acompte', 'refunded' => false, 'amount_refunded' => 1500])->assertNoContent();
        $this->webhook('evt_a1_bis', 'charge.refunded', ['payment_intent' => 'pi_acompte', 'refunded' => false, 'amount_refunded' => 1500])->assertNoContent();

        $this->assertSame([40.0, -15.0], $this->montants($facture));
        $this->assertSame(95.0, $facture->refresh()->load('lignes')->resteAPayer());
    }

    /** R9. Un paiement partiel en espèces, carte, TWINT ou virement. */
    public function test_r9_un_paiement_partiel_par_chaque_moyen_de_la_caisse(): void
    {
        foreach (['cash', 'tpe', 'twint', 'virement'] as $moyen) {
            $facture = $this->factureDue(200);
            $this->encaisser($facture, ['moyen_paiement' => $moyen, 'montant' => 60])->assertOk()
                ->assertJsonPath('data.statut', 'due')->assertJsonPath('data.reste_a_payer', 140)->assertJsonPath('data.payee_en_partie', true);
            $this->assertSame([$moyen], Settlement::query()->where('facture_id', $facture->id)->pluck('method')->map->value->all());
        }
    }

    /** R10. Plus que le reste est refusé ; en espèces, le montant reçu peut dépasser, le rendu se calcule. */
    public function test_r10_plus_que_le_reste_est_refuse_sauf_le_recu_en_especes(): void
    {
        $facture = $this->factureDue(50);
        $this->encaisser($facture, ['moyen_paiement' => 'tpe', 'montant' => 60])->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->assertSame([], $this->montants($facture));

        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 50, 'montant_recu' => 100])->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame([50.0], $this->montants($facture));
    }

    /** R11. Plusieurs règlements de moyens différents soldent une même facture. */
    public function test_r11_plusieurs_moyens_soldent_une_facture(): void
    {
        $facture = $this->factureDue(300);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 100])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'tpe', 'montant' => 200])->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame(['cash', 'tpe'], Settlement::query()->where('facture_id', $facture->id)->orderBy('id')->pluck('method')->map->value->all());
    }

    /** R12. Le terminal demande le montant choisi ; réussi, il devient un règlement de ce montant. */
    public function test_r12_le_terminal_demande_le_montant_choisi_et_le_regle(): void
    {
        $facture = $this->factureDue(180);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id, 'montant' => 50], $this->enTetes($this->jeton))->assertSuccessful()->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->assertSame(5000, $this->stripe->intentions[$paiement->stripe_payment_intent_id]['centimes']);

        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->assertSame([50.0], $this->montants($facture));
        $this->assertSame($paiement->id, Settlement::query()->where('facture_id', $facture->id)->sole()->paiement_id);
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
    }

    /** R12 (TWINT). TWINT demande aussi le montant choisi. */
    public function test_r12_twint_demande_le_montant_choisi(): void
    {
        $facture = $this->factureDue(180);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'twint', 'montant' => 70], $this->enTetes($this->jeton))->assertSuccessful()->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->assertSame(7000, $this->stripe->intentions[$paiement->stripe_payment_intent_id]['centimes']);
    }

    /** R13. Réussi mais plus grand que le reste du moment : rendu, sans règlement. */
    public function test_r13_un_paiement_stripe_plus_grand_que_le_reste_est_rendu_sans_reglement(): void
    {
        $facture = $this->factureDue(100);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->assertSuccessful()->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        app(Settlements::class)->record($facture, SettlementMethod::Especes, 40);

        $this->webhook('evt_trop', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->assertSame('rembourse', $paiement->refresh()->statut->value);
        $this->assertSame([40.0], $this->montants($facture));
    }

    /** R14. Un virement porte la date d'arrivée : aujourd'hui par défaut, une date passée permise, jamais une date future. */
    public function test_r14_la_date_d_arrivee_d_un_virement(): void
    {
        $facture = $this->factureDue(300);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 100])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 100, 'recu_le' => '2025-12-28'])->assertOk();
        $demain = CarbonImmutable::parse($this->aujourdhuiLocal())->addDay()->toDateString();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 100, 'recu_le' => $demain])->assertUnprocessable()->assertJsonValidationErrors('recu_le');

        $this->assertSame([$this->aujourdhuiLocal(), '2025-12-28'], Settlement::query()->where('facture_id', $facture->id)->orderBy('id')->get()->map(fn (Settlement $s): string => $s->received_on->toDateString())->all());
    }

    /** R15. Un virement peut porter une référence, facultative. */
    public function test_r15_un_virement_peut_porter_une_reference(): void
    {
        $facture = $this->factureDue(300);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 100, 'reference' => '21 00000 00003 13947 14300 09017'])->assertOk()
            ->assertJsonPath('data.reglements.0.reference', '21 00000 00003 13947 14300 09017');
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 100])->assertOk()->assertJsonPath('data.reglements.1.reference', null);
    }

    /** R16. Annuler ajoute une ligne négative du même montant, avec le motif obligatoire, qui et quand ; rien n'est effacé. */
    public function test_r16_annuler_ajoute_une_ligne_negative_avec_motif_qui_et_quand(): void
    {
        $facture = $this->factureDue(200);
        $id = $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 70])->json('data.reglements.0.id');

        $this->annuler($facture, $id, '')->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->annuler($facture, $id, 'Saisi sur la mauvaise facture')->assertOk();

        $annulation = Settlement::query()->where('reverses_id', $id)->sole();
        $this->assertSame([-70.0, 'cash', 'Saisi sur la mauvaise facture', $this->caissier->id, $this->aujourdhuiLocal()], [(float) $annulation->amount, $annulation->method->value, $annulation->reason, $annulation->recorded_by_id, $annulation->received_on->toDateString()]);
        $this->assertNotNull($annulation->created_at);
        $this->assertSame([70.0, -70.0], $this->montants($facture));
    }

    /** R17. Un règlement ne s'annule qu'une fois ; une ligne d'annulation ne s'annule pas. */
    public function test_r17_une_seule_annulation_et_jamais_d_une_annulation(): void
    {
        $facture = $this->factureDue(200);
        $id = $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 70])->json('data.reglements.0.id');
        $this->annuler($facture, $id)->assertOk();

        $this->annuler($facture, $id)->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->annuler($facture, Settlement::query()->where('reverses_id', $id)->value('id'))->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->assertSame([70.0, -70.0], $this->montants($facture));
    }

    /** R18. Un règlement passé par Stripe ne s'annule pas à la main ; l'acompte non plus. */
    public function test_r18_ni_un_reglement_stripe_ni_l_acompte_ne_s_annulent_a_la_main(): void
    {
        $facture = $this->factureDue(180, ['acompte' => 30]);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id, 'montant' => 50], $this->enTetes($this->jeton))->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $stripe = Settlement::query()->where('paiement_id', $paiement->id)->sole();
        $acompte = Settlement::query()->where('facture_id', $facture->id)->where('method', 'acompte')->sole();
        $this->annuler($facture, $stripe->id)->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->annuler($facture, $acompte->id)->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $reglements = collect($this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->json('data.reglements'));
        $this->assertSame([false, false], $reglements->pluck('annulable')->all());
    }

    /** R19. Un remboursement chez Stripe, entier ou partiel, ajoute une ligne négative datée du remboursement ; rejoué, rien de plus. */
    public function test_r19_un_remboursement_stripe_est_une_ligne_negative_une_seule_fois(): void
    {
        $facture = $this->factureDue(120);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->webhook('evt_r1', 'charge.refunded', ['payment_intent' => $paiement->stripe_payment_intent_id, 'refunded' => false, 'amount_refunded' => 2500])->assertNoContent();
        $this->webhook('evt_r1_bis', 'charge.refunded', ['payment_intent' => $paiement->stripe_payment_intent_id, 'refunded' => false, 'amount_refunded' => 2500])->assertNoContent();

        $this->assertSame([120.0, -25.0], $this->montants($facture));
        $this->assertSame($this->aujourdhuiLocal(), Settlement::query()->where('facture_id', $facture->id)->where('amount', '<', 0)->sole()->received_on->toDateString());
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
        $this->assertSame(25.0, $facture->load('lignes')->resteAPayer());
    }

    /** R20. Un litige perdu ajoute une ligne négative du montant perdu. */
    public function test_r20_un_litige_perdu_est_une_ligne_negative(): void
    {
        $facture = $this->factureDue(90);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->webhook('evt_d1', 'charge.dispute.created', ['payment_intent' => $paiement->stripe_payment_intent_id])->assertNoContent();
        $this->webhook('evt_d2', 'charge.dispute.closed', ['payment_intent' => $paiement->stripe_payment_intent_id, 'status' => 'lost'])->assertNoContent();

        $this->assertSame([90.0, -90.0], $this->montants($facture));
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
    }

    /** R21. Le Z compte les règlements de caisse qu'aucune clôture ne couvre, par moyen (ni virement ni acompte). */
    public function test_r21_le_z_compte_les_reglements_de_caisse_non_couverts(): void
    {
        $facture = $this->factureDue(300, ['acompte' => 20]);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 100])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'tpe', 'montant' => 50])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 30])->assertOk();

        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.moyens.cash.montant', 100)
            ->assertJsonPath('data.moyens.tpe.montant', 50)
            ->assertJsonPath('data.total', 150)
            ->assertJsonPath('data.encaissements', 2);
    }

    /** R22. Une annulation faite après la clôture tombe dans le Z suivant, en négatif. */
    public function test_r22_une_annulation_apres_la_cloture_va_au_z_suivant_en_negatif(): void
    {
        $facture = $this->factureDue(200);
        $id = $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 80])->json('data.reglements.0.id');
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 280, 'nouveau_fond' => 200], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.total_especes', 80);
        $this->assertNotNull(Settlement::query()->findOrFail($id)->cloture_caisse_id);

        $this->annuler($facture, $id)->assertOk();

        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.moyens.cash.montant', -80)->assertJsonPath('data.encaissements', 1);
    }

    /** R23. L'encaissé du jour compte les règlements reçus ce jour, par moyen. */
    public function test_r23_l_encaisse_du_jour_compte_les_reglements_du_jour(): void
    {
        $facture = $this->factureDue(300);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 100])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'tpe', 'montant' => 50])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 25, 'recu_le' => '2025-12-30'])->assertOk();

        $this->getJson('/api/v1/facturation/statistiques', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.du_jour.especes', 100)
            ->assertJsonPath('data.du_jour.tpe', 50)
            ->assertJsonPath('data.du_jour.virement', 0)
            ->assertJsonPath('data.du_jour.total', 150)
            ->assertJsonPath('data.du_jour.nombre', 1);
    }

    /** R24. Le journal du mois liste chaque règlement du mois, avec sa TVA au prorata, annulations et remboursements compris. */
    public function test_r24_le_journal_liste_les_reglements_du_mois_avec_leur_tva(): void
    {
        $facture = $this->factureDue(216.20);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 108.10, 'recu_le' => '2026-01-10'])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 108.10, 'recu_le' => '2026-02-03'])->assertOk();
        $id = Settlement::query()->where('facture_id', $facture->id)->orderBy('id')->value('id');

        $janvier = $this->getJson('/api/v1/facturation/factures/journal?mois=2026-01', $this->enTetes($this->jeton))->assertOk();
        $janvier->assertJsonPath('data.encaissements.nombre', 1)
            ->assertJsonPath('data.encaissements.reglements.0.recu_le', '2026-01-10')
            ->assertJsonPath('data.encaissements.reglements.0.numero', $facture->numero)
            ->assertJsonPath('data.encaissements.reglements.0.moyen', 'virement')
            ->assertJsonPath('data.encaissements.reglements.0.montant', 108.1)
            ->assertJsonPath('data.encaissements.total_ttc', 108.1);
        // La moitié de la TVA de la facture
        $this->assertEqualsWithDelta($facture->load('lignes')->montantTva() / 2, $janvier->json('data.encaissements.montant_tva'), 0.011);

        $this->annuler($facture, $id)->assertOk();
        $mois = CarbonImmutable::parse($this->aujourdhuiLocal())->format('Y-m');
        $this->getJson('/api/v1/facturation/factures/journal?mois='.$mois, $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.encaissements.reglements.0.montant', -108.1)
            ->assertJsonPath('data.encaissements.reglements.0.motif', 'Saisi deux fois');
    }

    /** R25. Les factures ouvertes à une date, avec le reste à cette date. */
    public function test_r25_les_factures_ouvertes_a_une_date(): void
    {
        $facture = $this->factureDue(450, ['emise_le' => CarbonImmutable::parse('2025-12-20 10:00', 'UTC')]);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 200, 'recu_le' => '2025-12-28'])->assertOk();
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 250, 'recu_le' => '2026-01-15'])->assertOk();
        $this->factureDue(99, ['emise_le' => CarbonImmutable::parse('2026-01-02 10:00', 'UTC')]);

        $this->getJson('/api/v1/facturation/factures/ouvertes?date=2025-12-31', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.nombre', 1)
            ->assertJsonPath('data.factures.0.numero', $facture->numero)
            ->assertJsonPath('data.factures.0.regle', 200)
            ->assertJsonPath('data.factures.0.reste', 250)
            ->assertJsonPath('data.total_reste', 250);
        $this->getJson('/api/v1/facturation/factures/ouvertes?date=2026-01-31', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.nombre', 1)->assertJsonPath('data.factures.0.reste', 99);
    }

    /** R26. La dette de l'animal et le solde du propriétaire comptent les règlements. */
    public function test_r26_la_dette_de_l_animal_et_le_solde_du_proprietaire_comptent_les_reglements(): void
    {
        $patient = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $facture = $this->factureDue(300, ['patient_id' => $patient->id, 'client_id' => $patient->client_id]);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 120])->assertOk();

        $this->assertSame(180.0, (float) DB::table('patients')->where('id', $patient->id)->value('dette'));
        $solde = DB::table('clients')->where('id', $patient->client_id)->selectRaw(PatientDebts::ownerBalanceSql().' as solde')->value('solde');
        $this->assertSame(180.0, round((float) $solde, 2));
    }

    /** R27. Une vente du comptoir se paie en entier, et ne s'annule plus dès qu'elle a un règlement. */
    public function test_r27_une_vente_du_comptoir_se_paie_en_entier(): void
    {
        $vente = $this->factureDue(40);
        $vente->forceFill(['vente_comptoir' => true])->save();
        $this->encaisser($vente, ['moyen_paiement' => 'cash', 'montant' => 10])->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->postJson("/api/v1/facturation/factures/{$vente->id}/paiements", ['moyen' => 'twint', 'montant' => 10], $this->enTetes($this->jeton))->assertUnprocessable();

        app(Settlements::class)->record($vente, SettlementMethod::Especes, 10);
        $this->postJson("/api/v1/facturation/ventes/{$vente->id}/annuler", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('vente');
        $this->assertSame(StatutFacture::Due, $vente->refresh()->statut);
    }

    /** R28. La facture servie dit ses règlements, ce qui est réglé et si elle est payée en partie. */
    public function test_r28_la_facture_servie_dit_ses_reglements(): void
    {
        $facture = $this->factureDue(200);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 50])->assertOk();

        $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.montant_regle', 50)
            ->assertJsonPath('data.payee_en_partie', true)
            ->assertJsonPath('data.reglements.0.moyen', 'cash')
            ->assertJsonPath('data.reglements.0.montant', 50)
            ->assertJsonPath('data.reglements.0.recu_le', $this->aujourdhuiLocal())
            ->assertJsonPath('data.reglements.0.saisi_par', $this->caissier->name)
            ->assertJsonPath('data.reglements.0.annulable', true);
        $liste = collect($this->getJson('/api/v1/facturation/factures', $this->enTetes($this->jeton))->json('data'));
        $this->assertTrue($liste->firstWhere('id', $facture->id)['payee_en_partie']);
    }

    /** R36. Le « reste à payer » des statistiques compte les paiements partiels. */
    public function test_r36_les_statistiques_comptent_les_paiements_partiels_dans_le_du(): void
    {
        $facture = $this->factureDue(740, ['acompte' => 40]);
        $this->encaisser($facture, ['moyen_paiement' => 'virement', 'montant' => 240])->assertOk();
        $jour = $this->aujourdhuiLocal();

        $this->getJson('/api/v1/facturation/statistics?'.http_build_query(['from' => $jour, 'to' => $jour, 'compare' => 'none', 'history' => 0]), $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.indicators.due.total', 460)
            ->assertJsonPath('data.indicators.due.count', 1);
    }

    /** R37. Payée en partie : ni ligne ajoutée, ni quantité changée, ni ligne retirée. */
    public function test_r37_les_lignes_d_une_facture_payee_en_partie_ne_changent_plus(): void
    {
        $facture = $this->factureDue(200);
        $ligne = $facture->lignes()->sole();
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 50])->assertOk();
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
        $message = 'Cette facture est déjà payée en partie : annulez d’abord son règlement pour la modifier.';

        $prestation = Prestation::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'code' => 'SUPP-R']);
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['prestation_id' => $prestation->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonPath('errors.facture.0', $message);
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 2], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonPath('errors.facture.0', $message);
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonPath('errors.facture.0', $message);
        $this->assertSame(200.0, $facture->refresh()->load('lignes')->totalTtc());

        // Le règlement annulé, la facture se corrige de nouveau
        $this->annuler($facture, Settlement::query()->where('facture_id', $facture->id)->value('id'))->assertOk();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/lignes", ['prestation_id' => $prestation->id], $this->enTetes($this->jeton))->assertOk();
    }

    /** R38. Payée en partie, même par son créateur dans la fenêtre d'annulation : jamais supprimée ; ni après l'annulation du règlement (journal gardé, jamais d'erreur 500). */
    public function test_r38_une_facture_qui_a_recu_un_paiement_ne_se_supprime_plus(): void
    {
        $facture = $this->factureDue(200, ['cree_par_id' => $this->caissier->id]);
        $temoin = $this->factureDue(80, ['cree_par_id' => $this->caissier->id]);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 50])->assertOk();

        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('facture');
        $this->annuler($facture, Settlement::query()->where('facture_id', $facture->id)->value('id'))->assertOk();
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('facture');
        $this->assertNotNull(Facture::query()->find($facture->id));
        // Le témoin, sans règlement, se supprime dans la fenêtre
        $this->deleteJson("/api/v1/facturation/factures/{$temoin->id}", [], $this->enTetes($this->jeton))->assertNoContent();
    }

    /** R12 (TWINT). Un TWINT réussi devient un règlement de son montant. */
    public function test_r12_un_twint_reussi_est_regle_de_son_montant(): void
    {
        $facture = $this->factureDue(180);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'twint', 'montant' => 70], $this->enTetes($this->jeton))->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->webhook('evt_tw', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $ligne = Settlement::query()->where('facture_id', $facture->id)->sole();
        $this->assertSame(['twint', 70.0, $paiement->id], [$ligne->method->value, (float) $ligne->amount, $ligne->paiement_id]);
    }

    /** R19. Remboursé en entier : la ligne négative du reste, datée du remboursement chez Stripe ; le journal la liste. */
    public function test_r19_un_remboursement_entier_date_du_jour_stripe_et_au_journal(): void
    {
        $facture = $this->factureDue(120);
        $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->json('data.id');
        $paiement = Paiement::query()->findOrFail($id);
        $this->webhook('evt_ok', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();
        $this->webhook('evt_r1', 'charge.refunded', ['payment_intent' => $paiement->stripe_payment_intent_id, 'refunded' => false, 'amount_refunded' => 2000, 'refunds' => ['data' => [['created' => CarbonImmutable::parse('2026-01-05 23:30', 'UTC')->timestamp]]]])->assertNoContent();
        $this->webhook('evt_r2', 'charge.refunded', ['payment_intent' => $paiement->stripe_payment_intent_id, 'refunded' => true, 'amount_refunded' => 12000, 'refunds' => ['data' => [['created' => CarbonImmutable::parse('2026-01-05 23:30', 'UTC')->timestamp], ['created' => CarbonImmutable::parse('2026-01-07 09:00', 'UTC')->timestamp]]]])->assertNoContent();

        $negatives = Settlement::query()->where('facture_id', $facture->id)->where('amount', '<', 0)->orderBy('id')->get();
        // 23 h 30 UTC le 5 janvier : déjà le 6 à Zurich
        $this->assertSame([[-20.0, '2026-01-06'], [-100.0, '2026-01-07']], $negatives->map(fn (Settlement $x): array => [(float) $x->amount, $x->received_on->toDateString()])->all());
        $this->assertSame(StatutFacture::Due, $facture->refresh()->statut);
        $this->getJson('/api/v1/facturation/factures/journal?mois=2026-01', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.encaissements.reglements.1.montant', -100)
            ->assertJsonPath('data.encaissements.reglements.1.motif', 'Remboursé chez Stripe');
    }

    /** R19 et R20. Sans la liste des remboursements (Stripe ne la joint plus), la ligne se date de l'heure de l'événement, pas du jour où il arrive. */
    public function test_r19_r20_sans_liste_la_date_vient_de_l_evenement(): void
    {
        $factures = [$this->factureDue(120), $this->factureDue(90)];
        $paiements = [];
        foreach ($factures as $i => $facture) {
            $id = $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", ['moyen' => 'tpe', 'terminal_id' => $this->terminal()->id], $this->enTetes($this->jeton))->json('data.id');
            $paiements[$i] = Paiement::query()->findOrFail($id);
            $this->webhook('evt_ok_'.$i, 'payment_intent.succeeded', ['id' => $paiements[$i]->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();
        }
        $hier = CarbonImmutable::parse($this->aujourdhuiLocal(), 'Europe/Zurich')->subDay()->setTime(23, 50);

        $this->webhook('evt_r', 'charge.refunded', ['payment_intent' => $paiements[0]->stripe_payment_intent_id, 'refunded' => false, 'amount_refunded' => 3000], $hier->timestamp)->assertNoContent();
        $this->webhook('evt_d1', 'charge.dispute.created', ['payment_intent' => $paiements[1]->stripe_payment_intent_id])->assertNoContent();
        $this->webhook('evt_d2', 'charge.dispute.closed', ['payment_intent' => $paiements[1]->stripe_payment_intent_id, 'status' => 'lost'], $hier->timestamp)->assertNoContent();

        foreach ($factures as $facture) {
            $this->assertSame($hier->toDateString(), Settlement::query()->where('facture_id', $facture->id)->where('amount', '<', 0)->sole()->received_on->toDateString());
        }
    }

    /** R27. Une vente du comptoir qui a reçu un règlement ne s'annule plus, même après l'annulation de ce règlement. */
    public function test_r27_une_vente_reglee_puis_annulee_ne_s_annule_toujours_pas(): void
    {
        $vente = $this->factureDue(40);
        $vente->forceFill(['vente_comptoir' => true])->save();
        $ligne = app(Settlements::class)->record($vente, SettlementMethod::Especes, 40);
        app(Settlements::class)->reverse($vente->refresh(), $ligne, 'Erreur de caisse', $this->caissier);

        $this->postJson("/api/v1/facturation/ventes/{$vente->id}/annuler", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('vente');
        $this->assertSame(StatutFacture::Due, $vente->refresh()->statut);
    }

    /** Vérification (M1) : un acompte rendu ne compte plus dans la répartition des statistiques. */
    public function test_un_acompte_rendu_ne_compte_pas_deux_fois_dans_les_statistiques(): void
    {
        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 5000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => now()->subDay()]);
        $facture = $this->factureDue(100, ['rendez_vous_id' => $rdv->id, 'acompte' => 50]);
        $this->webhook('evt_a', 'charge.refunded', ['payment_intent' => 'pi_acompte', 'refunded' => true, 'amount_refunded' => 5000])->assertNoContent();
        $this->encaisser($facture, ['moyen_paiement' => 'cash'])->assertOk()->assertJsonPath('data.statut', 'payee');
        $jour = $this->aujourdhuiLocal();

        $moyens = collect($this->getJson('/api/v1/facturation/statistics?'.http_build_query(['from' => $jour, 'to' => $jour, 'compare' => 'none', 'history' => 0]), $this->enTetes($this->jeton))->assertOk()->json('data.payment_methods'));
        // 100 en espèces, plus rien d'acompte : la répartition fait la facture, pas 150
        $this->assertEqualsWithDelta(100.0, $moyens->sum('total'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $moyens->firstWhere('key', 'deposit')['total'], 0.001);
    }

    /** Vérification (M2) : un acompte plus grand que la facture n'apporte au journal que la TVA de la facture. */
    public function test_la_tva_du_journal_ne_depasse_pas_celle_de_la_facture(): void
    {
        $facture = $this->factureDue(50, ['acompte' => 80]);
        $mois = CarbonImmutable::parse($this->aujourdhuiLocal())->format('Y-m');

        $journal = $this->getJson('/api/v1/facturation/factures/journal?mois='.$mois, $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(80.0, (float) $journal->json('data.encaissements.reglements.0.montant'));
        $this->assertEqualsWithDelta($facture->load('lignes')->montantTva(), (float) $journal->json('data.encaissements.montant_tva'), 0.001);
        // Les 30 de trop ne sont pas du chiffre d'affaires hors taxe
        $this->assertEqualsWithDelta(30.0, (float) $journal->json('data.encaissements.excedent'), 0.001);
        $this->assertEqualsWithDelta($facture->totalHt(), (float) $journal->json('data.encaissements.total_ht'), 0.001);
    }

    /** Vérification (M3) : la reprise inscrit ce qui a été rendu avant elle, et la facture redevient due. */
    public function test_la_reprise_inscrit_les_remboursements_d_avant(): void
    {
        $entier = $this->factureDue(100);
        $entier->forceFill(['statut' => StatutFacture::Payee, 'moyen_paiement' => MoyenPaiement::Tpe, 'payee_le' => now()->subDays(5)])->save();
        $p1 = Paiement::query()->create(['facture_id' => $entier->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Tpe, 'montant' => 100, 'stripe_payment_intent_id' => 'pi_entier', 'statut' => 'rembourse', 'erreur' => 'Remboursé (CHF 100.00).']);
        $partiel = $this->factureDue(100);
        $partiel->forceFill(['statut' => StatutFacture::Payee, 'moyen_paiement' => MoyenPaiement::Twint, 'payee_le' => now()->subDays(5)])->save();
        Paiement::query()->create(['facture_id' => $partiel->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Twint, 'montant' => 100, 'stripe_payment_intent_id' => 'pi_partiel', 'statut' => 'reussi', 'erreur' => 'Remboursé en partie : CHF 1’020.00.']);
        $rendu = $this->factureDue(100);
        $rendu->forceFill(['statut' => StatutFacture::Payee, 'moyen_paiement' => MoyenPaiement::Especes, 'payee_le' => now()->subDays(5)])->save();
        // Un paiement rendu aussitôt (payé deux fois) n'a jamais payé la facture
        Paiement::query()->create(['facture_id' => $rendu->id, 'clinique_id' => $this->clinique->id, 'moyen' => MoyenPaiement::Tpe, 'montant' => 100, 'stripe_payment_intent_id' => 'pi_rendu', 'statut' => 'rembourse', 'erreur' => 'Facture déjà payée par un autre moyen : ce paiement a été remboursé.']);
        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 4000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => now()->subDays(9), 'acompte_rembourse_le' => now()->subDays(2), 'acompte_rembourse_centimes' => 1500]);
        $acompte = $this->factureDue(120, ['rendez_vous_id' => $rdv->id, 'acompte' => 40]);
        DB::table('settlements')->delete();

        $migration = require database_path('migrations/2026_11_09_000823_create_settlements_table.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);

        $this->assertSame([100.0, -100.0], $this->montants($entier));
        $this->assertSame($p1->id, Settlement::query()->where('facture_id', $entier->id)->where('amount', '>', 0)->value('paiement_id'));
        $this->assertSame(StatutFacture::Due, $entier->refresh()->statut);
        // Plus que le paiement : borné à ce qui avait été payé
        $this->assertSame([100.0, -100.0], $this->montants($partiel));
        $this->assertSame([100.0], $this->montants($rendu));
        $this->assertSame(StatutFacture::Payee, $rendu->refresh()->statut);
        $this->assertNull(Settlement::query()->where('facture_id', $rendu->id)->value('paiement_id'));
        $this->assertSame([40.0, -15.0], $this->montants($acompte));
        // Plus rien d'annulable à la main : la ligne est liée à son paiement Stripe
        $this->assertFalse(Settlement::query()->where('facture_id', $entier->id)->where('amount', '>', 0)->sole()->isReversible());
    }

    /** Vérification (L2) : un Z suivant compte la facture de son second règlement. */
    public function test_un_z_suivant_compte_la_facture_de_son_second_reglement(): void
    {
        $facture = $this->factureDue(200);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 80])->assertOk();
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 280, 'nouveau_fond' => 200], $this->enTetes($this->jeton))->assertCreated()->assertJsonPath('data.nombre_factures', 1);
        $this->encaisser($facture, ['moyen_paiement' => 'cash', 'montant' => 120])->assertOk();

        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 320, 'nouveau_fond' => 200], $this->enTetes($this->jeton))->assertCreated()
            ->assertJsonPath('data.nombre_factures', 1)
            ->assertJsonPath('data.facture_ids', [$facture->id]);
        $liste = collect($this->getJson('/api/v1/facturation/clotures-caisse', $this->enTetes($this->jeton))->assertOk()->json('data'));
        $this->assertSame([1, 1], $liste->pluck('nombre_factures')->all());
    }
}
