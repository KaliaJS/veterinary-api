<?php

namespace Tests\Feature\Planning;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\LignePlanning;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Annuaire de la clinique pour la grille de planning : lecture sous le
 * domaine `planning` (une ASV sans `equipe` doit voir les zones
 * d'absence), bornée à la clinique du jeton, sans aucune donnée RH.
 */
class AnnuaireEmployeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
    }

    public function test_l_annuaire_expose_identite_ligne_et_poste_sans_donnees_rh(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJetonAsv();
        $veterinaire = Employe::factory()->create([
            'clinique_id' => $this->clinique->id,
            'nom' => 'Dr Anna Meyer',
            'initiales' => 'AM',
            'salaire_mensuel' => 9800,
        ]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $veterinaire->id]);

        $reponse = $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $employe->id)
            ->assertJsonPath('data.0.ligne_planning_id', null)
            ->assertJsonPath('data.1.id', $veterinaire->id)
            ->assertJsonPath('data.1.nom', 'Dr Anna Meyer')
            ->assertJsonPath('data.1.titre', 'Dr')
            ->assertJsonPath('data.1.initiales', 'AM')
            ->assertJsonPath('data.1.role_libelle', 'Vétérinaire')
            ->assertJsonPath('data.1.ligne_planning_id', $ligne->id);

        $this->assertEqualsCanonicalizing(
            ['id', 'clinique_id', 'nom', 'initiales', 'titre', 'role_libelle', 'ligne_planning_id', 'acces_suspendu'],
            array_keys($reponse->json('data.1')),
        );
        $this->assertStringNotContainsString('9800', $reponse->getContent());
    }

    public function test_une_asv_sans_le_domaine_equipe_lit_l_annuaire_et_les_absences_mais_pas_les_fiches_rh(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJetonAsv();
        $veterinaire = Employe::factory()->create(['clinique_id' => $this->clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $veterinaire->id]);
        $absence = Absence::factory()->create(['employe_id' => $veterinaire->id]);

        $this->getJson('/api/v1/equipe/employes', $this->enTetes($jeton))->assertForbidden();

        $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonFragment(['id' => $veterinaire->id]);

        $this->getJson('/api/v1/absences', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonFragment(['id' => $absence->id]);
    }

    public function test_l_annuaire_porte_l_etat_de_suspension_pour_retirer_la_colonne(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJetonAsv();
        $suspendu = Employe::factory()->create(['clinique_id' => $this->clinique->id, 'acces_suspendu' => true]);
        $actif = Employe::factory()->create(['clinique_id' => $this->clinique->id, 'acces_suspendu' => false]);

        $reponse = $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))->assertOk();

        $parId = collect($reponse->json('data'))->keyBy('id');

        $this->assertTrue($parId[$suspendu->id]['acces_suspendu']);
        $this->assertFalse($parId[$actif->id]['acces_suspendu']);
    }

    public function test_l_annuaire_est_celui_de_la_clinique_du_jeton(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        Employe::factory()->create(['clinique_id' => $this->cliniqueSoeur->id]);
        Employe::factory()->create(['clinique_id' => Clinique::factory()->create()->id]);

        $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employe->id)
            ->assertJsonPath('data.0.clinique_id', $this->clinique->id);
    }

    public function test_sans_le_domaine_planning_l_annuaire_est_refuse(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Planning,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/planning/employes', $this->enTetes($jeton))->assertForbidden();

        // Le domaine équipe seul ne suffit pas : l'annuaire est planning.
        $this->getJson('/api/v1/equipe/employes', $this->enTetes($jeton))->assertOk();
    }

    /** @return array{user: User, employe: Employe, jeton: string} */
    private function employeAvecJetonAsv(): array
    {
        $resultat = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV · Accueil']);

        $role = Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ASV)
            ->firstOrFail();

        // L'ASV par défaut porte `equipe` ; on le lui retire pour
        // reproduire le cas visé (profil planning seul).
        $resultat['employe']->update(['role_id' => $role->id]);
        DerogationPermission::create([
            'employe_id' => $resultat['employe']->id,
            'domaine' => DomainePermission::Equipe,
            'est_accordee' => false,
        ]);

        return $resultat;
    }
}
