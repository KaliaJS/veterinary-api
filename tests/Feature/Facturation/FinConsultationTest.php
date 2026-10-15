<?php

namespace Tests\Feature\Facturation;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fin de consultation (chemin 2 de docs/data-model.md §3) — le geste
 * composé : actes → facture, vaccin → carnet + stock, médicaments →
 * ordonnance au registre + stock + prescription jointe, RDV en fini.
 * L'undo repasse par les fenêtres d'annulation de chaque pièce.
 */
class FinConsultationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function rendezVousEnCours(array $attributs = []): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Cours,
            'patient_id' => $this->patient->id,
            'client_id' => $this->patient->client_id,
            ...$attributs,
        ]);
    }

    private function medicamentEnStock(int $quantite): Produit
    {
        $produit = Produit::factory()->medicament()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        Stock::factory()->create([
            'produit_id' => $produit->id,
            'clinique_id' => $this->clinique->id,
            'quantite' => $quantite,
        ]);

        return $produit;
    }

    private function prestation(string $code): Prestation
    {
        return Prestation::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('code', $code)
            ->sole();
    }

    private function stockDe(Produit $produit): int
    {
        return Stock::withoutGlobalScopes()
            ->where('produit_id', $produit->id)
            ->where('clinique_id', $this->clinique->id)
            ->sole()
            ->quantite;
    }

    public function test_le_geste_compose_genere_facture_ordonnance_carnet_et_stock(): void
    {
        $rendezVous = $this->rendezVousEnCours(['type' => TypeRendezVous::Vaccin]);
        $vaccin = $this->medicamentEnStock(10);
        $medicament = $this->medicamentEnStock(5);

        $reponse = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('vaccin')->id, $this->prestation('inj_sc')->id],
            'vaccination' => [
                'nom' => 'CHPPiL',
                'marque' => 'Nobivac',
                'produit_id' => $vaccin->id,
                'echeance_le' => today()->addYear()->toDateString(),
            ],
            'medicaments' => [
                ['produit_id' => $medicament->id, 'posologie' => '0,1 mg/kg — 1× par jour', 'duree' => '7 jours'],
            ],
        ], $this->enTetes($this->jeton));

        $reponse->assertOk()->assertJsonPath('data.status', 'fini');

        // Facture : 2 actes + vaccin + médicament, stock déjà sorti.
        $facture = Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->sole();
        $this->assertSame(4, $facture->lignes()->count());
        $this->assertTrue($facture->stock_sorti);
        $this->assertSame($this->patient->id, $facture->patient_id);

        // Ordonnance au registre, référencée par la facture.
        $ordonnance = Ordonnance::withoutGlobalScopes()->sole();
        $this->assertSame($ordonnance->id, $facture->ordonnance_id);
        $this->assertSame('0,1 mg/kg — 1× par jour', $ordonnance->lignes()->sole()->posologie);

        // Prescription jointe « délivré par la clinique ».
        $this->assertSame('stock', $facture->prescriptions()->sole()->mode->value);

        // Stock : vaccin via la facture, médicament via l'ordonnance.
        $this->assertSame(9, $this->stockDe($vaccin));
        $this->assertSame(4, $this->stockDe($medicament));

        // Carnet : l'injection du jour est inscrite, signée.
        $vaccination = $this->patient->vaccinations()->sole();
        $this->assertSame('CHPPiL', $vaccination->nom);
        $this->assertSame(today()->toDateString(), $vaccination->injectee_le->toDateString());

        // Réponse complète pour un vétérinaire (facturation + patients).
        $this->assertSame($facture->numero, $reponse->json('facture.numero'));
        $this->assertSame($ordonnance->numero, $reponse->json('ordonnance.numero'));
    }

    public function test_une_injection_du_jour_s_ajoute_a_l_historique_du_carnet(): void
    {
        $this->patient->vaccinations()->create([
            'nom' => 'CHPPiL',
            'injectee_le' => today()->subYear(),
            'echeance_le' => today()->addDays(10),
        ]);

        $rendezVous = $this->rendezVousEnCours(['type' => TypeRendezVous::Vaccin]);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('vaccin')->id],
            'vaccination' => ['nom' => 'CHPPiL', 'echeance_le' => today()->addYear()->toDateString()],
        ], $this->enTetes($this->jeton))->assertOk();

        // Historique complet (16.10.2026) : deux lignes, la dernière du jour
        $vaccinations = $this->patient->vaccinations()->orderByDesc('injectee_le')->get();
        $this->assertCount(2, $vaccinations);
        $this->assertSame(today()->toDateString(), $vaccinations->first()->injectee_le->toDateString());
        $this->assertSame(today()->addYear()->toDateString(), $vaccinations->first()->echeance_le->toDateString());
    }

    public function test_sans_fiche_patient_vaccin_et_medicaments_sont_refuses(): void
    {
        $rendezVous = $this->rendezVousEnCours(['patient_id' => null, 'client_id' => null]);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
            'medicaments' => [['produit_id' => $this->medicamentEnStock(5)->id]],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous');
    }

    public function test_sans_fiche_patient_les_actes_seuls_se_facturent(): void
    {
        $rendezVous = $this->rendezVousEnCours(['patient_id' => null, 'client_id' => null, 'patient_name' => 'Rex']);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
        ], $this->enTetes($this->jeton))->assertOk();

        $facture = Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->sole();
        $this->assertSame('Rex', $facture->patient_name);
        $this->assertNull($facture->patient_id);
    }

    public function test_un_rdv_deja_facture_ne_se_cloture_pas_deux_fois(): void
    {
        $rendezVous = $this->rendezVousEnCours();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->assertOk();
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'cours'], $entetes)->assertOk();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendez_vous');
    }

    public function test_un_tampon_ne_se_cloture_jamais(): void
    {
        $tampon = $this->rendezVousEnCours(['type' => TypeRendezVous::Tampon, 'patient_id' => null, 'client_id' => null]);

        $this->postJson("/api/v1/rendez-vous/{$tampon->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
        ], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_l_undo_repasse_par_les_fenetres_d_annulation_et_restaure_le_stock(): void
    {
        $rendezVous = $this->rendezVousEnCours();
        $vaccin = $this->medicamentEnStock(10);
        $medicament = $this->medicamentEnStock(5);
        $entetes = $this->enTetes($this->jeton);

        $reponse = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/fin-de-consultation", [
            'prestations' => [$this->prestation('consult')->id],
            'vaccination' => ['nom' => 'CHPPiL', 'produit_id' => $vaccin->id, 'echeance_le' => today()->addYear()->toDateString()],
            'medicaments' => [['produit_id' => $medicament->id]],
        ], $entetes);

        // L'undo du front : statut restauré, facture et ordonnance
        // annulées dans leurs fenêtres — chaque stock revient.
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'cours', 'sans_facture' => true], $entetes)->assertOk();
        $this->deleteJson('/api/v1/facturation/factures/'.$reponse->json('facture.id'), [], $entetes)->assertNoContent();
        $this->deleteJson('/api/v1/ordonnances/'.$reponse->json('ordonnance.id'), [], $entetes)->assertNoContent();

        $this->assertSame(10, $this->stockDe($vaccin));
        $this->assertSame(5, $this->stockDe($medicament));
        $this->assertSame(StatutRendezVous::Cours, $rendezVous->fresh()->status);
    }
}
