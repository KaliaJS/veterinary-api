<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Permissions du domaine équipe, vérifiées en base à chaque requête :
 * sans le domaine, chaque endpoint RH — fiches, embauche, accès,
 * licenciement, gardes — refuse en 403.
 */
class EquipePermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $collegue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->collegue = Employe::factory()->dans($this->clinique)->create();
    }

    public function test_chaque_endpoint_du_domaine_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $appels = [
            ['getJson', '/api/v1/equipe/employes', []],
            ['postJson', '/api/v1/equipe/employes', ['nom' => 'Refusé', 'poste' => 'ASV', 'contrat_statut' => 'signe']],
            ['getJson', "/api/v1/equipe/employes/{$this->collegue->id}", []],
            ['patchJson', "/api/v1/equipe/employes/{$this->collegue->id}", []],
            ['postJson', "/api/v1/equipe/employes/{$this->collegue->id}/suspendre", []],
            ['postJson', "/api/v1/equipe/employes/{$this->collegue->id}/retablir", []],
            ['postJson', "/api/v1/equipe/employes/{$this->collegue->id}/licencier", ['fin_contrat_le' => aujourdhui()->addMonth()->toDateString()]],
            ['postJson', "/api/v1/equipe/employes/{$this->collegue->id}/annuler-licenciement", []],
            ['getJson', '/api/v1/equipe/gardes', []],
            ['putJson', '/api/v1/equipe/gardes', ['samedi_le' => aujourdhui()->toDateString(), 'employe_id' => $this->collegue->id]],
        ];

        foreach ($appels as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_equipe_ferme_le_domaine(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Equipe,
            'est_accordee' => false,
        ]);

        $this->getJson('/api/v1/equipe/employes', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/equipe/gardes', $this->enTetes($jeton))->assertForbidden();

        // Le domaine planning, lui, reste ouvert.
        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jeton))->assertOk();
    }

    public function test_un_role_avec_le_domaine_equipe_accede_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/equipe/employes', $this->enTetes($jeton))->assertOk();
        $this->getJson("/api/v1/equipe/employes/{$this->collegue->id}", $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/equipe/gardes', $this->enTetes($jeton))->assertOk();
    }
}
