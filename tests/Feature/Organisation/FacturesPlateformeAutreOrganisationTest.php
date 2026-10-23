<?php

namespace Tests\Feature\Organisation;

use App\Models\Clinique;
use App\Models\OrganisationFacture;
use App\Paiements\PasserelleAbonnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Factures de la plateforme à l'organisation : celles d'une autre
 * organisation restent introuvables (lecture, règlement, confirmation),
 * celles de la nôtre se lisent depuis chaque clinique, et l'état de la
 * facture décide de ce qui se règle ou se confirme.
 */
class FacturesPlateformeAutreOrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $this->stripe);

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    /** Facture de la plateforme en attente de paiement, prélèvement parti */
    private function factureAPayer(int $organisationId): OrganisationFacture
    {
        return OrganisationFacture::factory()->create([
            'organisation_id' => $organisationId,
            'stripe_facture_id' => null,
            'numero' => 'MS-2026-'.fake()->unique()->numerify('#####'),
            'libelle' => 'Abonnement Pro Cabinet, du 20.10.2026 au 19.11.2026',
            'lignes' => [['libelle' => 'Abonnement Pro Cabinet, du 20.10.2026 au 19.11.2026', 'montant' => 249]],
            'montant' => 249,
            'montant_ht' => 249,
            'tva' => null,
            'statut' => 'a_payer',
            'stripe_paiement_id' => 'pi_autre',
            'metadonnees' => ['type' => 'pack'],
        ]);
    }

    public function test_la_facture_d_une_autre_organisation_ne_se_lit_ni_ne_se_regle_ni_ne_se_confirme(): void
    {
        $etrangere = $this->factureAPayer(Clinique::factory()->create()->societe->organisation_id);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/organisation/factures/{$etrangere->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/organisation/factures/{$etrangere->id}/regler", [], $entetes)->assertNotFound();
        $this->postJson("/api/v1/organisation/factures/{$etrangere->id}/confirmer", [], $entetes)->assertNotFound();

        $this->assertSame([], $this->stripe->prelevements);
        $this->assertSame('a_payer', $etrangere->refresh()->statut);
    }

    public function test_la_facture_de_l_organisation_se_lit_depuis_une_clinique_soeur(): void
    {
        $facture = $this->factureAPayer($this->clinique->societe->organisation_id);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->administrateurAvecJeton($soeur);

        $this->getJson("/api/v1/organisation/factures/{$facture->id}", $this->enTetes($jetonSoeur))
            ->assertOk()
            ->assertJsonPath('data.id', $facture->id)
            ->assertJsonPath('data.statut', 'a_payer')
            ->assertJsonPath('data.montant', 249)
            ->assertJsonPath('data.client.nom', $this->clinique->societe->nom);
    }

    public function test_un_veterinaire_ne_lit_ni_ne_regle_les_factures_de_l_organisation(): void
    {
        $facture = $this->factureAPayer($this->clinique->societe->organisation_id);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $entetes = $this->enTetes($jeton);

        $this->getJson("/api/v1/organisation/factures/{$facture->id}", $entetes)->assertForbidden();
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $entetes)->assertForbidden();
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/confirmer", [], $entetes)->assertForbidden();
        $this->assertSame([], $this->stripe->prelevements);
    }

    public function test_une_facture_payee_ne_se_regle_plus_et_sans_prelevement_rien_ne_se_confirme(): void
    {
        $organisationId = $this->clinique->societe->organisation_id;
        $payee = OrganisationFacture::factory()->create(['organisation_id' => $organisationId, 'statut' => 'payee']);
        $sansPrelevement = $this->factureAPayer($organisationId);
        $sansPrelevement->forceFill(['stripe_paiement_id' => null])->save();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/organisation/factures/{$payee->id}/regler", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('facture');
        $this->postJson("/api/v1/organisation/factures/{$sansPrelevement->id}/confirmer", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('facture');
        // Une facture déjà payée se confirme sans rien prélever
        $this->postJson("/api/v1/organisation/factures/{$payee->id}/confirmer", [], $entetes)
            ->assertOk()->assertJsonPath('data.statut', 'payee')->assertJsonPath('paiement', null);

        $this->assertSame([], $this->stripe->prelevements);
    }
}
