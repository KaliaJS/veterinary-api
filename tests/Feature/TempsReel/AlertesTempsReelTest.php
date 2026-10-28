<?php

namespace Tests\Feature\TempsReel;

use App\Enums\StatutPaiement;
use App\Events\AlertesModifiees;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\Lot;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Stock;
use App\Models\SuggestionDiagnostique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Alertes en temps réel (règle de Jim, 28.10.2026) : quand ce qui les fait
 * naître change (stock, lots, seuil, paiement, consommation), la clinique
 * est prévenue et relit ses alertes ; plus de relecture toutes les cinq
 * minutes.
 */
class AlertesTempsReelTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
    }

    /** @return list<int> */
    private function cliniquesPrevenues(): array
    {
        return Event::dispatched(AlertesModifiees::class)->map(fn (array $e) => $e[0]->cliniqueId)->unique()->sort()->values()->all();
    }

    public function test_un_stock_qui_change_previent_sa_clinique(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'seuil_alerte' => 5]);
        Event::fake([AlertesModifiees::class]);

        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 2]);

        $this->assertSame([$this->clinique->id], $this->cliniquesPrevenues());
        Event::assertDispatched(AlertesModifiees::class, fn (AlertesModifiees $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id && $e->broadcastAs() === 'alertes.modifiees' && $e->broadcastWith() === []);
    }

    public function test_un_lot_qui_change_previent_sa_clinique(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        Event::fake([AlertesModifiees::class]);

        Lot::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->soeur->id, 'peremption_le' => now()->addMonth()]);

        $this->assertSame([$this->soeur->id], $this->cliniquesPrevenues());
    }

    public function test_un_seuil_change_previent_toutes_les_cliniques_de_l_organisation(): void
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'seuil_alerte' => 5]);
        Event::fake([AlertesModifiees::class]);

        $produit->update(['seuil_alerte' => 50]);

        $this->assertEqualsCanonicalizing([$this->clinique->id, $this->soeur->id], $this->cliniquesPrevenues());
    }

    public function test_la_consommation_du_diagnostic_previent_l_organisation(): void
    {
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        Event::fake([AlertesModifiees::class]);

        SuggestionDiagnostique::query()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $rendezVous->id, 'statut' => 'prete', 'montant_centimes' => 30]);

        $this->assertEqualsCanonicalizing([$this->clinique->id, $this->soeur->id], $this->cliniquesPrevenues());
    }

    public function test_le_point_du_matin_previent_chaque_clinique(): void
    {
        Event::fake([AlertesModifiees::class]);

        $this->artisan('alertes:stock')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$this->clinique->id, $this->soeur->id], $this->cliniquesPrevenues());
    }

    /* ---------- Troisième passe (28.10.2026) : sources d'alertes qui restaient muettes ---------- */

    public function test_un_acompte_conteste_ou_rendu_previent_la_clinique(): void
    {
        $demande = DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id]);
        Event::fake([AlertesModifiees::class]);

        DemandeRendezVous::withoutGlobalScopes()->findOrFail($demande->id)->forceFill(['acompte_litige' => 'ouvert'])->save();
        $this->assertSame([$this->clinique->id], $this->cliniquesPrevenues());

        Event::fake([AlertesModifiees::class]);
        DemandeRendezVous::withoutGlobalScopes()->findOrFail($demande->id)->forceFill(['acompte_rembourse_le' => now(), 'acompte_rembourse_centimes' => 5000])->save();
        $this->assertSame([$this->clinique->id], $this->cliniquesPrevenues());
    }

    public function test_un_remboursement_partiel_previent_la_clinique(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $paiement = Paiement::query()->create(['facture_id' => $facture->id, 'clinique_id' => $this->clinique->id, 'moyen' => 'tpe', 'montant' => 60, 'stripe_payment_intent_id' => 'pi_essai', 'statut' => StatutPaiement::Reussi, 'reussi_le' => now()]);
        Event::fake([AlertesModifiees::class]);

        Paiement::withoutGlobalScopes()->findOrFail($paiement->id)->forceFill(['erreur' => 'Remboursé en partie : CHF 20.00.'])->save();

        $this->assertSame([$this->clinique->id], $this->cliniquesPrevenues());
    }

    public function test_un_plafond_change_previent_toutes_les_cliniques_de_l_organisation(): void
    {
        $organisation = $this->clinique->societe->organisation;
        Event::fake([AlertesModifiees::class]);

        $organisation->forceFill(['plafond_transcription' => 1000])->save();

        $this->assertSame(collect([$this->clinique->id, $this->soeur->id])->sort()->values()->all(), $this->cliniquesPrevenues());
    }
}
