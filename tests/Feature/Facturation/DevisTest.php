<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cycle de vie d'un devis (docs/data-model.md §7) : brouillon (seul
 * modifiable et supprimable) → envoi (canal selon le compte espace
 * client) → acceptation signée / refus ; l'expiration est dérivée de la
 * validité ; l'acompte puis la facture finale déduisent remise et
 * acompte en lignes négatives.
 */
class DevisTest extends TestCase
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

    private function devis(array $attributs = []): Devis
    {
        return Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'patient_id' => $this->patient->id,
            ...$attributs,
        ]);
    }

    public function test_creer_un_devis_numerote_en_brouillon_et_derive_les_totaux(): void
    {
        $annee = now()->year;

        $this->postJson('/api/v1/facturation/devis', [
            'patient_id' => $this->patient->id,
            'motif' => 'Ostéosynthèse fémur droit',
            'lignes' => [
                ['libelle' => 'Chirurgie : forfait bloc', 'quantite' => 1, 'prix_chf' => 420, 'type' => 'acte'],
                ['libelle' => 'Radiographie : 2 clichés', 'quantite' => 2, 'prix_chf' => 120, 'type' => 'acte'],
            ],
            'remise_pct' => 10,
            'acompte_pct' => 30,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.numero', "DV-{$annee}-001")
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.statut_effectif', 'brouillon')
            ->assertJsonPath('data.sous_total', 660)
            ->assertJsonPath('data.total_ttc', 594)
            // TVA 8.1 % extraite du TTC remisé, comme sur la facture.
            ->assertJsonPath('data.montant_tva', 44.51);
    }

    public function test_l_envoi_pose_la_validite_et_choisit_le_portail_quand_le_proprietaire_a_un_compte(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis(['validite_jours' => 30]);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('data.canal', 'portail')
            ->assertJsonPath('data.valide_jusquau', today()->addDays(30)->toDateString());
    }

    public function test_l_envoi_passe_au_papier_sans_compte_espace_client(): void
    {
        $devis = $this->devis();

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.canal', 'papier');
    }

    public function test_seul_un_brouillon_se_modifie(): void
    {
        $envoye = $this->devis()->fresh();
        $this->postJson("/api/v1/facturation/devis/{$envoye->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson("/api/v1/facturation/devis/{$envoye->id}", ['motif' => 'Modifié'], $this->enTetes($this->jeton))
            ->assertUnprocessable();

        $brouillon = $this->devis();
        DevisLigne::factory()->create(['devis_id' => $brouillon->id]);

        $this->patchJson("/api/v1/facturation/devis/{$brouillon->id}", [
            'motif' => 'Bilan complet',
            'lignes' => [['libelle' => 'Consultation standard', 'prix_chf' => 85, 'type' => 'acte']],
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.motif', 'Bilan complet')
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonPath('data.lignes.0.libelle', 'Consultation standard');
    }

    public function test_l_acceptation_signe_selon_le_canal(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $entetes)->assertOk();

        $reponse = $this->postJson("/api/v1/facturation/devis/{$devis->id}/accepter", [], $entetes);

        $reponse->assertOk()->assertJsonPath('data.statut', 'accepte');
        // L'équipe enregistre l'accord : jamais présenté comme une signature électronique du client
        $this->assertStringStartsWith('Accord du propriétaire enregistré par ', $reponse->json('data.signe_par'));
        $this->assertStringNotContainsString('Signature électronique', $reponse->json('data.signe_par'));
        $this->assertNotNull($reponse->json('data.signe_le'));
    }

    public function test_un_devis_expire_est_derive_et_ne_s_accepte_plus(): void
    {
        $expire = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => today()->subDays(5)]);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/facturation/devis/{$expire->id}", $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('data.statut_effectif', 'expire');

        $this->postJson("/api/v1/facturation/devis/{$expire->id}/accepter", [], $entetes)
            ->assertUnprocessable();

        // Le filtre `expire` du registre le retrouve.
        $this->getJson('/api/v1/facturation/devis?statut=expire', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/facturation/devis?statut=envoye', $entetes)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_duplication_repart_en_brouillon_signature_remise_a_zero(): void
    {
        $accepte = $this->devis(['statut' => 'accepte', 'canal' => 'portail', 'signe_le' => now(), 'signe_par' => 'Signature électronique']);
        DevisLigne::factory()->create(['devis_id' => $accepte->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 420]);

        $reponse = $this->postJson("/api/v1/facturation/devis/{$accepte->id}/dupliquer", [], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.canal', null)
            ->assertJsonPath('data.signe_le', null)
            ->assertJsonPath('data.emis_le', today()->toDateString())
            ->assertJsonPath('data.lignes.0.libelle', 'Chirurgie : forfait bloc');

        $this->assertNotSame($accepte->numero, $reponse->json('data.numero'));
    }

    public function test_seul_un_brouillon_se_supprime(): void
    {
        $brouillon = $this->devis();
        $envoye = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => today()->addDays(30)]);
        $entetes = $this->enTetes($this->jeton);

        $this->deleteJson("/api/v1/facturation/devis/{$brouillon->id}", [], $entetes)->assertNoContent();
        $this->deleteJson("/api/v1/facturation/devis/{$envoye->id}", [], $entetes)->assertUnprocessable();
    }

    public function test_l_acompte_puis_la_finale_deduisent_remise_et_acompte(): void
    {
        $devis = $this->devis(['statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now(), 'remise_pct' => 10, 'acompte_pct' => 30]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 400]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Analyses laboratoire — profil standard', 'prix_chf' => 100]);
        $entetes = $this->enTetes($this->jeton);

        // Sous-total 500, remise 10 % → TTC 450, acompte 30 % → 135.
        $acompte = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes);

        $acompte->assertCreated()
            ->assertJsonPath('data.total_ttc', 135)
            ->assertJsonPath('data.lignes.0.libelle', "Acompte 30 % : devis {$devis->numero}");

        $finale = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes);

        $finale->assertCreated()
            ->assertJsonCount(4, 'data.lignes')
            ->assertJsonPath('data.lignes.2.prix_chf', -50)
            ->assertJsonPath('data.lignes.3.prix_chf', -135)
            ->assertJsonPath('data.total_ttc', 315);

        $devis->refresh();
        $this->assertSame($acompte->json('data.id'), $devis->acompte_facture_id);
        $this->assertSame($finale->json('data.id'), $devis->facture_finale_id);

        // Chacune ne se facture qu'une fois.
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes)->assertUnprocessable();
    }

    public function test_seul_un_devis_accepte_se_facture(): void
    {
        $envoye = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => today()->addDays(30), 'acompte_pct' => 30]);

        $this->postJson("/api/v1/facturation/devis/{$envoye->id}/facturer-acompte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
    }
}
