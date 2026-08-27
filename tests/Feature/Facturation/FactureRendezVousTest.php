<?php

namespace Tests\Feature\Facturation;

use App\Auth\RolesParDefaut;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Branchement planning → caisse (chemin 1 de docs/data-model.md §3) :
 * le passage d'un RDV en « fini » facture l'acte dérivé de son type —
 * une seule facture par RDV, jamais pour un tampon, débrayable
 * (« Terminer sans générer »). La facture est un effet système : elle
 * naît même sans le domaine facturation, mais la réponse la cache alors.
 */
class FactureRendezVousTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function rendezVous(array $attributs = []): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Cours,
            ...$attributs,
        ]);
    }

    public function test_le_passage_a_fini_facture_l_acte_derive_du_type(): void
    {
        $rendezVous = $this->rendezVous(['type' => TypeRendezVous::Vaccin]);

        $reponse = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", [
            'status' => 'fini',
        ], $this->enTetes($this->jeton));

        $reponse->assertOk()->assertJsonPath('data.status', 'fini');

        $facture = Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->sole();

        $this->assertSame('due', $facture->statut->value);
        $this->assertSame($this->clinique->id, $facture->clinique_id);
        $this->assertSame('Vaccination — acte', $facture->lignes()->sole()->libelle);
        $this->assertSame(48.0, (float) $facture->lignes()->sole()->prix_chf);

        // Le créateur a le domaine facturation : la facture est exposée.
        $this->assertSame($facture->numero, $reponse->json('facture.numero'));
        $this->assertSame(48, $reponse->json('facture.total_ttc'));
    }

    public function test_terminer_a_nouveau_retrouve_la_facture_sans_doublon(): void
    {
        $rendezVous = $this->rendezVous();
        $entetes = $this->enTetes($this->jeton);

        $premier = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->json('facture.id');

        // Rouvrir puis re-terminer (bouton « Rouvrir » du front).
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'cours'], $entetes)->assertOk();
        $second = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->json('facture.id');

        $this->assertSame($premier, $second);
        $this->assertSame(1, Facture::withoutGlobalScopes()->count());
    }

    public function test_un_tampon_termine_n_est_jamais_facture(): void
    {
        $tampon = $this->rendezVous(['type' => TypeRendezVous::Tampon, 'patient_name' => null]);

        $this->postJson("/api/v1/rendez-vous/{$tampon->id}/statut", ['status' => 'fini'], $this->enTetes($this->jeton))
            ->assertOk();

        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
    }

    public function test_terminer_sans_generer_ne_facture_pas(): void
    {
        $rendezVous = $this->rendezVous();

        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", [
            'status' => 'fini',
            'sans_facture' => true,
        ], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(0, Facture::withoutGlobalScopes()->count());
    }

    public function test_une_asv_termine_le_rdv_mais_la_reponse_cache_la_facture(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ASV)
                ->value('id'),
        ]);

        $rendezVous = $this->rendezVous();

        $reponse = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jeton));

        $reponse->assertOk()->assertJsonMissingPath('facture');

        // L'effet système a bien eu lieu — la facture attend en caisse.
        $this->assertSame(1, Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->count());
    }

    public function test_l_annulation_d_une_creation_supprime_la_facture_dans_la_fenetre(): void
    {
        $rendezVous = $this->rendezVous();
        $entetes = $this->enTetes($this->jeton);

        $factureId = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->json('facture.id');

        $this->deleteJson("/api/v1/facturation/factures/{$factureId}", [], $entetes)->assertNoContent();

        $this->assertDatabaseMissing('factures', ['id' => $factureId]);
    }

    public function test_l_annulation_hors_fenetre_est_refusee(): void
    {
        $rendezVous = $this->rendezVous();
        $entetes = $this->enTetes($this->jeton);

        $factureId = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->json('facture.id');

        $this->travel(21)->seconds();

        $this->deleteJson("/api/v1/facturation/factures/{$factureId}", [], $entetes)->assertUnprocessable();

        $this->assertDatabaseHas('factures', ['id' => $factureId]);
    }

    public function test_l_annulation_par_un_autre_auteur_est_refusee(): void
    {
        $rendezVous = $this->rendezVous();

        $factureId = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($this->jeton))
            ->json('facture.id');

        ['jeton' => $autreJeton] = $this->employeAvecJeton($this->clinique);

        $this->deleteJson("/api/v1/facturation/factures/{$factureId}", [], $this->enTetes($autreJeton))
            ->assertUnprocessable();
    }

    public function test_une_facture_encaissee_ne_se_supprime_plus_meme_dans_la_fenetre(): void
    {
        $rendezVous = $this->rendezVous();
        $entetes = $this->enTetes($this->jeton);

        $factureId = $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $entetes)->json('facture.id');

        $this->postJson("/api/v1/facturation/factures/{$factureId}/encaisser", ['moyen_paiement' => 'tpe'], $entetes)->assertOk();

        $this->deleteJson("/api/v1/facturation/factures/{$factureId}", [], $entetes)->assertUnprocessable();
    }
}
