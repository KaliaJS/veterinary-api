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
 * Le planning de l'équipe, vu par chacun (Jim, 11.11.2026, É10 : « Team
 * planning, read-only ») : sans le droit Équipe (et, depuis l'audit C29,
 * sans « Gérer les absences »), les autres ne sont
 * qu'« absents », jamais la raison ni la note, et leurs demandes en
 * attente ne se montrent pas ; ses propres absences se lisent en entier.
 */
class PlanningEquipeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        $this->collegue = Employe::factory()->dans($this->clinique)->create(['nom' => 'Lena Weber']);
        Absence::factory()->create(['employe_id' => $this->collegue->id, 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-16', 'fin_le' => '2026-11-18', 'note' => 'Grippe']);
        Absence::factory()->create(['employe_id' => $this->collegue->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::EnAttente, 'debut_le' => '2026-11-23', 'fin_le' => '2026-11-27']);
    }

    private function planning(string $jeton): array
    {
        return $this->getJson('/api/v1/equipe/planning?du=2026-11-09&au=2026-12-06', $this->enTetes($jeton))->assertOk()->json();
    }

    public function test_sans_le_droit_equipe_les_autres_sont_seulement_absents(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);
        $mienne = Absence::factory()->create(['employe_id' => $moi->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::EnAttente, 'debut_le' => '2026-12-01', 'fin_le' => '2026-12-02', 'note' => 'Mariage']);

        $reponse = $this->planning($jeton);

        $this->assertEqualsCanonicalizing([$moi->id, $this->collegue->id], array_column($reponse['data']['employes'], 'id'));
        $absences = collect($reponse['data']['absences'])->keyBy('id');
        $this->assertCount(2, $absences);
        $autre = $absences->firstWhere('employe_id', $this->collegue->id);
        $this->assertSame(['id' => $autre['id'], 'employe_id' => $this->collegue->id, 'type' => null, 'debut_le' => '2026-11-16', 'fin_le' => '2026-11-18', 'statut' => 'ok', 'note' => null, 'valide_par_user_id' => null], $autre);
        $this->assertSame('conge', $absences[$mienne->id]['type']);
        $this->assertSame('Mariage', $absences[$mienne->id]['note']);
        $this->assertFalse($reponse['data']['peut_gerer']);
        $this->assertSame($moi->id, $reponse['data']['moi']['employe_id']);
        $this->assertFalse($reponse['data']['moi']['sans_validation']);
        $this->assertArrayHasKey('solde', $reponse['data']['moi']['conges']);
    }

    public function test_avec_le_droit_de_gerer_les_absences_tout_se_lit(): void
    {
        // « Voir l'équipe » ne suffit plus (audit C29, 14.11.2026)
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');

        $reponse = $this->planning($jeton);

        $this->assertTrue($reponse['data']['peut_gerer']);
        $types = collect($reponse['data']['absences'])->where('employe_id', $this->collegue->id)->pluck('type', 'statut')->all();
        $this->assertSame(['ok' => 'maladie', 'att' => 'conge'], $types);
        $this->assertSame('Grippe', collect($reponse['data']['absences'])->firstWhere('type', 'maladie')['note']);
    }

    public function test_sans_le_droit_equipe_la_liste_des_absences_du_planning_ne_dit_pas_la_raison(): void
    {
        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);
        $mienne = Absence::factory()->create(['employe_id' => $moi->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::EnAttente, 'debut_le' => '2026-12-01', 'fin_le' => '2026-12-02', 'note' => 'Mariage']);
        $garde = Absence::factory()->create(['employe_id' => $this->collegue->id, 'type' => TypeAbsence::Garde, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-12-05', 'fin_le' => '2026-12-06']);

        $absences = collect($this->getJson('/api/v1/absences', $this->enTetes($jeton))->assertOk()->json('data'))->keyBy('id');

        // La demande en attente d'un autre ne se montre pas ; sa maladie, sans raison ni note
        $this->assertEqualsCanonicalizing([$mienne->id, $garde->id, $this->maladie()->id], $absences->keys()->all());
        $this->assertNull($absences[$this->maladie()->id]['type']);
        $this->assertNull($absences[$this->maladie()->id]['note']);
        // Une garde n'est pas une raison : elle reste dite ; ses propres absences se lisent en entier
        $this->assertSame('garde', $absences[$garde->id]['type']);
        $this->assertSame('Mariage', $absences[$mienne->id]['note']);
        $this->getJson('/api/v1/absences/'.$this->maladie()->id, $this->enTetes($jeton))->assertOk()->assertJsonPath('data.type', null)->assertJsonPath('data.note', null);
        // La demande en attente d'un autre ne se lit pas non plus une à une
        $enAttente = Absence::query()->where('employe_id', $this->collegue->id)->where('statut', StatutAbsence::EnAttente)->sole();
        $this->getJson('/api/v1/absences/'.$enAttente->id, $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/absences/'.$mienne->id, $this->enTetes($jeton))->assertOk()->assertJsonPath('data.note', 'Mariage');
    }

    private function maladie(): Absence
    {
        return Absence::query()->where('type', TypeAbsence::Maladie)->sole();
    }

    public function test_le_planning_ne_montre_que_la_clinique_du_jeton(): void
    {
        $ailleurs = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $autre = Employe::factory()->dans($ailleurs)->create(['nom' => 'Paul Ailleurs']);
        Absence::factory()->create(['employe_id' => $autre->id, 'type' => TypeAbsence::Conge, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-16', 'fin_le' => '2026-11-20']);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $reponse = $this->planning($jeton);

        $this->assertNotContains($autre->id, array_column($reponse['data']['employes'], 'id'));
        $this->assertNotContains($autre->id, array_column($reponse['data']['absences'], 'employe_id'));
    }

    public function test_une_periode_trop_longue_est_refusee(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/equipe/planning?du=2026-01-01&au=2026-12-31', $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('au');
    }
}
