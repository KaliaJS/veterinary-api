<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Role;
use App\Models\Societe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Structure et abonnement SaaS : sociétés, cliniques (création avec
 * horaires par défaut, quota de la formule, suppression tolérée d'une
 * clinique vide seulement — jamais la dernière), changement de formule
 * bloqué par les limites dépassées, packs de quota, moyen de paiement
 * masqué, factures « F- ».
 */
class OrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->organisationId())
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);

        $this->clinique->societe->organisation->update(['moyen_paiement' => 'Visa •••• 4242']);
    }

    private function organisationId(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    public function test_l_organisation_expose_formule_limites_usage_et_stats(): void
    {
        $this->getJson('/api/v1/organisation', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'cabinet')
            ->assertJsonPath('data.plan.limites.cliniques', 5)
            ->assertJsonPath('data.plan.limites.employes', 30)
            ->assertJsonPath('data.stats.cliniques', 1)
            ->assertJsonPath('data.stats.employes_actifs', 1);
    }

    public function test_une_societe_s_ajoute_avec_un_ide_genere_au_besoin(): void
    {
        $reponse = $this->postJson('/api/v1/organisation/societes', [
            'nom' => 'Vet Riviera Sàrl',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()->assertJsonPath('data.nom', 'Vet Riviera Sàrl');

        $this->assertMatchesRegularExpression('/^CHE-\d{3}\.\d{3}\.\d{3}$/', $reponse->json('data.ide'));
    }

    public function test_une_clinique_se_cree_avec_ses_horaires_par_defaut(): void
    {
        $reponse = $this->postJson('/api/v1/organisation/cliniques', [
            'nom' => 'Riviera',
            'raison_sociale' => 'Clinique Vétérinaire de la Riviera',
            'societe_id' => $this->clinique->societe_id,
            'rue' => 'Avenue de Chillon 12',
            'npa' => '1820',
            'ville' => 'Montreux',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()->assertJsonPath('data.nom', 'Riviera');

        $this->assertDatabaseHas('horaires_ouverture', [
            'clinique_id' => $reponse->json('data.id'),
            'ouverture' => '08:00',
            'fermeture' => '19:30',
        ]);
    }

    public function test_la_creation_de_clinique_respecte_la_limite_de_la_formule(): void
    {
        Clinique::withoutGlobalScopes()->findOrFail($this->clinique->id)
            ->societe->organisation->update(['plan' => FormuleSaas::Individuel]);

        $this->postJson('/api/v1/organisation/cliniques', [
            'nom' => 'Refusée',
            'raison_sociale' => 'Clinique de trop',
            'societe_id' => $this->clinique->societe_id,
            'rue' => 'Rue X 1',
            'npa' => '1000',
            'ville' => 'Lausanne',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');
    }

    public function test_une_clinique_se_modifie_et_se_rattache_a_une_autre_societe(): void
    {
        $autreSociete = Societe::create([
            'organisation_id' => $this->organisationId(),
            'nom' => 'Vet Lac Léman Sàrl',
            'ide' => 'CHE-302.118.447',
        ]);

        $this->patchJson("/api/v1/organisation/cliniques/{$this->clinique->id}", [
            'tel' => '+41 21 601 42 42',
            'societe_id' => $autreSociete->id,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tel', '+41 21 601 42 42')
            ->assertJsonPath('data.societe_id', $autreSociete->id);
    }

    public function test_la_derniere_clinique_ne_se_supprime_jamais(): void
    {
        $this->deleteJson("/api/v1/organisation/cliniques/{$this->clinique->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinique');
    }

    public function test_une_clinique_avec_historique_se_desactive_plutot_qu_elle_ne_se_supprime(): void
    {
        $avecEquipe = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        Employe::factory()->create(['clinique_id' => $avecEquipe->id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$avecEquipe->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();

        $vide = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$vide->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('cliniques', ['id' => $vide->id]);
    }

    public function test_le_changement_de_formule_est_bloque_par_les_limites_depassees(): void
    {
        // Deux cliniques : la formule Individuel (1 clinique) est hors
        // de portée — le message nomme le dépassement.
        Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->postJson('/api/v1/organisation/abonnement', [
            'plan' => 'individuel',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
    }

    public function test_le_changement_de_formule_facture_immediatement(): void
    {
        $annee = now()->year;

        $reponse = $this->postJson('/api/v1/organisation/abonnement', [
            'plan' => 'hopital',
            'cycle' => 'an',
        ], $this->enTetes($this->jeton));

        $reponse->assertOk()
            ->assertJsonPath('data.plan.code', 'hopital')
            ->assertJsonPath('data.cycle', 'an')
            ->assertJsonPath('data.resilie_le', null);

        // Dix mois pour un an, TVA 8.1 % incluse : 999 × 10 × 1.081.
        $this->assertDatabaseHas('organisation_factures', [
            'organisation_id' => $this->organisationId(),
            'numero' => "F-{$annee}-001",
            'montant' => 10799.19,
        ]);
    }

    public function test_le_changement_de_formule_exige_un_moyen_de_paiement(): void
    {
        Clinique::withoutGlobalScopes()->findOrFail($this->clinique->id)
            ->societe->organisation->update(['moyen_paiement' => null]);

        $this->postJson('/api/v1/organisation/abonnement', [
            'plan' => 'hopital',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('moyen_paiement');
    }

    public function test_la_resiliation_vaut_fin_de_mois_et_se_reactive(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', today()->endOfMonth()->toDateString());

        $this->postJson('/api/v1/organisation/abonnement/reactiver', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', null);
    }

    public function test_un_pack_de_quota_s_ajoute_au_palier_du_catalogue_et_se_facture(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->postJson('/api/v1/organisation/packs', [
            'type' => 'emails',
            'quantite' => 5000,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.quota_extra.emails', 5000);

        $this->assertDatabaseHas('organisation_factures', [
            'libelle' => 'Quota e-mails +5 000',
            'montant' => 15,
        ]);

        // Un palier hors catalogue est refusé — le prix est celui du
        // serveur, jamais celui du client.
        $this->postJson('/api/v1/organisation/packs', [
            'type' => 'emails',
            'quantite' => 4242,
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');
    }

    public function test_le_moyen_de_paiement_est_masque_et_supprimable(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $this->putJson('/api/v1/organisation/moyen-paiement', [
            'titulaire' => 'Deux Rives Vétérinaires SA',
            'numero' => '4242 4242 4242 4242',
            'expiration' => '08/28',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.moyen_paiement', 'Visa •••• 4242');

        $this->deleteJson('/api/v1/organisation/moyen-paiement', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.moyen_paiement', null);
    }
}
