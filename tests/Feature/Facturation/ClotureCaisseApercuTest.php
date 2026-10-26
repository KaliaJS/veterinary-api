<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Aperçu de la clôture de caisse (26.10.2026) : l'écran n'additionne plus
 * rien. Les totaux de la journée, le fond, les espèces attendues, le
 * comptage des billets, l'écart et le dépôt viennent du serveur, du même
 * calcul que l'enregistrement du Z.
 */
class ClotureCaisseApercuTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function facturePayee(MoyenPaiement $moyen, float $montant, float $acompte = 0, bool $aLaMain = false, ?Clinique $clinique = null): Facture
    {
        $clinique ??= $this->clinique;
        $facture = Facture::factory()->payee($moyen)->create(['clinique_id' => $clinique->id, 'payee_le' => now(), 'acompte' => $acompte]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant]);
        if (! $aLaMain && in_array($moyen, [MoyenPaiement::Tpe, MoyenPaiement::Twint], true)) {
            Paiement::query()->create([
                'facture_id' => $facture->id, 'clinique_id' => $clinique->id, 'moyen' => $moyen, 'montant' => $montant - $acompte,
                'stripe_payment_intent_id' => 'pi_'.$facture->id, 'statut' => StatutPaiement::Reussi, 'reussi_le' => now(),
            ]);
        }

        return $facture;
    }

    /** @param  array<string, mixed>  $parametres */
    private function apercu(array $parametres = []): TestResponse
    {
        return $this->getJson('/api/v1/facturation/clotures-caisse/apercu?'.http_build_query($parametres), $this->enTetes($this->jeton));
    }

    /** La journée de l'exemple : fond de 200, Rex 300 dont 100 d'acompte en ligne, Mimi 80, Félix 120, en espèces ; 600 attendus et comptés, aucun écart. */
    public function test_l_apercu_compte_ce_qui_est_passe_a_la_caisse_sans_l_acompte_en_ligne(): void
    {
        ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 200, 'arretee_le' => now()->subDay()]);
        $this->facturePayee(MoyenPaiement::Especes, 300, acompte: 100);
        $this->facturePayee(MoyenPaiement::Especes, 80);
        $this->facturePayee(MoyenPaiement::Especes, 120);
        $this->facturePayee(MoyenPaiement::Tpe, 50);

        $this->apercu(['comptage' => [10000 => 5, 5000 => 2], 'nouveau_fond' => 200])
            ->assertOk()
            ->assertJsonPath('data.encaissements', 4)
            ->assertJsonPath('data.moyens.cash.montant', 400)
            ->assertJsonPath('data.moyens.cash.nombre', 3)
            ->assertJsonPath('data.moyens.tpe.montant', 50)
            ->assertJsonPath('data.moyens.twint.montant', 0)
            ->assertJsonPath('data.total', 450)
            ->assertJsonPath('data.fond', 200)
            ->assertJsonPath('data.fond_impose', true)
            ->assertJsonPath('data.especes_theoriques', 600)
            ->assertJsonPath('data.especes_comptees', 600)
            ->assertJsonPath('data.ecart', 0)
            ->assertJsonPath('data.justification_requise', false)
            ->assertJsonPath('data.depot_suggere', 400)
            ->assertJsonPath('data.coupures.1', ['centimes' => 10000, 'nombre' => 5, 'montant' => 500]);
    }

    /** Plus de 100 encaissements depuis la dernière clôture : tous comptés. */
    public function test_l_apercu_compte_tous_les_encaissements_au_dela_de_cent(): void
    {
        Facture::factory()->payee(MoyenPaiement::Especes)->count(101)->create(['clinique_id' => $this->clinique->id, 'payee_le' => now()])
            ->each(fn (Facture $facture) => FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 1]));

        $this->apercu()->assertOk()->assertJsonPath('data.encaissements', 101)->assertJsonPath('data.moyens.cash.montant', 101);
    }

    /** Le premier jour, le fond est celui saisi ; ensuite, celui que la clôture précédente a laissé, quoi qu'on envoie. */
    public function test_le_fond_est_celui_saisi_le_premier_jour_puis_celui_de_la_veille(): void
    {
        $this->facturePayee(MoyenPaiement::Especes, 50);
        $this->apercu(['fond' => 150])->assertJsonPath('data.fond', 150)->assertJsonPath('data.fond_impose', false);

        ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 300, 'arretee_le' => now()->subDay()]);
        $this->apercu(['fond' => 150])->assertJsonPath('data.fond', 300)->assertJsonPath('data.fond_impose', true);
    }

    /** Il manque 100 : l'écart le dit, la justification est demandée. */
    public function test_un_ecart_demande_une_justification(): void
    {
        ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 200, 'arretee_le' => now()->subDay()]);
        $this->facturePayee(MoyenPaiement::Especes, 400);

        $this->apercu(['comptage' => [10000 => 5]])
            ->assertJsonPath('data.especes_comptees', 500)
            ->assertJsonPath('data.ecart', -100)
            ->assertJsonPath('data.justification_requise', true);
    }

    /** Une carte saisie sans terminal : signalée avec son auteur, à justifier. */
    public function test_une_carte_saisie_sans_terminal_est_signalee(): void
    {
        $facture = $this->facturePayee(MoyenPaiement::Tpe, 85, aLaMain: true);
        $auteur = User::factory()->create(['name' => 'Léa Fontaine']);
        $facture->update(['encaissee_par_id' => $auteur->id]);

        $this->apercu()
            ->assertJsonPath('data.moyens.tpe.sans_terminal', 1)
            ->assertJsonPath('data.moyens.tpe.saisies_par', ['Léa Fontaine'])
            ->assertJsonPath('data.mode_integre', true)
            ->assertJsonPath('data.justification_requise', true);
    }

    /** Un billet inconnu, ou un nombre négatif, est refusé. */
    public function test_un_comptage_inconnu_est_refuse(): void
    {
        $this->apercu(['comptage' => [30000 => 1]])->assertUnprocessable()->assertJsonValidationErrors('comptage');
        $this->apercu(['comptage' => [10000 => -1]])->assertUnprocessable()->assertJsonValidationErrors('comptage.10000');
    }

    /** Rien à clôturer : l'aperçu le dit, sans erreur. */
    public function test_sans_encaissement_l_apercu_le_dit(): void
    {
        $this->apercu()->assertOk()->assertJsonPath('data.encaissements', 0);
    }

    /** Les encaissements d'une autre clinique, même sœur (même société), ne comptent jamais : chaque clinique a sa caisse. */
    public function test_une_autre_clinique_ne_compte_pas(): void
    {
        $this->facturePayee(MoyenPaiement::Especes, 50);
        $this->facturePayee(MoyenPaiement::Especes, 70, clinique: Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]));
        $this->facturePayee(MoyenPaiement::Especes, 90, clinique: Clinique::factory()->create());

        $this->apercu()->assertJsonPath('data.encaissements', 1)->assertJsonPath('data.moyens.cash.montant', 50);
    }

    /** Le Z enregistré dit exactement ce que l'aperçu montrait, billets compris. */
    public function test_la_cloture_enregistre_exactement_l_apercu(): void
    {
        ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 200, 'arretee_le' => now()->subDay()]);
        $this->facturePayee(MoyenPaiement::Especes, 300, acompte: 100);
        $this->facturePayee(MoyenPaiement::Especes, 80);
        // Attendus : 200 de fond, 200 de Rex et 80 de Mimi, soit 480 ; comptés : 470
        $comptage = [10000 => 4, 5000 => 1, 2000 => 1];
        $apercu = $this->apercu(['comptage' => $comptage, 'nouveau_fond' => 200])->json('data');

        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 0, 'comptage' => $comptage, 'nouveau_fond' => 200, 'note' => 'Écart compté deux fois.'], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.especes_comptees', $apercu['especes_comptees'])
            ->assertJsonPath('data.especes_theoriques', $apercu['especes_theoriques'])
            ->assertJsonPath('data.ecart', $apercu['ecart'])
            ->assertJsonPath('data.depot', $apercu['depot_suggere'])
            ->assertJsonPath('data.total_especes', $apercu['moyens']['cash']['montant']);
        $this->assertSame(-10.0, (float) $apercu['ecart']);
    }

    /** L'aperçu se demande avec le droit de clôturer la caisse. */
    public function test_l_apercu_demande_le_droit_de_cloturer(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($jeton))->assertForbidden();
    }
}
