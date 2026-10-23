<?php

namespace Tests\Feature\Facturation;

use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * TVA par société (23.10.2026) : une société non inscrite au registre de
 * la TVA n'en facture pas ; le taux se fige à l'émission de la facture,
 * changer le statut de la société ne touche pas les factures émises.
 */
class TvaSocieteTest extends TestCase
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

    private function facture(): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 108.10]);

        return $facture;
    }

    public function test_une_societe_assujettie_facture_la_tva_au_taux_normal(): void
    {
        $facture = $this->facture();

        $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.taux_tva', 8.1)
            ->assertJsonPath('data.montant_tva', 8.1)
            ->assertJsonPath('data.total_ht', 100)
            ->assertJsonPath('data.en_tete.tva', true);
    }

    public function test_une_societe_non_assujettie_ne_facture_pas_de_tva_et_le_taux_reste_fige(): void
    {
        $avant = $this->facture();

        $this->patchJson("/api/v1/organisation/societes/{$this->clinique->societe_id}", ['assujettie_tva' => false], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.assujettie_tva', false);

        $apres = $this->facture();

        $this->getJson("/api/v1/facturation/factures/{$apres->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.taux_tva', 0)
            ->assertJsonPath('data.montant_tva', 0)
            ->assertJsonPath('data.total_ht', 108.1)
            ->assertJsonPath('data.en_tete.tva', false);

        // La facture émise avant garde son taux
        $this->getJson("/api/v1/facturation/factures/{$avant->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.taux_tva', 8.1)
            ->assertJsonPath('data.montant_tva', 8.1);
    }

    public function test_une_societe_d_une_autre_organisation_ne_se_modifie_pas(): void
    {
        $ailleurs = Clinique::factory()->create();

        $this->patchJson("/api/v1/organisation/societes/{$ailleurs->societe_id}", ['assujettie_tva' => false], $this->enTetes($this->jeton))
            ->assertNotFound();
        $this->assertTrue($ailleurs->societe->fresh()->assujettie_tva);
    }
}
