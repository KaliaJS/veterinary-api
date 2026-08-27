<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
use App\Models\Clinique;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Models\FactureLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Clôture de caisse (Z — docs/data-model.md §6) : l'arrêté couvre les
 * factures payées de la clinique qu'aucune clôture ne couvre, totalise
 * par moyen côté serveur, exige le rapprochement TPE/TWINT et la
 * justification de tout écart d'espèces. Les statistiques de caisse
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

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function facturePayee(MoyenPaiement $moyen, float $montant): Facture
    {
        $facture = Facture::factory()->payee($moyen)->create([
            'clinique_id' => $this->clinique->id,
            'payee_le' => now(),
        ]);

        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant]);

        return $facture;
    }

    public function test_la_cloture_totalise_et_couvre_les_encaissements_non_couverts(): void
    {
        $tpe = $this->facturePayee(MoyenPaiement::Tpe, 85);
        $especes = $this->facturePayee(MoyenPaiement::Especes, 45);
        $due = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $dejaCouverte = $this->facturePayee(MoyenPaiement::Tpe, 60);
        $dejaCouverte->update(['cloture_caisse_id' => ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id])->id]);

        $reponse = $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 245,
            'tpe_rapproche' => true,
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

    public function test_le_rapprochement_tpe_est_obligatoire_quand_le_tpe_a_encaisse(): void
    {
        $this->facturePayee(MoyenPaiement::Tpe, 85);

        $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 200,
            'nouveau_fond' => 200,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tpe_rapproche');
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
