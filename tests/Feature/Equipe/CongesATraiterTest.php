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
 * La cloche de l'Équipe (Jim, 11.11.2026, É5 et É11) : les demandes à
 * valider, les jours reportés au-dessus du seuil pas encore planifiés
 * (à poser avant la date de l'organisation) et, dès le 1er septembre,
 * qui n'a pas encore eu deux semaines de suite (CO 329c).
 */
class CongesATraiterTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        // Lausanne : les jours fériés vaudois (É23)
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'npa' => '1003']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['entree_le' => '2020-01-01', 'created_at' => '2026-01-01']);
    }

    private function employe(): Employe
    {
        return Employe::factory()->dans($this->clinique)->create(['entree_le' => '2020-01-01', 'created_at' => '2025-01-01', 'conges_droit' => 25]);
    }

    private function conge(Employe $employe, string $debut, string $fin, StatutAbsence $statut = StatutAbsence::Validee): Absence
    {
        return Absence::factory()->create(['employe_id' => $employe->id, 'type' => TypeAbsence::Conge, 'statut' => $statut, 'debut_le' => $debut, 'fin_le' => $fin]);
    }

    private function aTraiter(?string $jeton = null): array
    {
        return $this->getJson('/api/v1/equipe/conges/a-traiter', $this->enTetes($jeton ?? $this->jeton))->assertOk()->json();
    }

    public function test_les_demandes_en_attente_sont_a_valider(): void
    {
        $employe = $this->employe();
        $demande = $this->conge($employe, '2026-12-14', '2026-12-18', StatutAbsence::EnAttente);
        $this->conge($employe, '2026-12-28', '2026-12-31');

        $reponse = $this->aTraiter();

        $this->assertSame([$demande->id], array_column($reponse['data']['a_valider'], 'id'));
        $this->assertSame(5, $reponse['data']['a_valider'][0]['jours']);
        $this->assertSame($employe->nom, $reponse['data']['a_valider'][0]['nom']);
    }

    public function test_les_jours_reportes_au_dessus_du_seuil_sont_a_planifier(): void
    {
        // 2025 : 17 jours pris sur 25, 8 jours reportés, rien de posé en 2026
        $beaucoup = $this->employe();
        $this->conge($beaucoup, '2025-07-07', '2025-07-25');
        $this->conge($beaucoup, '2025-10-06', '2025-10-07');
        // 2025 : 22 jours pris, 3 reportés, sous le seuil de 5
        $peu = $this->employe();
        $this->conge($peu, '2025-07-07', '2025-08-05');

        $aPlanifier = collect($this->aTraiter()['data']['a_planifier'])->keyBy('employe_id');

        $this->assertSame(['employe_id' => $beaucoup->id, 'nom' => $beaucoup->nom, 'initiales' => $beaucoup->initiales, 'reportes' => 8, 'a_planifier' => 8, 'avant_le' => '2026-03-31', 'est_depasse' => true], $aPlanifier[$beaucoup->id]);
        $this->assertFalse($aPlanifier->has($peu->id));
    }

    public function test_des_septembre_qui_n_a_pas_eu_deux_semaines_de_suite(): void
    {
        $deuxSemaines = $this->employe();
        $this->conge($deuxSemaines, '2026-07-06', '2026-07-17');
        $uneSemaine = $this->employe();
        $this->conge($uneSemaine, '2026-07-06', '2026-07-10');

        $ids = array_column($this->aTraiter()['data']['deux_semaines'], 'employe_id');
        $this->assertContains($uneSemaine->id, $ids);
        $this->assertNotContains($deuxSemaines->id, $ids);

        // Le rappel se coupe dans les règles ; avant septembre, rien
        $this->clinique->societe->organisation->forceFill(['conges_rappel_deux_semaines' => false])->save();
        $this->assertSame([], $this->aTraiter()['data']['deux_semaines']);
        $this->clinique->societe->organisation->forceFill(['conges_rappel_deux_semaines' => true])->save();
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->assertSame([], $this->aTraiter($jeton)['data']['deux_semaines']);
    }

    public function test_le_total_et_le_droit_equipe(): void
    {
        $this->conge($this->employe(), '2026-12-21', '2026-12-25', StatutAbsence::EnAttente);
        $reponse = $this->aTraiter();
        $this->assertSame(count($reponse['data']['a_valider']) + count($reponse['data']['a_planifier']) + count($reponse['data']['deux_semaines']), $reponse['meta']['total']);

        ['employe' => $moi, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $moi->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);
        $this->getJson('/api/v1/equipe/conges/a-traiter', $this->enTetes($jeton))->assertForbidden();
    }
}
