<?php

namespace Tests\Feature\Planning;

use App\Enums\StatutAbsence;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Qui gère les absences (audit C04, décision de Jim du 14.11.2026) : le
 * droit « Gérer les absences » (Manager, Administrateur) valide, refuse et
 * pose une absence pour un autre ; « Voir l'équipe », donné à tous, n'y
 * suffit plus. Personne ne valide sa propre absence, sauf un
 * administrateur ; poser ses congés sans validation (réglage de
 * l'organisation) ne vaut que pour soi.
 */
class AbsenceDroitsTest extends TestCase
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

    public function test_valider_ou_refuser_demande_le_droit_de_gerer_les_absences(): void
    {
        $demande = $this->absenceDe($this->collegue, StatutAbsence::EnAttente);

        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $this->postJson("/api/v1/absences/{$demande->id}/valider", [], $this->enTetes($jeton))->assertForbidden();
            $this->postJson("/api/v1/absences/{$demande->id}/refuser", [], $this->enTetes($jeton))->assertForbidden();
        }
        $this->assertSame(StatutAbsence::EnAttente, $demande->refresh()->statut);

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->postJson("/api/v1/absences/{$demande->id}/valider", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'ok');
    }

    public function test_personne_ne_valide_ni_ne_refuse_sa_propre_demande_sauf_l_administrateur(): void
    {
        ['employe' => $manager, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $demande = $this->absenceDe($manager, StatutAbsence::EnAttente);

        $this->postJson("/api/v1/absences/{$demande->id}/valider", [], $this->enTetes($jeton))->assertForbidden();
        $this->postJson("/api/v1/absences/{$demande->id}/refuser", [], $this->enTetes($jeton))->assertForbidden();
        $this->assertSame(StatutAbsence::EnAttente, $demande->refresh()->statut);

        ['employe' => $administrateur, 'jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $sienne = $this->absenceDe($administrateur, StatutAbsence::EnAttente);
        $this->postJson("/api/v1/absences/{$sienne->id}/valider", [], $this->enTetes($jetonAdmin))
            ->assertOk()
            ->assertJsonPath('data.statut', 'ok');
    }

    public function test_poser_une_absence_pour_un_autre_demande_le_droit_de_gerer_les_absences(): void
    {
        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            foreach (['att', 'ok'] as $statut) {
                $this->postJson('/api/v1/absences', $this->saisie($this->collegue, $statut), $this->enTetes($jeton))->assertForbidden();
            }
        }
        $this->assertDatabaseCount('absences', 0);

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->postJson('/api/v1/absences', $this->saisie($this->collegue, 'ok'), $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'ok');
    }

    public function test_une_absence_validee_pour_soi_ne_se_pose_que_par_l_administrateur(): void
    {
        ['employe' => $manager, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');

        $this->postJson('/api/v1/absences', $this->saisie($manager, 'ok'), $this->enTetes($jeton))->assertForbidden();
        // Une demande pour soi reste possible : quelqu'un d'autre la valide
        $this->postJson('/api/v1/absences', $this->saisie($manager, 'att'), $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'att');

        ['employe' => $administrateur, 'jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $this->postJson('/api/v1/absences', $this->saisie($administrateur, 'ok'), $this->enTetes($jetonAdmin))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'ok');
    }

    public function test_sa_propre_absence_validee_ne_se_modifie_ni_ne_se_supprime_sauf_par_l_administrateur(): void
    {
        ['employe' => $manager, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $validee = $this->absenceDe($manager, StatutAbsence::Validee);
        $refusee = $this->absenceDe($manager, StatutAbsence::Refusee, decalage: 20);

        foreach ([$validee, $refusee] as $absence) {
            $this->patchJson("/api/v1/absences/{$absence->id}", ['fin_le' => $absence->fin_le->addDays(5)->toDateString()], $this->enTetes($jeton))->assertForbidden();
            $this->deleteJson("/api/v1/absences/{$absence->id}", [], $this->enTetes($jeton))->assertForbidden();
        }
        $this->assertSame($validee->fin_le->toDateString(), $validee->refresh()->fin_le->toDateString());

        // Sa demande en attente, elle, se corrige ou se retire
        $demande = $this->absenceDe($manager, StatutAbsence::EnAttente, decalage: 40);
        $this->patchJson("/api/v1/absences/{$demande->id}", ['note' => 'Mariage'], $this->enTetes($jeton))->assertOk();
        $this->deleteJson("/api/v1/absences/{$demande->id}", [], $this->enTetes($jeton))->assertNoContent();

        ['employe' => $administrateur, 'jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $sienne = $this->absenceDe($administrateur, StatutAbsence::Validee);
        $this->patchJson("/api/v1/absences/{$sienne->id}", ['note' => 'Formation'], $this->enTetes($jetonAdmin))->assertOk();
    }

    public function test_modifier_ou_supprimer_l_absence_d_un_autre_demande_le_droit_de_gerer_les_absences(): void
    {
        $absence = $this->absenceDe($this->collegue, StatutAbsence::Validee);

        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $this->patchJson("/api/v1/absences/{$absence->id}", ['note' => 'Changée'], $this->enTetes($jeton))->assertForbidden();
            $this->deleteJson("/api/v1/absences/{$absence->id}", [], $this->enTetes($jeton))->assertForbidden();
        }
        $this->assertModelExists($absence);

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->patchJson("/api/v1/absences/{$absence->id}", ['note' => 'Changée'], $this->enTetes($jeton))->assertOk();
        $this->deleteJson("/api/v1/absences/{$absence->id}", [], $this->enTetes($jeton))->assertNoContent();
    }

    public function test_les_conges_a_traiter_demandent_le_droit_de_gerer_les_absences(): void
    {
        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $this->getJson('/api/v1/equipe/conges/a-traiter', $this->enTetes($jeton))->assertForbidden();
        }
    }

    public function test_la_cloche_ne_propose_pas_sa_propre_demande_sauf_a_l_administrateur(): void
    {
        ['employe' => $manager, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $sienne = $this->absenceDe($manager, StatutAbsence::EnAttente);
        $celleDuCollegue = $this->absenceDe($this->collegue, StatutAbsence::EnAttente);

        $this->getJson('/api/v1/equipe/conges/a-traiter', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.a_valider')
            ->assertJsonPath('data.a_valider.0.id', $celleDuCollegue->id);

        ['employe' => $administrateur, 'jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $celleDeLAdministrateur = $this->absenceDe($administrateur, StatutAbsence::EnAttente, decalage: 20);
        $ids = collect($this->getJson('/api/v1/equipe/conges/a-traiter', $this->enTetes($jetonAdmin))->assertOk()->json('data.a_valider'))->pluck('id');
        $this->assertEqualsCanonicalizing([$sienne->id, $celleDuCollegue->id, $celleDeLAdministrateur->id], $ids->all());
    }

    public function test_poser_ses_conges_sans_validation_ne_vaut_que_pour_soi(): void
    {
        $this->clinique->societe->organisation->forceFill(['conges_sans_validation' => true])->save();
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $this->postJson('/api/v1/absences', $this->saisie($this->collegue, 'ok'), $this->enTetes($jeton))->assertForbidden();
        $this->postJson('/api/v1/absences', $this->saisie($this->collegue, 'att'), $this->enTetes($jeton))->assertForbidden();
        $demande = $this->absenceDe($this->collegue, StatutAbsence::EnAttente);
        $this->postJson("/api/v1/absences/{$demande->id}/valider", [], $this->enTetes($jeton))->assertForbidden();

        $this->postJson('/api/v1/equipe/demandes-conge', [
            'debut_le' => $this->lundi(30)->toDateString(),
            'fin_le' => $this->lundi(30)->addDays(2)->toDateString(),
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'ok');
    }

    private function absenceDe(Employe $employe, StatutAbsence $statut, int $decalage = 0): Absence
    {
        return Absence::factory()->create([
            'employe_id' => $employe->id,
            'type' => 'conge',
            'statut' => $statut,
            'debut_le' => $this->lundi(7 + $decalage),
            'fin_le' => $this->lundi(7 + $decalage)->addDays(2),
        ]);
    }

    /** @return array<string, mixed> */
    private function saisie(Employe $employe, string $statut): array
    {
        return [
            'employe_id' => $employe->id,
            'type' => 'formation',
            'debut_le' => $this->lundi(14)->toDateString(),
            'fin_le' => $this->lundi(14)->addDay()->toDateString(),
            'statut' => $statut,
        ];
    }

    /** Un lundi à venir : des jours travaillés, jamais un week-end */
    private function lundi(int $dansAuMoins): Carbon
    {
        return aujourdhui()->addDays($dansAuMoins)->startOfWeek();
    }
}
