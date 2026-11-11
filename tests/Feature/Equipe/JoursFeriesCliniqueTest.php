<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * É23 (Jim, 11.11.2026, « By canton, automatic ») : les jours fériés du
 * canton de la clinique (d'après son NPA) ne comptent pas comme jours de
 * congé ; la clinique choisit sa région et ajuste ses jours (Réglages,
 * Horaires) ; le Planning les montre.
 */
class JoursFeriesCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        // Lausanne : canton de Vaud
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'npa' => '1003']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function employe(): Employe
    {
        return Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'created_at' => '2026-01-05', 'conges_droit' => 25]);
    }

    public function test_un_jour_ferie_du_canton_ne_compte_pas_comme_jour_de_conge(): void
    {
        $employe = $this->employe();
        // L'Ascension tombe le jeudi 14 mai 2026 : 4 jours de congé, pas 5
        Absence::factory()->create(['employe_id' => $employe->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-05-11', 'fin_le' => '2026-05-15']);
        // Du lundi 21 au jeudi 24 décembre, puis Noël (vendredi) : 4 jours
        Absence::factory()->create(['employe_id' => $employe->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-12-21', 'fin_le' => '2026-12-25']);

        $this->getJson("/api/v1/equipe/employes/{$employe->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.conges.pris', 4)
            ->assertJsonPath('data.conges.planifies', 4)
            ->assertJsonPath('data.conges.solde', 17);
    }

    public function test_la_clinique_lit_et_ajuste_ses_jours_feries(): void
    {
        // Sans année : celle d'aujourd'hui
        $this->getJson('/api/v1/equipe/jours-feries', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.canton', 'VD');
        $reponse = $this->getJson('/api/v1/equipe/jours-feries?annee=2026', $this->enTetes($this->jeton))->assertOk();
        $reponse->assertJsonPath('data.canton', 'VD')->assertJsonPath('data.variante', 'canton');
        $this->assertContains(['cle' => 'lundi_jeune', 'nom' => 'Lundi du Jeûne fédéral', 'jour' => '2026-09-21', 'part' => 1, 'est_actif' => true, 'est_du_canton' => true], $reponse->json('data.jours'));

        // Le Lundi du Jeûne travaillé, le 1er mai chômé
        $this->putJson('/api/v1/equipe/jours-feries', ['retires' => ['lundi_jeune'], 'ajoutes' => ['fete_travail']], $this->enTetes($this->jeton))->assertOk();
        $etat = $this->getJson('/api/v1/equipe/jours-feries?annee=2026', $this->enTetes($this->jeton))->json('data');
        $this->assertSame(['lundi_jeune'], $etat['retires']);
        $this->assertSame(['fete_travail'], $etat['ajoutes']);
        $jours = collect($etat['jours'])->keyBy('cle');
        $this->assertFalse($jours['lundi_jeune']['est_actif']);
        $this->assertTrue($jours['fete_travail']['est_actif']);
        $this->assertFalse($jours['fete_travail']['est_du_canton']);

        $this->putJson('/api/v1/equipe/jours-feries', ['variante' => 'inconnue'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('variante');
        $this->putJson('/api/v1/equipe/jours-feries', ['ajoutes' => ['inconnu']], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('ajoutes.0');
    }

    public function test_une_region_se_choisit_dans_les_cantons_qui_en_ont(): void
    {
        $this->clinique->update(['npa' => '1700']);
        $this->getJson('/api/v1/equipe/jours-feries?annee=2026', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.canton', 'FR')
            ->assertJsonPath('data.variante', 'catholique')
            ->assertJsonCount(2, 'data.variantes');
        $this->putJson('/api/v1/equipe/jours-feries', ['variante' => 'reformee'], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.variante', 'reformee');
    }

    public function test_les_changer_demande_l_administration_ou_les_rh_le_planning_les_montre_a_chacun(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        foreach ([DomainePermission::Organisation, DomainePermission::Rh, DomainePermission::Equipe] as $domaine) {
            DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => $domaine, 'est_accordee' => false]);
        }

        $this->putJson('/api/v1/equipe/jours-feries', ['retires' => ['noel']], $this->enTetes($jeton))->assertForbidden();
        $feries = $this->getJson('/api/v1/equipe/planning?du=2026-12-21&au=2026-12-31', $this->enTetes($jeton))->assertOk()->json('data.feries');
        $this->assertSame([['jour' => '2026-12-25', 'nom' => 'Noël', 'part' => 1]], $feries);
    }
}
