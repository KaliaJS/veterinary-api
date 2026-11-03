<?php

namespace Tests\Feature\Facturation;

use App\Enums\StatutFacture;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La dette d'un animal et le solde dû d'un propriétaire viennent des
 * factures dues, tenus à jour à chaque facture, paiement ou annulation
 * (case de notes/todo.md du web, 03.11.2026 : Mme Barbier affichait
 * CHF 100.00 à régler pour une facture due de CHF 740.00).
 */
class DetteDesAnimauxTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        $this->client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function animal(): Patient
    {
        return Patient::factory()->for($this->client)->create(['organisation_id' => $this->client->organisation_id, 'dette' => 0]);
    }

    /** @param  list<array{0: int, 1: float}>  $lignes  quantité, prix */
    private function facture(?Patient $patient, array $lignes, array $attributs = []): Facture
    {
        $facture = Facture::factory()->create([
            'clinique_id' => $this->clinique->id,
            'client_id' => $this->client->id,
            'patient_id' => $patient?->id,
            ...$attributs,
        ]);
        foreach ($lignes as [$quantite, $prix]) {
            FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => $quantite, 'prix_chf' => $prix]);
        }

        return $facture;
    }

    private function dette(Patient $patient): float
    {
        return (float) Patient::withoutGlobalScopes()->whereKey($patient->id)->value('dette');
    }

    public function test_la_dette_de_l_animal_est_le_reste_a_payer_de_ses_factures_dues(): void
    {
        $cannelle = $this->animal();
        $this->facture($cannelle, [[1, 600], [2, 70]]);
        $this->facture($cannelle, [[1, 100]], ['acompte' => 30]);

        $this->assertSame(810.0, $this->dette($cannelle));
    }

    public function test_un_paiement_ou_une_annulation_la_remet_a_jour(): void
    {
        $cannelle = $this->animal();
        $premiere = $this->facture($cannelle, [[1, 740]]);
        $seconde = $this->facture($cannelle, [[1, 100]]);

        $premiere->update(['statut' => StatutFacture::Payee, 'payee_le' => now()]);
        $this->assertSame(100.0, $this->dette($cannelle));

        $seconde->update(['statut' => StatutFacture::Annulee]);
        $this->assertSame(0.0, $this->dette($cannelle));
    }

    public function test_une_ligne_ajoutee_modifiee_ou_retiree_la_remet_a_jour(): void
    {
        $cannelle = $this->animal();
        $facture = $this->facture($cannelle, [[1, 100]]);
        $ligne = FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 50]);
        $this->assertSame(150.0, $this->dette($cannelle));

        $ligne->update(['quantite' => 3]);
        $this->assertSame(250.0, $this->dette($cannelle));

        $ligne->delete();
        $this->assertSame(100.0, $this->dette($cannelle));
    }

    public function test_une_facture_passee_a_un_autre_animal_remet_les_deux_a_jour(): void
    {
        $cannelle = $this->animal();
        $ramen = $this->animal();
        $facture = $this->facture($cannelle, [[1, 200]]);

        $facture->update(['patient_id' => $ramen->id]);

        $this->assertSame(0.0, $this->dette($cannelle));
        $this->assertSame(200.0, $this->dette($ramen));
    }

    public function test_le_solde_du_proprietaire_compte_toutes_ses_factures_dues_meme_sans_animal(): void
    {
        $cannelle = $this->animal();
        $ramen = $this->animal();
        $this->facture($cannelle, [[1, 740]]);
        $this->facture($ramen, [[1, 60]], ['acompte' => 10]);
        // Une vente du comptoir, sans animal
        $this->facture(null, [[2, 15]]);
        // Payée : ne compte pas
        $this->facture($cannelle, [[1, 999]], ['statut' => StatutFacture::Payee]);

        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.solde_du', 820);
        $this->getJson('/api/v1/clients', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.solde_du', 820);
    }

    public function test_la_commande_recalcule_les_dettes_existantes(): void
    {
        $cannelle = $this->animal();
        $this->facture($cannelle, [[1, 740]]);
        // Une valeur ancienne, sans rapport avec les factures
        Patient::withoutGlobalScopes()->whereKey($cannelle->id)->update(['dette' => 100]);
        $sansFacture = $this->animal();
        Patient::withoutGlobalScopes()->whereKey($sansFacture->id)->update(['dette' => 90]);

        $this->artisan('invoices:recompute-debts')->assertSuccessful();

        $this->assertSame(740.0, $this->dette($cannelle));
        $this->assertSame(0.0, $this->dette($sansFacture));
    }

    /** Un nouveau propriétaire ne porte pas les dettes de l'ancien ; l'ancien les garde à son solde */
    public function test_un_changement_de_proprietaire_ne_passe_pas_la_dette_au_nouveau(): void
    {
        $cannelle = $this->animal();
        $this->facture($cannelle, [[1, 500]]);
        $nouveau = Client::factory()->create(['organisation_id' => $this->client->organisation_id]);

        $cannelle->update(['client_id' => $nouveau->id]);

        $this->assertSame(0.0, $this->dette($cannelle));
        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.solde_du', 500);
        $this->getJson("/api/v1/clients/{$nouveau->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.solde_du', 0);
    }

    /** Remises en négatif, acompte plus grand que le total : jamais de reste négatif */
    public function test_le_reste_n_est_jamais_negatif(): void
    {
        $cannelle = $this->animal();
        $this->facture($cannelle, [[1, 100], [1, -150]]);
        $this->facture($cannelle, [[1, 50]]);
        $this->facture($cannelle, [[1, 20]], ['acompte' => 80]);

        $this->assertSame(50.0, $this->dette($cannelle));
        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.solde_du', 50);
    }

    public function test_un_proprietaire_sans_facture_doit_zero(): void
    {
        $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.solde_du', 0);
    }

    /** La réponse du changement de propriétaire sert la dette recalculée (le web l'affiche aussitôt) */
    public function test_la_reponse_du_changement_de_proprietaire_sert_la_nouvelle_dette(): void
    {
        $cannelle = $this->animal();
        $this->facture($cannelle, [[1, 300]]);
        $nouveau = Client::factory()->create(['organisation_id' => $this->client->organisation_id]);

        $this->patchJson("/api/v1/patients/{$cannelle->id}", ['client_id' => $nouveau->id], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.dette', 0);
        $this->patchJson("/api/v1/patients/{$cannelle->id}", ['client_id' => $this->client->id], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.dette', 300);
    }
}
