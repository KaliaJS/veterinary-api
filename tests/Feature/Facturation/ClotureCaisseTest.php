<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Paiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Clôture de caisse (Z — docs/data-model.md §6) : l'arrêté couvre les
 * factures payées de la clinique qu'aucune clôture ne couvre, totalise
 * par moyen côté serveur et exige la justification de tout écart
 * d'espèces (pas de rapprochement TPE/TWINT manuel). Les statistiques de caisse
 * sont dérivées, rien n'est stocké.
 */
class ClotureCaisseTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        // Arrêter la caisse : droit `cloturer_caisse` (l'administrateur par défaut)
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    /** Carte et TWINT : payés au terminal (un paiement Stripe réussi), sauf `$aLaMain`. */
    private function facturePayee(MoyenPaiement $moyen, float $montant, bool $aLaMain = false): Facture
    {
        $facture = Facture::factory()->payee($moyen)->create([
            'clinique_id' => $this->clinique->id,
            'payee_le' => now(),
        ]);

        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant]);

        if (! $aLaMain && in_array($moyen, [MoyenPaiement::Tpe, MoyenPaiement::Twint], true)) {
            Paiement::query()->create([
                'facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => $moyen, 'montant' => $montant,
                'stripe_payment_intent_id' => 'pi_'.$facture->id, 'statut' => StatutPaiement::Reussi, 'reussi_le' => now(),
            ]);
        }

        return $facture;
    }

    public function test_la_cloture_totalise_et_couvre_les_encaissements_non_couverts(): void
    {
        $tpe = $this->facturePayee(MoyenPaiement::Tpe, 85);
        $especes = $this->facturePayee(MoyenPaiement::Especes, 45);
        $due = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $dejaCouverte = $this->facturePayee(MoyenPaiement::Tpe, 60);
        $dejaCouverte->update(['cloture_caisse_id' => ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 200])->id]);

        $reponse = $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 245,
            'nouveau_fond' => 200,
        ], $this->enTetes($this->jeton));

        $annee = now()->year;

        $reponse->assertCreated()
            ->assertJsonPath('data.numero', "Z-{$annee}-001")
            ->assertJsonPath('data.total_tpe', 85)
            ->assertJsonPath('data.total_twint', 0)
            ->assertJsonPath('data.total_especes', 45)
            ->assertJsonPath('data.ecart', 0)
            ->assertJsonPath('data.depot', 45)
            ->assertJsonPath('data.nombre_factures', 2);

        $clotureId = $reponse->json('data.id');

        $this->assertSame($clotureId, $tpe->fresh()->cloture_caisse_id);
        $this->assertSame($clotureId, $especes->fresh()->cloture_caisse_id);
        $this->assertNull($due->fresh()->cloture_caisse_id);
        $this->assertNotSame($clotureId, $dejaCouverte->fresh()->cloture_caisse_id);

        // Plus rien à clôturer : le second arrêté est refusé.
        $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 200,
            'nouveau_fond' => 200,
        ], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_aucun_rapprochement_manuel_n_est_demande_pour_le_tpe_et_twint(): void
    {
        $this->facturePayee(MoyenPaiement::Tpe, 85);
        $this->facturePayee(MoyenPaiement::Twint, 30);

        $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 200,
            'nouveau_fond' => 200,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.total_tpe', 85)
            ->assertJsonPath('data.total_twint', 30);
    }

    public function test_une_carte_saisie_sans_terminal_se_justifie(): void
    {
        $this->facturePayee(MoyenPaiement::Tpe, 85, aLaMain: true);

        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 200, 'nouveau_fond' => 200], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 200, 'nouveau_fond' => 200, 'note' => 'Terminal en panne, carte passée sur l’ancien lecteur'], $this->enTetes($this->jeton))
            ->assertCreated();
    }

    public function test_le_fond_suit_la_cloture_precedente_et_le_depot_ne_depasse_pas_les_especes(): void
    {
        ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id, 'nouveau_fond' => 300, 'arretee_le' => now()->subDay()]);
        $this->facturePayee(MoyenPaiement::Especes, 50);

        // Un fond déclaré à 250 ne change rien : il vient de la veille (300)
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 250, 'especes_comptees' => 350, 'nouveau_fond' => 300, 'depot' => 400], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('depot');
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 250, 'especes_comptees' => 350, 'nouveau_fond' => 300], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.fond', 300)
            ->assertJsonPath('data.ecart', 0);
    }

    public function test_un_ecart_de_caisse_exige_une_justification(): void
    {
        $this->facturePayee(MoyenPaiement::Especes, 45);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 240,
            'nouveau_fond' => 200,
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 240,
            'nouveau_fond' => 200,
            'note' => 'Écart de -5.00 CHF non résolu — signalé à la comptabilité.',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.ecart', -5);
    }

    public function test_les_statistiques_de_caisse_sont_derivees(): void
    {
        $this->facturePayee(MoyenPaiement::Tpe, 85);
        $this->facturePayee(MoyenPaiement::Especes, 45);
        FactureLigne::factory()->create([
            'facture_id' => Facture::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'prix_chf' => 55,
        ]);

        $this->getJson('/api/v1/facturation/statistiques', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.du_jour.nombre', 2)
            ->assertJsonPath('data.du_jour.tpe', 85)
            ->assertJsonPath('data.du_jour.especes', 45)
            ->assertJsonPath('data.du_jour.total', 130)
            ->assertJsonPath('data.en_attente.nombre', 1)
            ->assertJsonPath('data.en_attente.total', 55)
            ->assertJsonPath('data.mois.ca_par_clinique.0.clinique_id', $this->clinique->id)
            ->assertJsonPath('data.mois.ca_par_clinique.0.total', 130);
    }
}
