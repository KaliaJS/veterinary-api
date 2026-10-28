<?php

namespace Tests\Feature\Alertes;

use App\Auth\RolesParDefaut;
use App\Mail\AlertesStockClinique;
use App\Mail\PlafondDiagnosticOrganisation;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Lot;
use App\Models\Organisation;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\Stock;
use App\Models\SuggestionDiagnostique;
use App\Services\Diagnostic\ConsommationDiagnostic;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Alertes de la clinique : stock (seuils, péremptions) et consommation
 * (plafonds), selon les domaines de chacun ; vues puis nouvelles quand la
 * situation change ; e-mails coupables par famille ; point du matin.
 */
class AlertesCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Organisation $organisation;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill(['statut_abonnement' => 'actif', 'periode_debut' => '2026-10-20', 'periode_fin' => '2026-11-20'])->save();
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function produit(string $nom, int $stock, int $seuil): Produit
    {
        $produit = Produit::factory()->create(['organisation_id' => $this->organisation->id, 'nom' => $nom, 'seuil_alerte' => $seuil]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => $stock]);

        return $produit;
    }

    private function role(string $nom): void
    {
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->organisation->id)->where('nom', $nom)->value('id')]);
    }

    public function test_les_alertes_de_stock_se_voient_puis_reviennent_quand_la_situation_change(): void
    {
        $this->produit('Metacam 1,5 mg/ml', 2, 5);
        $this->produit('Seringues 2 ml', 40, 10);
        $amox = $this->produit('Amoxicilline 250 mg', 30, 0);
        Lot::factory()->create(['produit_id' => $amox->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => 'A1', 'peremption_le' => '2026-09-01', 'quantite' => 4]);
        Lot::factory()->create(['produit_id' => $amox->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => 'A2', 'peremption_le' => '2026-11-01', 'quantite' => 6]);
        Lot::factory()->create(['produit_id' => $amox->id, 'clinique_id' => $this->clinique->id, 'numero_lot' => 'A3', 'peremption_le' => '2027-06-01', 'quantite' => 20]);

        $reponse = $this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.non_lues', 3)
            ->assertJsonPath('data.alertes.0.titre', '1 produit sous le seuil')
            ->assertJsonPath('data.alertes.0.detail', 'Metacam 1,5 mg/ml (2)')
            ->assertJsonPath('data.alertes.1.titre', '1 lot périmé à retirer')
            ->assertJsonPath('data.alertes.2.titre', '1 lot périme d’ici fin novembre');

        $this->postJson('/api/v1/alertes/lues', ['cles' => array_column($reponse->json('data.alertes'), 'cle')], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.non_lues', 0);

        // Un autre produit passe sous le seuil : l'alerte redevient nouvelle
        $this->produit('Gants M', 0, 3);
        $this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))
            ->assertJsonPath('data.non_lues', 1)
            ->assertJsonPath('data.alertes.0.titre', '2 produits sous le seuil')
            ->assertJsonPath('data.alertes.0.niveau', 'urgent');
    }

    /** Stock bas par clinique (28.10.2026) : l'alerte compare le stock de la clinique à son seuil, comme « Commander le stock bas ». */
    public function test_l_alerte_de_stock_bas_compte_le_stock_de_la_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $rimadyl = $this->produit('Rimadyl 50 mg', 2, 10);
        Stock::factory()->create(['produit_id' => $rimadyl->id, 'clinique_id' => $soeur->id, 'quantite' => 40]);
        // Tenu seulement par la sœur, en rupture là-bas : pas une alerte d'ici
        $metacam = Produit::factory()->create(['organisation_id' => $this->organisation->id, 'nom' => 'Metacam', 'seuil_alerte' => 5]);
        Stock::factory()->create(['produit_id' => $metacam->id, 'clinique_id' => $soeur->id, 'quantite' => 0]);

        $this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.alertes.0.titre', '1 produit sous le seuil')
            ->assertJsonPath('data.alertes.0.detail', 'Rimadyl 50 mg (2)');
    }

    public function test_la_consommation_ne_se_voit_qu_avec_le_domaine_organisation(): void
    {
        $this->organisation->forceFill(['plafond_diagnostic' => 1000])->save();
        SuggestionDiagnostique::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id, 'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'statut' => 'prete', 'soap' => ['s' => 'x', 'o' => '', 'a' => '', 'p' => ''], 'montant_centimes' => 800, 'rendue_le' => now(),
        ]);

        $this->role(RolesParDefaut::VETERINAIRE);
        $this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->assertJsonPath('data.alertes', []);

        $this->role(RolesParDefaut::ADMINISTRATEUR);
        $this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))
            ->assertJsonPath('data.alertes.0.titre', 'Diagnostic IA : 80 % du plafond')
            ->assertJsonPath('data.alertes.0.cible', 'reglages:diagnostic');
    }

    public function test_les_e_mails_se_coupent_par_famille(): void
    {
        Mail::fake();
        $this->role(RolesParDefaut::ADMINISTRATEUR);

        $this->getJson('/api/v1/alertes/reglages', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.stock', true)
            ->assertJsonPath('data.consommation', true)
            ->assertJsonPath('data.peut_consommation', true);
        $this->putJson('/api/v1/alertes/reglages', ['consommation' => false], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.consommation', false)
            ->assertJsonPath('data.stock', true);

        // Plafond atteint : l'administratrice qui a coupé ces e-mails n'en reçoit pas
        $this->organisation->forceFill(['plafond_diagnostic' => 50])->save();
        SuggestionDiagnostique::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id, 'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $this->clinique->id])->id,
            'statut' => 'prete', 'soap' => ['s' => 'x', 'o' => '', 'a' => '', 'p' => ''], 'montant_centimes' => 50, 'rendue_le' => now(),
        ]);
        app(ConsommationDiagnostic::class)->surveillerOrganisation($this->organisation->refresh());
        Mail::assertNotSent(PlafondDiagnosticOrganisation::class);

        $this->putJson('/api/v1/alertes/reglages', ['consommation' => true], $this->enTetes($this->jeton))->assertJsonPath('data.consommation', true);
        Cache::flush();
        app(ConsommationDiagnostic::class)->surveillerOrganisation($this->organisation->refresh());
        Mail::assertSent(PlafondDiagnosticOrganisation::class, fn ($mail) => $mail->hasTo($this->employe->user->email));
    }

    public function test_le_point_du_matin_part_a_ceux_qui_gerent_le_stock_et_ne_l_ont_pas_coupe(): void
    {
        Mail::fake();
        ['employe' => $autre] = $this->employeAvecJeton($this->clinique);
        $autre->update(['alertes_email_coupees' => ['stock']]);

        $this->artisan('alertes:stock')->assertSuccessful();
        Mail::assertNothingSent();

        $this->produit('Metacam 1,5 mg/ml', 1, 5);
        $this->artisan('alertes:stock')->assertSuccessful();
        Mail::assertSent(AlertesStockClinique::class, 1);
        Mail::assertSent(AlertesStockClinique::class, fn ($mail) => $mail->hasTo($this->employe->user->email)
            && ! $mail->hasTo($autre->user->email)
            && str_contains($mail->render(), 'Metacam 1,5 mg/ml (1)'));
    }
}
