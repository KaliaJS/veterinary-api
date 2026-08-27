<?php

namespace Tests\Feature\Equipe;

use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fiches RH : embauche (rôle dérivé du poste, quota d'employés actifs
 * de la formule, cible mono ou multi-cliniques), suspension d'accès à
 * effet immédiat, licenciement comme état (jamais de suppression),
 * solde de congés dérivé.
 */
class EmployeRhTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_l_embauche_cree_la_fiche_avec_le_role_derive_du_poste(): void
    {
        $reponse = $this->postJson('/api/v1/equipe/employes', [
            'nom' => 'Dr Nadia Rey',
            'poste' => 'Vétérinaire',
            'taux' => 80,
            'salaire_mensuel' => 8500,
            'contrat_statut' => 'attente',
            'email' => 'n.rey@veterinary.ch',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nom', 'Dr Nadia Rey')
            ->assertJsonPath('data.0.initiales', 'NR')
            ->assertJsonPath('data.0.contrat_statut', 'attente')
            ->assertJsonPath('data.0.clinique_id', $this->clinique->id);

        $fiche = Employe::withoutGlobalScopes()->find($reponse->json('data.0.id'));

        $this->assertSame(RolesParDefaut::VETERINAIRE, $fiche->role->nom);
        $this->assertSame('CDI', $fiche->contrat);
    }

    public function test_l_embauche_d_une_asv_prend_le_role_asv(): void
    {
        $reponse = $this->postJson('/api/v1/equipe/employes', [
            'nom' => 'Léa Perrin',
            'poste' => 'ASV · Accueil',
            'contrat_statut' => 'pdf',
        ], $this->enTetes($this->jeton))->assertCreated();

        $fiche = Employe::withoutGlobalScopes()->find($reponse->json('data.0.id'));

        $this->assertSame(RolesParDefaut::ASV, $fiche->role->nom);
    }

    public function test_l_embauche_respecte_la_limite_d_employes_actifs_de_la_formule(): void
    {
        // Formule Individuel : 2 employés actifs. Le porteur du jeton
        // en est un — la première embauche passe, la seconde non.
        $this->clinique->societe->organisation->update(['plan' => FormuleSaas::Individuel]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson('/api/v1/equipe/employes', [
            'nom' => 'Dr Paul Karlen', 'poste' => 'Vétérinaire', 'contrat_statut' => 'signe',
        ], $entetes)->assertCreated();

        $this->postJson('/api/v1/equipe/employes', [
            'nom' => 'Anna Duval', 'poste' => 'ASV', 'contrat_statut' => 'signe',
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employes');
    }

    public function test_l_embauche_pour_toutes_les_cliniques_cree_une_fiche_par_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $reponse = $this->postJson('/api/v1/equipe/employes', [
            'nom' => 'Dr Marc Weber',
            'poste' => 'Vétérinaire',
            'contrat_statut' => 'signe',
            'toutes_les_cliniques' => true,
        ], $this->enTetes($this->jeton))->assertCreated();

        $reponse->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$this->clinique->id, $soeur->id],
            Employe::withoutGlobalScopes()->where('nom', 'Dr Marc Weber')->pluck('clinique_id')->all(),
        );
    }

    public function test_la_suspension_coupe_l_acces_immediatement_et_le_retablissement_le_rouvre(): void
    {
        ['employe' => $collegue, 'jeton' => $jetonCollegue] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonCollegue))->assertOk();

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/suspendre", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.acces_suspendu', true);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonCollegue))->assertForbidden();

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/retablir", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.acces_suspendu', false);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonCollegue))->assertOk();
    }

    public function test_le_retablissement_reverifie_le_quota_de_la_formule(): void
    {
        ['employe' => $collegue] = $this->employeAvecJeton($this->clinique);
        $collegue->update(['acces_suspendu' => true]);

        // Individuel : 2 actifs max — déjà 2 en poste, le retour du
        // suspendu dépasserait.
        $this->employeAvecJeton($this->clinique);
        $this->clinique->societe->organisation->update(['plan' => FormuleSaas::Individuel]);

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/retablir", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acces');
    }

    public function test_le_licenciement_est_un_etat_notifie_revocable(): void
    {
        ['employe' => $collegue] = $this->employeAvecJeton($this->clinique);
        $fin = today()->addMonths(2)->endOfMonth()->toDateString();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/licencier", [
            'fin_contrat_le' => $fin,
            'motif' => 'Restructuration du service',
            'revoquer_acces' => true,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.licenciement.fin_contrat_le', $fin)
            ->assertJsonPath('data.licenciement.notifie_le', today()->toDateString())
            ->assertJsonPath('data.acces_suspendu', true);

        // Jamais deux notifications empilées.
        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/licencier", [
            'fin_contrat_le' => $fin,
        ], $entetes)->assertUnprocessable();

        // L'annulation efface l'état — l'accès, lui, reste un geste à part.
        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/annuler-licenciement", [], $entetes)
            ->assertOk()
            ->assertJsonMissingPath('data.licenciement');

        $this->assertTrue($collegue->fresh()->acces_suspendu);
    }

    public function test_le_solde_de_conges_est_derive_du_droit_du_pris_et_du_planifie(): void
    {
        $this->employe->update(['conges_droit' => 25, 'conges_pris' => 10]);

        // 3 jours validés à venir + un congé passé (ignoré) + une
        // demande en attente (ignorée).
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee,
            'debut_le' => today()->addDays(10),
            'fin_le' => today()->addDays(12),
        ]);
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee,
            'debut_le' => today()->subDays(20),
            'fin_le' => today()->subDays(18),
        ]);
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::EnAttente,
            'debut_le' => today()->addDays(20),
            'fin_le' => today()->addDays(24),
        ]);

        $this->getJson("/api/v1/equipe/employes/{$this->employe->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.conges.droit', 25)
            ->assertJsonPath('data.conges.pris', 10)
            ->assertJsonPath('data.conges.planifies', 3)
            ->assertJsonPath('data.conges.solde', 12);
    }

    public function test_la_modification_de_la_fiche(): void
    {
        $this->patchJson("/api/v1/equipe/employes/{$this->employe->id}", [
            'taux' => 60,
            'contrat' => 'CDD → 12.2026',
            'conges_droit' => 20,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.taux', 60)
            ->assertJsonPath('data.contrat', 'CDD → 12.2026');
    }
}
