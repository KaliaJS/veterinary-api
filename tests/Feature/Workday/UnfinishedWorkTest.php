<?php

namespace Tests\Feature\Workday;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\ServiceClinique;
use App\Enums\StatutAnalyse;
use App\Enums\StatutIntervention;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Models\Analyse;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Intervention;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\Sejour;
use App\Models\StaySheet;
use App\Models\SurgeryReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * « À finir aujourd'hui » (08.11.2026) : le travail du jour d'un
 * vétérinaire, chaque sorte comptée, ce qui reste d'avant ajouté sans
 * limite, le pourcentage servi, l'heure du rappel du menu ; les autres
 * personnes ne sont pas concernées.
 */
class UnfinishedWorkTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $vet;

    private string $jeton;

    private LignePlanning $lane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-08 17:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create();
        ['employe' => $this->vet, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->lane = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id, 'employe_id' => $this->vet->id]);
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Europe/Zurich')->utc();
    }

    private function appointment(string $start, StatutRendezVous $status, TypeRendezVous $type = TypeRendezVous::Consultation, ?LignePlanning $lane = null): RendezVous
    {
        return RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => ($lane ?? $this->lane)->id,
            'type' => $type,
            'status' => $status,
            'patient_name' => 'Patient '.$start,
            'starts_at' => $this->at($start),
            'ends_at' => $this->at($start)->addMinutes(30),
        ]);
    }

    private function surgery(string $start, StatutIntervention $status, array $attributes = []): Intervention
    {
        return Intervention::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient()->id,
            'chirurgien_id' => $this->vet->id,
            'prevu_le' => $this->at($start),
            'duree_minutes' => 60,
            'statut' => $status,
            ...$attributes,
        ]);
    }

    private function patient(string $nom = 'Filou'): Patient
    {
        return Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => $nom]);
    }

    private function fetch(?string $jeton = null): TestResponse
    {
        return $this->withToken($jeton ?? $this->jeton)->getJson('/api/v1/unfinished-work')->assertOk();
    }

    /** @return array<string, array{count: int, lines: list<array<string, mixed>>}> */
    private function groupsOf(TestResponse $response): array
    {
        return collect($response->json('data.groups'))->keyBy('kind')->all();
    }

    public function test_un_compte_rendu_a_rediger_est_compte(): void
    {
        $this->appointment('2026-11-08 09:00', StatutRendezVous::Fini);
        $written = $this->appointment('2026-11-08 10:00', StatutRendezVous::Fini);
        CompteRendu::factory()->create(['rendez_vous_id' => $written->id, 'signe_le' => now()]);

        $groups = $this->groupsOf($response = $this->fetch());

        $this->assertSame(1, $groups['report_to_write']['count']);
        $this->assertSame('Patient 2026-11-08 09:00', $groups['report_to_write']['lines'][0]['patient']);
        $this->assertSame(['type' => 'appointment', 'id' => RendezVous::query()->where('patient_name', 'Patient 2026-11-08 09:00')->value('id')], $groups['report_to_write']['lines'][0]['target']);
        $this->assertArrayNotHasKey('report_to_sign', $groups);
        $this->assertTrue($response->json('data.is_concerned'));
    }

    public function test_un_compte_rendu_a_signer_est_compte(): void
    {
        $rdv = $this->appointment('2026-11-08 11:00', StatutRendezVous::Fini);
        CompteRendu::factory()->create(['rendez_vous_id' => $rdv->id, 'signe_le' => null]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['report_to_sign']['count']);
        $this->assertArrayNotHasKey('report_to_write', $groups);
    }

    public function test_un_rapport_de_bloc_vide_est_compte(): void
    {
        // Depuis le 09.11.2026 (IA pour le rapport de bloc, choix de Jim) : seul un rapport validé boucle l'intervention, une note ne suffit plus
        $this->surgery('2026-11-08 08:00', StatutIntervention::Fini, ['note_operatoire' => 'Castration sans complication.', 'consent_method' => 'oral', 'consentement_signe_le' => now()]);
        $done = $this->surgery('2026-11-08 10:00', StatutIntervention::Fini, ['note_operatoire' => 'Castration sans complication.', 'consent_method' => 'oral', 'consentement_signe_le' => now()]);
        SurgeryReport::query()->create(['intervention_id' => $done->id, 'status' => 'validated', 'sections' => ['technique' => 'Castration sans complication.'], 'validated_at' => now()]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['surgery_report']['count']);
        $this->assertSame('surgery', $groups['surgery_report']['lines'][0]['target']['type']);
        $this->assertArrayNotHasKey('consent', $groups);
    }

    public function test_un_rapport_d_hospitalisation_non_signe_est_compte_pour_chaque_jour(): void
    {
        $stay = Sejour::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient('Rocky')->id,
            'veterinaire_id' => $this->vet->id,
            'box' => 3,
            'admis_le' => $this->at('2026-11-07 10:00'),
        ]);
        StaySheet::query()->create(['sejour_id' => $stay->id, 'day' => '2026-11-07', 'opened_at' => now(), 'report_status' => StaySheet::REPORT_SIGNED, 'report_signed_at' => now()]);
        // Le séjour d'un confrère ne compte pas
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient()->id, 'veterinaire_id' => Employe::factory()->dans($this->clinique)->create()->id]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['stay_report']['count']);
        $this->assertSame(['type' => 'stay', 'id' => $stay->id, 'day' => '2026-11-08'], $groups['stay_report']['lines'][0]['target']);
        $this->assertSame('Rocky', $groups['stay_report']['lines'][0]['patient']);
        $this->assertSame('none', $groups['stay_report']['lines'][0]['report_status']);
    }

    public function test_une_analyse_a_valider_est_comptee(): void
    {
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'statut' => StatutAnalyse::AValider, 'preleve_le' => $this->at('2026-11-08 13:00')]);
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'statut' => StatutAnalyse::Attente, 'preleve_le' => $this->at('2026-11-08 14:00')]);
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'statut' => StatutAnalyse::AValider, 'preleve_le' => $this->at('2026-11-08 13:00')]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['lab']['count']);
    }

    public function test_l_imagerie_a_interpreter_est_comptee(): void
    {
        ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'realise_le' => $this->at('2026-11-08 10:40'), 'libelle' => 'Thorax']);
        ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'realise_le' => $this->at('2026-11-08 11:40'), 'signe_le' => now()]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['imaging']['count']);
        $this->assertSame('Thorax', $groups['imaging']['lines'][0]['label']);
    }

    public function test_un_rendez_vous_reste_ouvert_est_compte(): void
    {
        $this->appointment('2026-11-08 15:30', StatutRendezVous::Prevu);
        $this->appointment('2026-11-08 14:00', StatutRendezVous::Noshow);
        $this->appointment('2026-11-08 13:00', StatutRendezVous::Annule);
        $this->appointment('2026-11-08 17:30', StatutRendezVous::Prevu);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['open_appointment']['count']);
        $this->assertSame('prevu', $groups['open_appointment']['lines'][0]['status']);
    }

    public function test_un_consentement_non_recueilli_est_compte(): void
    {
        $this->surgery('2026-11-08 18:00', StatutIntervention::Prevu);
        $this->surgery('2026-11-08 19:00', StatutIntervention::Prevu, ['consent_method' => 'signed', 'consentement_signe_le' => now()]);
        // Demain : pas encore à finir aujourd'hui
        $this->surgery('2026-11-09 09:00', StatutIntervention::Prevu);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['consent']['count']);
    }

    public function test_ce_qui_reste_des_jours_d_avant_s_ajoute_sans_limite(): void
    {
        $this->appointment('2025-06-02 09:00', StatutRendezVous::Fini);
        $this->appointment('2026-11-07 09:00', StatutRendezVous::Fini);
        // Fait un autre jour : ni dans la liste ni dans le pourcentage
        $done = $this->appointment('2026-11-06 09:00', StatutRendezVous::Fini);
        CompteRendu::factory()->create(['rendez_vous_id' => $done->id, 'signe_le' => now()]);

        $response = $this->fetch();
        $groups = $this->groupsOf($response);

        $this->assertSame(2, $groups['report_to_write']['count']);
        $this->assertTrue($groups['report_to_write']['lines'][0]['is_earlier']);
        $this->assertSame(0, $response->json('data.done'));
        $this->assertSame(2, $response->json('data.left'));
    }

    public function test_le_pourcentage_est_servi_par_l_api(): void
    {
        // Aujourd'hui : 2 rendez-vous finis (2 clos), 1 compte rendu signé sur 2, 1 analyse validée
        $signed = $this->appointment('2026-11-08 09:00', StatutRendezVous::Fini);
        CompteRendu::factory()->create(['rendez_vous_id' => $signed->id, 'signe_le' => now()]);
        $this->appointment('2026-11-08 10:00', StatutRendezVous::Fini);
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'statut' => StatutAnalyse::Valide, 'valide_le' => now(), 'preleve_le' => $this->at('2026-11-08 08:00')]);
        // D'avant : 1 compte rendu à rédiger
        $this->appointment('2026-11-02 09:00', StatutRendezVous::Fini);

        $response = $this->fetch();

        // Faites aujourd'hui : 2 rendez-vous clos, 1 compte rendu, 1 analyse = 4 ; restent 2 comptes rendus
        $this->assertSame(4, $response->json('data.done'));
        $this->assertSame(2, $response->json('data.left'));
        $this->assertSame(6, $response->json('data.total'));
        $this->assertSame(66, $response->json('data.percent'));
    }

    public function test_une_chose_d_un_jour_d_avant_bouclee_aujourd_hui_ne_compte_pas_comme_faite_aujourd_hui(): void
    {
        // Même règle pour toutes les sortes : le jour d'origine (ici le prélèvement d'hier)
        Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'prescripteur_id' => $this->vet->id, 'statut' => StatutAnalyse::Valide, 'valide_le' => now(), 'preleve_le' => $this->at('2026-11-07 09:00')]);
        $rdv = $this->appointment('2026-11-07 09:00', StatutRendezVous::Fini);
        CompteRendu::factory()->create(['rendez_vous_id' => $rdv->id, 'signe_le' => now()]);

        $response = $this->fetch();

        $this->assertSame(0, $response->json('data.done'));
        $this->assertNull($response->json('data.percent'));
    }

    public function test_une_intervention_d_un_jour_d_avant_jamais_close_reste_a_terminer(): void
    {
        $this->surgery('2026-11-07 15:00', StatutIntervention::Reveil, ['consent_method' => 'oral', 'consentement_signe_le' => now()]);
        // Celle d'aujourd'hui, en cours : pas encore à terminer
        $this->surgery('2026-11-08 16:00', StatutIntervention::Cours, ['consent_method' => 'oral', 'consentement_signe_le' => now()]);

        $groups = $this->groupsOf($this->fetch());

        $this->assertSame(1, $groups['surgery_report']['count']);
        $this->assertTrue($groups['surgery_report']['lines'][0]['is_earlier']);
    }

    public function test_un_sejour_dont_tout_est_signe_ne_s_ajoute_pas_et_son_rapport_du_jour_compte_fait(): void
    {
        $stay = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient()->id, 'veterinaire_id' => $this->vet->id, 'admis_le' => $this->at('2026-11-07 10:00')]);
        foreach (['2026-11-07', '2026-11-08'] as $day) {
            StaySheet::query()->create(['sejour_id' => $stay->id, 'day' => $day, 'opened_at' => now(), 'report_status' => StaySheet::REPORT_SIGNED, 'report_signed_at' => now()]);
        }

        $response = $this->fetch();

        $this->assertSame([], $response->json('data.groups'));
        $this->assertSame(1, $response->json('data.done'));
        $this->assertSame(100, $response->json('data.percent'));
    }

    public function test_sans_le_service_d_hospitalisation_les_sejours_ne_comptent_pas(): void
    {
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient()->id, 'veterinaire_id' => $this->vet->id]);
        $this->clinique->services()->where('service', ServiceClinique::Hospitalisation)->delete();

        $this->assertSame([], $this->fetch()->json('data.groups'));
    }

    public function test_sans_le_droit_patients_la_route_est_refusee(): void
    {
        $role = Role::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'domaines' => [DomainePermission::Planning]]);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_id' => $role->id]);

        $this->withToken($jeton)->getJson('/api/v1/unfinished-work')->assertForbidden();
    }

    public function test_sans_rien_a_faire_ni_fait_le_pourcentage_est_vide(): void
    {
        $response = $this->fetch();

        $this->assertNull($response->json('data.percent'));
        $this->assertSame([], $response->json('data.groups'));
    }

    public function test_le_rappel_vient_quinze_minutes_apres_le_dernier_rendez_vous_ou_la_derniere_intervention(): void
    {
        $this->appointment('2026-11-08 17:30', StatutRendezVous::Prevu);
        $this->appointment('2026-11-08 18:30', StatutRendezVous::Annule);

        $this->assertSame($this->at('2026-11-08 18:15')->toIso8601String(), $this->fetch()->json('data.reminder_at'));

        // Le contexte de la requête survit dans les tests : on repart neutre pour créer la suite
        app(ContexteClinique::class)->oublier();
        $this->surgery('2026-11-08 18:00', StatutIntervention::Prevu);

        $this->assertSame($this->at('2026-11-08 19:15')->toIso8601String(), $this->fetch()->json('data.reminder_at'));
    }

    public function test_apres_minuit_une_urgence_commencee_la_veille_garde_son_rappel(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 00:10', 'Europe/Zurich'));
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->lane->id,
            'type' => TypeRendezVous::Urgence,
            'status' => StatutRendezVous::Cours,
            'starts_at' => $this->at('2026-11-08 23:30'),
            'ends_at' => $this->at('2026-11-09 00:20'),
        ]);

        $this->assertSame($this->at('2026-11-09 00:35')->toIso8601String(), $this->fetch()->json('data.reminder_at'));
    }

    public function test_sans_rendez_vous_ni_intervention_pas_de_rappel(): void
    {
        $this->assertNull($this->fetch()->json('data.reminder_at'));
    }

    public function test_le_travail_d_un_confrere_ne_compte_pas(): void
    {
        $other = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->appointment('2026-11-08 09:00', StatutRendezVous::Fini, lane: $other);

        $this->assertSame([], $this->fetch()->json('data.groups'));
    }

    public function test_une_personne_qui_n_est_pas_veterinaire_n_est_pas_concernee(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, etat: 'assistant');

        $this->assertSame(['is_concerned' => false], $this->fetch($jeton)->json('data'));
    }
}
