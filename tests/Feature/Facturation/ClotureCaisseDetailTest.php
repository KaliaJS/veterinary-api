<?php

namespace Tests\Feature\Facturation;

use App\Auth\RolesParDefaut;
use App\Enums\MoyenPaiement;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ClotureCaisse;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Détail d'une clôture de caisse (Z) : ses totaux et les factures qu'elle
 * couvre, pour la clinique du jeton seulement. Le tiroir d'une clinique
 * sœur reste introuvable, comme celui d'une autre organisation, et la
 * lecture exige le droit de facturation.
 */
class ClotureCaisseDetailTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Employe $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton, 'employe' => $this->auteur] = $this->administrateurAvecJeton($this->clinique);
    }

    private function facturePayee(MoyenPaiement $moyen, float $montant): Facture
    {
        $facture = Facture::factory()->payee($moyen)->create(['clinique_id' => $this->clinique->id, 'payee_le' => now()]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant]);

        return $facture;
    }

    public function test_une_cloture_se_relit_avec_ses_totaux_et_ses_factures(): void
    {
        $especes = $this->facturePayee(MoyenPaiement::Especes, 45);
        $autre = $this->facturePayee(MoyenPaiement::Especes, 30);

        $id = $this->postJson('/api/v1/facturation/clotures-caisse', [
            'fond' => 200,
            'especes_comptees' => 270,
            'nouveau_fond' => 150,
            'note' => 'Cinq francs manquent, rendu de monnaie.',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/facturation/clotures-caisse/{$id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.clinique_id', $this->clinique->id)
            ->assertJsonPath('data.total_especes', 75)
            ->assertJsonPath('data.total_encaisse', 75)
            ->assertJsonPath('data.especes_theoriques', 275)
            ->assertJsonPath('data.especes_comptees', 270)
            ->assertJsonPath('data.ecart', -5)
            ->assertJsonPath('data.depot', 120)
            ->assertJsonPath('data.nouveau_fond', 150)
            ->assertJsonPath('data.note', 'Cinq francs manquent, rendu de monnaie.')
            ->assertJsonPath('data.nombre_factures', 2)
            ->assertJsonPath('data.facture_ids', fn (array $ids) => collect($ids)->sort()->values()->all() === collect([$especes->id, $autre->id])->sort()->values()->all());
    }

    public function test_la_cloture_d_une_clinique_soeur_ou_d_une_autre_organisation_est_introuvable(): void
    {
        $soeur = ClotureCaisse::factory()->create(['clinique_id' => Clinique::factory()->create(['societe_id' => $this->clinique->societe_id])->id]);
        $etrangere = ClotureCaisse::factory()->create(['clinique_id' => Clinique::factory()->create()->id]);

        $this->getJson("/api/v1/facturation/clotures-caisse/{$soeur->id}", $this->enTetes($this->jeton))->assertNotFound();
        $this->getJson("/api/v1/facturation/clotures-caisse/{$etrangere->id}", $this->enTetes($this->jeton))->assertNotFound();
    }

    public function test_un_role_sans_la_caisse_ne_lit_pas_une_cloture(): void
    {
        $cloture = ClotureCaisse::factory()->create(['clinique_id' => $this->clinique->id]);
        ['employe' => $assistant, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $assistant->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ASSISTANT)
            ->value('id')]);

        $this->getJson("/api/v1/facturation/clotures-caisse/{$cloture->id}", $this->enTetes($jeton))->assertForbidden();
    }

    /** Auteur d'une clôture Z : son nom (fiche d'équipe), pas seulement son numéro de compte (28.10.2026). */
    public function test_une_cloture_dit_le_nom_de_son_auteur(): void
    {
        $this->facturePayee(MoyenPaiement::Especes, 45);
        $id = $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 100, 'especes_comptees' => 145, 'nouveau_fond' => 100], $this->enTetes($this->jeton))
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/facturation/clotures-caisse/{$id}", $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.auteur', $this->auteur->nom);
        $this->getJson('/api/v1/facturation/clotures-caisse', $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.0.auteur', $this->auteur->nom);
    }
}
