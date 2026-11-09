<?php

namespace Tests\Feature\Facturation;

use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Enums\VatContext;
use App\Models\AccountingLock;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Paiements\PasserellePaiement;
use App\Services\Facturation\Settlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * L'export comptable (point e, 09.11.2026) : les écritures d'un mois de la
 * société, en partie double dans l'ordre de Banana, et le mois remis à la
 * fiduciaire. Un test par case E1 à E16 de notes/todo.md (web), section
 * « Export comptable ».
 */
class EcrituresComptablesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['user' => $this->admin, 'jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
    }

    /** @param  list<array{0: float, 1: string, 2?: ?string}>  $lignes  [montant, type, contexte] */
    private function facture(string $emiseLeUtc, array $lignes, array $attributs = [], ?Clinique $clinique = null): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => ($clinique ?? $this->clinique)->id, 'emise_le' => CarbonImmutable::parse($emiseLeUtc, 'UTC'), 'patient_name' => 'Rex', 'owner_name' => 'P. Meunier', ...$attributs]);
        foreach ($lignes as $l) {
            $ligne = FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => $l[0], 'type' => $l[1], 'vat_context' => $l[2] ?? null, 'produit_id' => null]);
            // Le taux se calcule à la naissance de la ligne : celui voulu, posé ensuite
            if (isset($l[3])) {
                FactureLigne::query()->whereKey($ligne->id)->update(['taux_tva' => $l[3]]);
            }
        }

        return $facture->refresh();
    }

    private function ecritures(string $mois, ?string $jeton = null): TestResponse
    {
        return $this->getJson('/api/v1/facturation/ecritures?mois='.$mois, $this->enTetes($jeton ?? $this->jeton));
    }

    /** @return list<array<string, mixed>> les montants en nombres décimaux (le JSON écrit 100 pour 100.0) */
    private function lignes(string $mois): array
    {
        return array_map(fn (array $l): array => ['amount' => (float) $l['amount']] + $l, $this->ecritures($mois)->assertOk()->json('data.lignes'));
    }

    /** E1. Les écritures servies sont celles de la société : ses deux cliniques, pas une autre société. */
    public function test_e1_les_ecritures_de_toute_la_societe(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $autreSociete = Clinique::factory()->create();
        $this->facture('2026-03-10 09:00', [[100, 'acte']]);
        $this->facture('2026-03-11 09:00', [[50, 'acte']], [], $soeur);
        $this->facture('2026-03-12 09:00', [[70, 'acte']], [], $autreSociete);

        $this->assertSame([100.0, 50.0], array_column($this->lignes('2026-03'), 'amount'));
    }

    /** E2. Une facture : clients au débit, prestations pour un acte, marchandises pour un article, TTC. */
    public function test_e2_une_facture_clients_au_debit_produits_au_credit(): void
    {
        $acte = $this->facture('2026-03-10 09:00', [[108.10, 'acte']]);
        $this->facture('2026-03-11 09:00', [[54.05, 'med']]);

        $lignes = $this->lignes('2026-03');
        $this->assertSame(['2026-03-10', $acte->numero, '1100', '3400', 108.1], [$lignes[0]['date'], $lignes[0]['doc'], $lignes[0]['debit'], $lignes[0]['credit'], $lignes[0]['amount']]);
        $this->assertSame(['1100', '3200', 54.05], [$lignes[1]['debit'], $lignes[1]['credit'], $lignes[1]['amount']]);
        $this->assertStringContainsString('Rex', $lignes[0]['description']);
    }

    /** E3. Plusieurs comptes ou taux : une ligne du total au débit, puis une ligne par compte et taux au crédit. */
    public function test_e3_une_facture_decoupee_par_compte_et_par_taux(): void
    {
        $this->facture('2026-03-10 09:00', [[100, 'acte', null, 8.1], [40, 'med', null, 2.6], [10, 'med', null, 2.6]]);

        $lignes = $this->lignes('2026-03');
        $this->assertSame([
            ['1100', '', 150.0, ''],
            ['', '3400', 100.0, 'V81'],
            ['', '3200', 50.0, 'V26'],
        ], array_map(fn (array $l): array => [$l['debit'], $l['credit'], $l['amount'], $l['vat_code']], $lignes));
    }

    /** E4. Le code TVA de chaque taux ; aucun pour une société non assujettie. */
    public function test_e4_le_code_tva_de_chaque_taux_et_aucun_hors_tva(): void
    {
        $this->facture('2026-03-10 09:00', [[100, 'acte', null, 8.1]]);
        $this->assertSame('V81', $this->lignes('2026-03')[0]['vat_code']);

        $this->clinique->societe->update(['assujettie_tva' => false]);
        $this->assertSame('', $this->lignes('2026-03')[0]['vat_code']);
    }

    /** E5. Une remise sans taux se répartit ; la somme des lignes fait le total. */
    public function test_e5_une_remise_se_repartit_et_la_somme_fait_le_total(): void
    {
        $this->facture('2026-03-10 09:00', [[100, 'acte', null, 8.1], [50, 'med', null, 2.6], [-15, 'acte', VatContext::Discount->value]]);

        $lignes = $this->lignes('2026-03');
        $this->assertSame(135.0, $lignes[0]['amount']);
        $this->assertSame([90.0, 45.0], [$lignes[1]['amount'], $lignes[2]['amount']]);
        $this->assertEqualsWithDelta(135.0, $lignes[1]['amount'] + $lignes[2]['amount'], 0.001);
    }

    /** E6. Un règlement : le compte de son moyen au débit (caisse, banque, transfert), les clients au crédit. */
    public function test_e6_un_reglement_au_debit_du_compte_de_son_moyen(): void
    {
        $facture = $this->facture('2026-02-20 09:00', [[400, 'acte']]);
        $settlements = app(Settlements::class);
        $settlements->record($facture, SettlementMethod::Especes, 100, CarbonImmutable::parse('2026-03-02'));
        $settlements->record($facture, SettlementMethod::Virement, 100, CarbonImmutable::parse('2026-03-03'));
        $settlements->record($facture, SettlementMethod::Tpe, 100, CarbonImmutable::parse('2026-03-04'));
        $settlements->record($facture, SettlementMethod::Twint, 100, CarbonImmutable::parse('2026-03-05'));

        $this->assertSame([['1000', '1100'], ['1020', '1100'], ['1090', '1100'], ['1090', '1100']], array_map(fn (array $l): array => [$l['debit'], $l['credit']], $this->lignes('2026-03')));
    }

    /** E7. Un règlement négatif passe en sens inverse, montant positif. */
    public function test_e7_un_reglement_negatif_en_sens_inverse(): void
    {
        $facture = $this->facture('2026-02-20 09:00', [[400, 'acte']]);
        $ligne = app(Settlements::class)->record($facture, SettlementMethod::Especes, 100, CarbonImmutable::parse('2026-03-02'));
        $this->travelTo(CarbonImmutable::parse('2026-03-09 10:00', 'UTC'));
        app(Settlements::class)->reverse($facture->refresh(), $ligne, 'Saisi deux fois', $this->admin);

        $lignes = $this->lignes('2026-03');
        $this->assertSame(['1100', '1000', 100.0], [$lignes[1]['debit'], $lignes[1]['credit'], $lignes[1]['amount']]);
        $this->assertStringContainsString('Saisi deux fois', $lignes[1]['description']);
    }

    /** E8. Vente annulée un autre mois : l'écriture inverse au jour de l'annulation ; le même mois : rien. */
    public function test_e8_une_vente_annulee_un_autre_mois_s_inverse(): void
    {
        $this->facture('2026-03-10 09:00', [[40, 'med']], ['statut' => StatutFacture::Annulee, 'annulee_le' => CarbonImmutable::parse('2026-03-10 10:00', 'UTC')]);
        $tard = $this->facture('2026-03-30 09:00', [[60, 'med']]);
        $tard->forceFill(['statut' => StatutFacture::Annulee, 'annulee_le' => CarbonImmutable::parse('2026-04-02 08:00', 'UTC')])->save();

        $this->assertSame([[$tard->numero, '1100', '3200', 60.0]], array_map(fn (array $l): array => [$l['doc'], $l['debit'], $l['credit'], $l['amount']], $this->lignes('2026-03')));
        $avril = $this->lignes('2026-04');
        $this->assertSame([['2026-04-02', '3200', '1100', 60.0]], array_map(fn (array $l): array => [$l['date'], $l['debit'], $l['credit'], $l['amount']], $avril));
    }

    /** E9 et E10. Les comptes et les codes TVA par défaut, puis changés pour la société. */
    public function test_e9_e10_le_plan_par_defaut_puis_modifie(): void
    {
        $societe = $this->clinique->societe;
        $plan = $this->getJson('/api/v1/organisation/societes', $this->enTetes($this->jeton))->assertOk()->json('data.0.plan_comptable');
        $this->assertSame(['caisse' => '1000', 'banque' => '1020', 'transfert' => '1090', 'clients' => '1100', 'prestations' => '3400', 'marchandises' => '3200'], $plan['comptes']);
        $this->assertSame(['normal' => 'V81', 'reduced' => 'V26'], $plan['codes_tva']);

        $this->patchJson("/api/v1/organisation/societes/{$societe->id}", ['plan_comptable' => ['comptes' => ['clients' => '1105', 'prestations' => '3410'], 'codes_tva' => ['normal' => 'USN']]], $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.plan_comptable.comptes.clients', '1105')
            ->assertJsonPath('data.plan_comptable.comptes.caisse', '1000');
        $this->patchJson("/api/v1/organisation/societes/{$societe->id}", ['plan_comptable' => ['comptes' => ['clients' => '11 05; drop']]], $this->enTetes($this->jeton))->assertUnprocessable();

        $this->facture('2026-03-10 09:00', [[100, 'acte', null, 8.1]]);
        $this->assertSame(['1105', '3410', 'USN'], [$this->lignes('2026-03')[0]['debit'], $this->lignes('2026-03')[0]['credit'], $this->lignes('2026-03')[0]['vat_code']]);
    }

    /** E11. Un mois passé se remet à la fiduciaire, avec qui et quand ; il faut le droit de clôturer la caisse. */
    public function test_e11_remettre_un_mois_passe_avec_qui_et_quand(): void
    {
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->postJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($asv))->assertForbidden();

        $this->postJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.verrou.par', $this->admin->name);
        $this->assertNotNull($this->ecritures('2025-12')->json('data.verrou.locked_at'));
        $this->assertTrue(AccountingLock::query()->where('societe_id', $this->clinique->societe_id)->where('month', '2025-12')->exists());
    }

    /** E12. Le mois en cours ne se verrouille pas. */
    public function test_e12_le_mois_en_cours_ne_se_verrouille_pas(): void
    {
        $mois = aujourdhui($this->clinique)->format('Y-m');
        $this->postJson("/api/v1/facturation/ecritures/{$mois}/verrou", [], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('mois');
        $this->assertFalse($this->ecritures($mois)->json('data.peut_verrouiller'));
    }

    /** E13. Un mois verrouillé se rouvre, avec le même droit. */
    public function test_e13_un_mois_se_rouvre(): void
    {
        $this->postJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk();
        ['jeton' => $asv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        $this->deleteJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($asv))->assertForbidden();

        $this->deleteJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.verrou', null);
        $this->assertNull($this->ecritures('2025-12')->json('data.verrou'));
    }

    /** E14. Mois verrouillé : un virement daté dans ce mois est refusé. */
    public function test_e14_un_virement_dans_un_mois_verrouille_est_refuse(): void
    {
        $facture = $this->facture('2025-12-10 09:00', [[200, 'acte']]);
        AccountingLock::query()->create(['societe_id' => $this->clinique->societe_id, 'month' => '2025-12', 'locked_at' => now()]);

        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'virement', 'recu_le' => '2025-12-28'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('recu_le');
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'virement', 'recu_le' => '2026-01-04'], $this->enTetes($this->jeton))->assertOk();
    }

    /** E15. Mois verrouillé : un acompte ou un remboursement Stripe qui y tomberait est daté du jour, avec une note. */
    public function test_e15_acompte_et_remboursement_dates_du_jour_avec_une_note(): void
    {
        AccountingLock::query()->create(['societe_id' => $this->clinique->societe_id, 'month' => '2025-12', 'locked_at' => now()]);
        $aujourdhui = aujourdhui($this->clinique)->toDateString();

        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 3000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => CarbonImmutable::parse('2025-12-20 10:00', 'UTC')]);
        $facture = $this->facture(now()->toDateTimeString(), [[120, 'acte']], ['rendez_vous_id' => $rdv->id, 'acompte' => 30]);
        $acompte = $facture->settlements()->sole();
        $this->assertSame([$aujourdhui, 'Acompte payé le 20.12.2025, mois remis à la fiduciaire'], [$acompte->received_on->toDateString(), $acompte->reason]);

        $carte = $this->facture(now()->toDateTimeString(), [[80, 'acte']]);
        $paiement = Paiement::query()->create(['facture_id' => $carte->id, 'clinique_id' => $this->clinique->id, 'moyen' => 'tpe', 'montant' => 80, 'stripe_payment_intent_id' => 'pi_carte', 'statut' => 'reussi']);
        app(Settlements::class)->record($carte, SettlementMethod::Tpe, 80, null, null, null, $paiement);
        app(Settlements::class)->syncStripeRefund($paiement, 8000, 'Remboursé chez Stripe', CarbonImmutable::parse('2025-12-31 10:00', 'UTC'));
        $rendu = $carte->settlements()->where('amount', '<', 0)->sole();
        $this->assertSame([$aujourdhui, 'Remboursé chez Stripe le 31.12.2025, mois remis à la fiduciaire'], [$rendu->received_on->toDateString(), $rendu->reason]);
    }

    /** E16. Mois verrouillé : les lignes d'une facture émise dans ce mois ne changent plus. */
    public function test_e16_les_lignes_d_une_facture_d_un_mois_verrouille_ne_changent_plus(): void
    {
        $facture = $this->facture('2025-12-10 09:00', [[200, 'acte']]);
        $ligne = $facture->lignes()->sole();
        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 2], $this->enTetes($this->jeton))->assertOk();
        AccountingLock::query()->create(['societe_id' => $this->clinique->societe_id, 'month' => '2025-12', 'locked_at' => now()]);

        $this->patchJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", ['quantite' => 3], $this->enTetes($this->jeton))->assertUnprocessable()
            ->assertJsonPath('errors.facture.0', 'Cette facture est d’un mois remis à la fiduciaire : elle ne se modifie plus.');
        $this->deleteJson("/api/v1/facturation/factures/{$facture->id}/lignes/{$ligne->id}", [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** E6 et E7. L'acompte en ligne passe par le compte de transfert ; un remboursement Stripe repart en sens inverse. */
    public function test_e6_e7_acompte_en_ligne_et_remboursement_stripe(): void
    {
        $rdv = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rdv->id, 'acompte_centimes' => 3000, 'acompte_intention_id' => 'pi_acompte', 'acompte_paye_le' => CarbonImmutable::parse('2026-03-02 10:00', 'UTC')]);
        $facture = $this->facture('2026-03-05 09:00', [[120, 'acte']], ['rendez_vous_id' => $rdv->id, 'acompte' => 30]);
        $paiement = Paiement::query()->create(['facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => 'tpe', 'montant' => 90, 'stripe_payment_intent_id' => 'pi_carte', 'statut' => 'reussi']);
        app(Settlements::class)->record($facture, SettlementMethod::Tpe, 90, CarbonImmutable::parse('2026-03-05'), null, null, $paiement);
        app(Settlements::class)->syncStripeRefund($paiement, 2000, 'Remboursé chez Stripe', CarbonImmutable::parse('2026-03-20 10:00', 'UTC'));

        $lignes = array_map(fn (array $l): array => [$l['date'], $l['debit'], $l['credit'], $l['amount']], $this->lignes('2026-03'));
        $this->assertContains(['2026-03-02', '1090', '1100', 30.0], $lignes);
        $this->assertContains(['2026-03-05', '1090', '1100', 90.0], $lignes);
        $this->assertContains(['2026-03-20', '1100', '1090', 20.0], $lignes);
    }

    /** Vérification : remis à la fiduciaire, le mois garde ses écritures même si le plan change ; rouvert, la trace reste. */
    public function test_un_mois_remis_garde_ses_ecritures_et_sa_trace(): void
    {
        $this->facture('2025-12-10 09:00', [[100, 'acte']]);
        $this->postJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk();
        $this->clinique->societe->update(['plan_comptable' => ['comptes' => ['clients' => '1105']]]);

        $this->assertSame('1100', $this->lignes('2025-12')[0]['debit']);

        $this->deleteJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk();
        $verrou = AccountingLock::query()->where('month', '2025-12')->sole();
        $this->assertNotNull($verrou->reopened_at);
        $this->assertSame($this->admin->id, $verrou->reopened_by_id);
        // Rouvert : les écritures du jour, avec le plan d'aujourd'hui ; plus de verrou
        $this->assertSame('1105', $this->lignes('2025-12')[0]['debit']);
        $this->assertFalse(AccountingLock::isLocked($this->clinique->id, '2025-12-15'));
        // Remis de nouveau : un verrou neuf
        $this->postJson('/api/v1/facturation/ecritures/2025-12/verrou', [], $this->enTetes($this->jeton))->assertOk();
        $this->assertNull($verrou->refresh()->reopened_at);
        $this->assertTrue(AccountingLock::isLocked($this->clinique->id, '2025-12-15'));
    }

    /** Vérification : un acte offert (0 franc) ne donne pas de ligne à 0.00. */
    public function test_pas_de_ligne_a_zero_franc(): void
    {
        $this->facture('2026-03-10 09:00', [[100, 'acte', null, 8.1], [0, 'med', null, 2.6]]);

        $this->assertSame([[100.0, 'V81']], array_map(fn (array $l): array => [$l['amount'], $l['vat_code']], $this->lignes('2026-03')));
    }
}
