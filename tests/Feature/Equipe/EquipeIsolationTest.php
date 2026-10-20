<?php

namespace Tests\Feature\Equipe;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tranches de scope du domaine : la LISTE d'équipe est celle de la
 * clinique du jeton (écran Équipe) ; les GESTES RH sont bornés à
 * l'ORGANISATION (l'écran Organisation pilote les cliniques sœurs) —
 * jamais au-delà.
 */
class EquipeIsolationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $cliniqueSoeur;

    private Clinique $cliniqueEtrangere;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->cliniqueSoeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->cliniqueEtrangere = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_liste_d_equipe_est_celle_de_la_clinique_du_jeton(): void
    {
        Employe::factory()->dans($this->cliniqueSoeur)->create();

        $this->getJson('/api/v1/equipe/employes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cliniques', [$this->clinique->id]);
    }

    public function test_les_gestes_rh_atteignent_une_clinique_soeur_mais_jamais_une_autre_organisation(): void
    {
        $soeur = Employe::factory()->dans($this->cliniqueSoeur)->create();
        $etranger = Employe::factory()->dans($this->cliniqueEtrangere)->create();
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/equipe/employes/{$soeur->id}", $entetes)->assertOk();
        $this->postJson("/api/v1/equipe/employes/{$soeur->id}/suspendre", [], $entetes)->assertOk();

        $this->getJson("/api/v1/equipe/employes/{$etranger->id}", $entetes)->assertNotFound();
        $this->postJson("/api/v1/equipe/employes/{$etranger->id}/suspendre", [], $entetes)->assertNotFound();
        $this->postJson("/api/v1/equipe/employes/{$etranger->id}/licencier", [
            'fin_contrat_le' => today()->addMonth()->toDateString(),
        ], $entetes)->assertNotFound();
    }

    public function test_l_embauche_ne_cible_jamais_une_clinique_d_une_autre_organisation(): void
    {
        $this->postJson('/api/v1/equipe/employes', [
            'code' => User::factory()->create()->codeEmploye(),
            'poste' => 'ASV',
            'contrat_statut' => 'signe',
            'cliniques' => [$this->clinique->id, $this->cliniqueEtrangere->id],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques.1');
    }

    public function test_un_employe_ne_s_affecte_jamais_a_une_clinique_d_une_autre_organisation(): void
    {
        $soeur = Employe::factory()->dans($this->cliniqueSoeur)->create();

        $this->putJson("/api/v1/equipe/employes/{$soeur->id}/cliniques", [
            'cliniques' => [$this->cliniqueEtrangere->id],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques.0');

        $this->assertSame([$this->cliniqueSoeur->id], $soeur->cliniques()->pluck('cliniques.id')->all());
    }

    public function test_une_garde_ne_se_reattribue_pas_a_un_employe_d_une_autre_clinique(): void
    {
        $soeur = Employe::factory()->dans($this->cliniqueSoeur)->create();

        $this->putJson('/api/v1/equipe/gardes', [
            'samedi_le' => today()->next('saturday')->toDateString(),
            'employe_id' => $soeur->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employe_id');
    }

    public function test_la_rotation_des_gardes_ignore_les_veterinaires_des_autres_cliniques(): void
    {
        Employe::factory()->dans($this->cliniqueSoeur)->create();

        $reponse = $this->getJson('/api/v1/equipe/gardes', $this->enTetes($this->jeton));

        $veterinaireLocal = Employe::withoutGlobalScopes()
            ->whereRelation('cliniques', 'cliniques.id', $this->clinique->id)
            ->sole();

        foreach ($reponse->json('data') as $garde) {
            $this->assertSame($veterinaireLocal->id, $garde['employe_id']);
        }
    }
}
