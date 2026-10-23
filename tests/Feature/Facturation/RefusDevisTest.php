<?php

namespace Tests\Feature\Facturation;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Refus d'un devis : seul un devis envoyé se refuse ; refusé, il reste au
 * registre (filtre `refuse`), ne s'accepte, ne se modifie, ne se supprime
 * ni ne se facture plus, et le propriétaire ne peut plus le signer ; il se
 * duplique pour repartir d'un brouillon.
 */
class RefusDevisTest extends TestCase
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
        $devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'patient_id' => $this->patient->id,
            'acompte_pct' => 30,
            ...$attributs,
        ]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Détartrage sous anesthésie', 'quantite' => 1, 'prix_chf' => 320]);

        return $devis;
    }

    public function test_un_devis_envoye_se_refuse_et_se_retrouve_au_registre_des_refuses(): void
    {
        $devis = $this->devis();
        $this->devis(['motif' => 'Un autre, resté en brouillon']);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $entetes)->assertOk()->assertJsonPath('data.statut', 'envoye');

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/refuser", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.id', $devis->id)
            ->assertJsonPath('data.statut', 'refuse')
            ->assertJsonPath('data.statut_effectif', 'refuse')
            ->assertJsonPath('data.signe_le', null)
            ->assertJsonPath('data.lignes.0.libelle', 'Détartrage sous anesthésie');

        $this->getJson("/api/v1/facturation/devis/{$devis->id}", $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'refuse');

        $this->getJson('/api/v1/facturation/devis?statut=refuse', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $devis->id);
        $this->getJson('/api/v1/facturation/devis?statut=envoye', $entetes)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_seul_un_devis_envoye_se_refuse(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $brouillon = $this->devis();
        $accepte = $this->devis(['statut' => StatutDevis::Accepte, 'canal' => CanalDevis::Papier, 'signe_le' => now(), 'signe_par' => 'Signature papier']);
        $refuse = $this->devis(['statut' => StatutDevis::Refuse, 'canal' => CanalDevis::Papier]);

        foreach ([$brouillon, $accepte, $refuse] as $devis) {
            $this->postJson("/api/v1/facturation/devis/{$devis->id}/refuser", [], $entetes)
                ->assertUnprocessable()
                ->assertJsonPath('errors.statut.0', 'Seul un devis envoyé se refuse.');
        }

        $this->assertSame(StatutDevis::Brouillon, $brouillon->refresh()->statut);
        $this->assertSame(StatutDevis::Accepte, $accepte->refresh()->statut);
    }

    public function test_refuse_il_ne_s_accepte_ne_se_modifie_ne_se_supprime_ni_ne_se_facture_mais_se_duplique(): void
    {
        $devis = $this->devis(['statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier, 'valide_jusquau' => aujourdhui()->addDays(30)]);
        $entetes = $this->enTetes($this->jeton);
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/refuser", [], $entetes)->assertOk();

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/accepter", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->patchJson("/api/v1/facturation/devis/{$devis->id}", ['motif' => 'Modifié après refus'], $entetes)->assertUnprocessable();
        $this->deleteJson("/api/v1/facturation/devis/{$devis->id}", [], $entetes)->assertUnprocessable();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('statut');

        $devis->refresh();
        $this->assertSame(StatutDevis::Refuse, $devis->statut);
        $this->assertNotSame('Modifié après refus', $devis->motif);
        $this->assertNull($devis->acompte_facture_id);
        $this->assertNull($devis->facture_finale_id);

        $copie = $this->postJson("/api/v1/facturation/devis/{$devis->id}/dupliquer", [], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.lignes.0.libelle', 'Détartrage sous anesthésie')
            ->json('data.id');
        $this->assertNotSame($devis->id, $copie);
    }

    public function test_le_proprietaire_ne_signe_plus_un_devis_refuse_par_la_clinique(): void
    {
        ['user' => $proprietaire, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->patient->client->update(['user_id' => $proprietaire->id]);
        $devis = $this->devis();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.canal', 'portail');
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/refuser", [], $this->enTetes($this->jeton))->assertOk();

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", [], $this->enTetes($jetonProprietaire))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');

        $this->assertSame(StatutDevis::Refuse, $devis->refresh()->statut);
        $this->assertNull($devis->signe_le);
    }

    public function test_le_devis_d_une_autre_organisation_est_introuvable(): void
    {
        $etranger = Devis::factory()->envoye()->create();

        $this->postJson("/api/v1/facturation/devis/{$etranger->id}/refuser", [], $this->enTetes($this->jeton))
            ->assertNotFound();

        $this->assertSame(StatutDevis::Envoye, $etranger->refresh()->statut);
    }
}
