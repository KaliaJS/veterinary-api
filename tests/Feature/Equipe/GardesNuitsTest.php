<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Events\GardesModifiees;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Employe;
use App\Models\Garde;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La garde de chaque nuit (Jim, 11.11.2026 : « il faut simplement mettre
 * quand un vétérinaire est de garde mais il faut que ce soit dans le
 * planning ») : sans choix, la nuit va à qui en a eu le moins sur
 * quatre semaines (É24, « Balanced suggestion »), le week-end d'un bloc,
 * en passant un vétérinaire absent ; un choix s'enregistre pour la nuit,
 * le week-end d'un bloc. Le compteur de chaque semaine : les nuits des quatre
 * semaines qui finissent ce dimanche (OLT 2, art. 8b : 7 au plus).
 */
class GardesNuitsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    /** @var list<Employe> */
    private array $vets;

    protected function setUp(): void
    {
        parent::setUp();
        // Un mercredi
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        $this->vets = [
            Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Anna Meyer']),
            Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Kofi Adjei']),
            Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Lena Weber']),
        ];
    }

    private function nuits(string $du, string $au, ?string $jeton = null): array
    {
        return $this->getJson("/api/v1/equipe/gardes?du={$du}&au={$au}", $this->enTetes($jeton ?? $this->jeton))->assertOk()->json();
    }

    public function test_sans_choix_les_nuits_se_repartissent_et_le_week_end_va_d_un_bloc(): void
    {
        // É24 (Jim, 11.11.2026, « Balanced suggestion ») : chacun le moins possible, le week-end d'un bloc
        $reponse = $this->nuits('2026-11-09', '2026-12-06');

        $this->assertCount(28, $reponse['data']);
        $parJour = array_column($reponse['data'], 'employe_id', 'jour');
        foreach (['2026-11-14', '2026-11-21', '2026-11-28', '2026-12-05'] as $samedi) {
            $this->assertSame($parJour[$samedi], $parJour[CarbonImmutable::parse($samedi)->addDay()->toDateString()]);
        }
        $nuits = array_count_values(array_values($parJour));
        $this->assertCount(3, $nuits);
        // 28 nuits pour trois : 9 ou 10 chacun, au lieu de 7 à 12 avec la semaine de chacun
        $this->assertLessThanOrEqual(10, max($nuits));
        $this->assertGreaterThanOrEqual(9, min($nuits));
        $this->assertFalse($reponse['data'][0]['est_choisie']);
        $this->assertNotEmpty($reponse['data'][0]['initiales']);
    }

    public function test_une_nuit_montre_le_meme_veterinaire_quelle_que_soit_la_periode_demandee(): void
    {
        $longue = array_column($this->nuits('2026-10-01', '2026-12-31')['data'], 'employe_id', 'jour');
        $courte = array_column($this->nuits('2026-11-20', '2026-11-26')['data'], 'employe_id', 'jour');

        foreach ($courte as $jour => $id) {
            $this->assertSame($longue[$jour], $id, $jour);
        }
    }

    public function test_un_choix_vaut_pour_la_nuit_et_le_week_end_entier(): void
    {
        $avant = array_column($this->nuits('2026-11-09', '2026-11-15')['data'], 'employe_id', 'jour');
        $autre = collect($this->vets)->first(fn (Employe $v): bool => $v->id !== $avant['2026-11-12']);

        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $autre->id], $this->enTetes($this->jeton))->assertOk();
        $samedi = collect($this->vets)->first(fn (Employe $v): bool => $v->id !== $avant['2026-11-14']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-15', 'employe_id' => $samedi->id], $this->enTetes($this->jeton))->assertOk();

        $apres = collect($this->nuits('2026-11-09', '2026-11-15')['data'])->keyBy('jour');
        $this->assertSame($autre->id, $apres['2026-11-12']['employe_id']);
        $this->assertTrue($apres['2026-11-12']['est_choisie']);
        // Les autres nuits de la semaine ne bougent pas
        $this->assertSame($avant['2026-11-11'], $apres['2026-11-11']['employe_id']);
        // Le week-end se donne d'un bloc
        $this->assertSame($samedi->id, $apres['2026-11-14']['employe_id']);
        $this->assertSame($samedi->id, $apres['2026-11-15']['employe_id']);
    }

    public function test_sans_choix_un_veterinaire_absent_passe_son_tour(): void
    {
        $avant = array_column($this->nuits('2026-11-09', '2026-11-15')['data'], 'employe_id', 'jour');
        Absence::factory()->create([
            'employe_id' => $avant['2026-11-12'], 'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-12', 'fin_le' => '2026-11-12',
        ]);

        $apres = array_column($this->nuits('2026-11-09', '2026-11-15')['data'], 'employe_id', 'jour');

        $this->assertNotSame($avant['2026-11-12'], $apres['2026-11-12']);
        $this->assertNotNull($apres['2026-11-12']);
    }

    public function test_chaque_matin_la_nuit_passee_s_enregistre_et_regarder_n_ecrit_rien(): void
    {
        // É26, É27 (Jim, 11.11.2026, « Every night, automatically ») : le registre des gardes (OLT 1 art. 73)
        $this->nuits('2026-10-12', '2026-11-15');
        $this->assertSame(0, Garde::query()->count());

        $this->artisan('gardes:enregistrer')->assertSuccessful();
        $enregistrees = Garde::query()->orderBy('jour')->get()->mapWithKeys(fn (Garde $g): array => [$g->jour->toDateString() => $g->employe_id])->all();
        // Les quatre semaines avant aujourd'hui (le premier passage), jusqu'à hier
        $this->assertCount(27, $enregistrees);
        $this->assertSame(['2026-10-15', '2026-11-10'], [array_key_first($enregistrees), array_key_last($enregistrees)]);
        $this->assertFalse(Garde::query()->where('est_choisie', true)->exists());

        // Une embauche et une absence posée après coup ne changent rien aux nuits enregistrées
        Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'Vétérinaire', 'nom' => 'Dr Paul Neuf', 'entree_le' => '2026-11-11']);
        Absence::factory()->create(['employe_id' => $enregistrees['2026-11-05'], 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-05', 'fin_le' => '2026-11-05']);
        $apres = array_column($this->nuits('2026-10-15', '2026-11-10')['data'], 'employe_id', 'jour');

        $this->assertSame($enregistrees, $apres);
        // Le nouveau vétérinaire, entré aujourd'hui, prend des nuits à venir
        $this->assertContains(Employe::query()->where('nom', 'Dr Paul Neuf')->value('id'), array_column($this->nuits('2026-11-11', '2026-12-08')['data'], 'employe_id'));
    }

    public function test_le_registre_garde_qui_a_remplace_un_veterinaire_choisi_puis_absent(): void
    {
        // Le registre a déjà ses quatre semaines ; Kofi choisi pour jeudi, malade : un autre le remplace, et le registre dit cet autre
        $this->artisan('gardes:enregistrer')->assertSuccessful();
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[1]->id], $this->enTetes($this->jeton))->assertOk();
        Absence::factory()->create(['employe_id' => $this->vets[1]->id, 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-12', 'fin_le' => '2026-11-12']);
        $remplacant = collect($this->nuits('2026-11-12', '2026-11-12')['data'])->first()['employe_id'];
        $this->assertNotSame($this->vets[1]->id, $remplacant);

        $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Zurich'));
        $this->artisan('gardes:enregistrer')->assertSuccessful();
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);

        $nuit = collect($this->nuits('2026-11-12', '2026-11-12', $jeton)['data'])->first();
        $this->assertSame($remplacant, $nuit['employe_id']);
        $this->assertFalse($nuit['est_choisie']);
    }

    public function test_une_nuit_des_30_derniers_jours_se_corrige(): void
    {
        // É28 : qui l'a vraiment faite ; une correction ne touche que sa nuit (le samedi passé sans le dimanche)
        $avant = array_column($this->nuits('2026-11-07', '2026-11-08')['data'], 'employe_id', 'jour');
        $autre = collect($this->vets)->first(fn (Employe $v): bool => $v->id !== $avant['2026-11-07']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-07', 'employe_id' => $autre->id], $this->enTetes($this->jeton))->assertOk();

        $this->assertTrue(Garde::query()->whereDate('jour', '2026-11-07')->where('employe_id', $autre->id)->where('est_choisie', true)->exists());
        $this->assertFalse(Garde::query()->whereDate('jour', '2026-11-08')->exists());
        // Le dimanche, sans choix, suit le vétérinaire du samedi
        $this->assertSame($autre->id, collect($this->nuits('2026-11-08', '2026-11-08')['data'])->first()['employe_id']);

        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-10-11', 'employe_id' => $autre->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('jour');
    }

    public function test_un_choix_tient_sauf_si_son_veterinaire_devient_absent(): void
    {
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-18', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame($this->vets[0]->id, collect($this->nuits('2026-11-18', '2026-11-18')['data'])->first()['employe_id']);

        Absence::factory()->create([
            'employe_id' => $this->vets[0]->id, 'type' => TypeAbsence::Maladie,
            'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-18', 'fin_le' => '2026-11-19',
        ]);
        $nuit = collect($this->nuits('2026-11-18', '2026-11-18')['data'])->first();

        $this->assertNotSame($this->vets[0]->id, $nuit['employe_id']);
        $this->assertNotNull($nuit['employe_id']);
        $this->assertFalse($nuit['est_choisie']);
    }

    public function test_le_compteur_de_chaque_semaine_compte_quatre_semaines(): void
    {
        $reponse = $this->nuits('2026-11-09', '2026-11-22');

        $this->assertSame(7, $reponse['meta']['maximum']);
        $this->assertSame(['2026-11-09', '2026-11-16'], array_column($reponse['meta']['compteurs'], 'lundi'));
        $semaine = $reponse['meta']['compteurs'][0]['nuits'];
        // Les 28 nuits des quatre semaines finissant le 15 novembre, réparties entre les trois vétérinaires
        $this->assertSame(28, array_sum(array_column($semaine, 'nuits')));
        $this->assertEqualsCanonicalizing(array_map(fn (Employe $v): int => $v->id, $this->vets), array_column($semaine, 'employe_id'));
    }

    public function test_tout_employe_lit_les_gardes_seul_le_droit_equipe_les_change(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Equipe, 'est_accordee' => false]);

        $this->assertCount(7, $this->nuits('2026-11-09', '2026-11-15', $jeton)['data']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[0]->id], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_un_veterinaire_absent_un_des_jours_ou_suspendu_ne_se_choisit_pas(): void
    {
        // Absent le dimanche : le week-end, qui se donne d'un bloc, ne peut pas lui revenir
        Absence::factory()->create([
            'employe_id' => $this->vets[1]->id, 'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee, 'debut_le' => '2026-11-15', 'fin_le' => '2026-11-15',
        ]);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-14', 'employe_id' => $this->vets[1]->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');

        $this->vets[2]->update(['acces_suspendu' => true]);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[2]->id], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');
    }

    public function test_une_nuit_choisie_se_choisit_de_nouveau_et_le_dimanche_ne_touche_pas_le_samedi_passe(): void
    {
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertOk();
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[2]->id], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame($this->vets[2]->id, collect($this->nuits('2026-11-12', '2026-11-12')['data'])->first()['employe_id']);

        // Un dimanche : le samedi d'hier ne change plus
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        $samedi = collect($this->nuits('2026-11-14', '2026-11-14', $jeton)['data'])->first();
        $autre = collect($this->vets)->first(fn (Employe $v): bool => $v->id !== $samedi['employe_id']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-15', 'employe_id' => $autre->id], $this->enTetes($jeton))->assertOk();
        $apres = collect($this->nuits('2026-11-14', '2026-11-15', $jeton)['data'])->keyBy('jour');
        $this->assertSame($samedi['employe_id'], $apres['2026-11-14']['employe_id']);
        $this->assertFalse($apres['2026-11-14']['est_choisie']);
        $this->assertSame($autre->id, $apres['2026-11-15']['employe_id']);
    }

    public function test_un_choix_ou_une_absence_previent_le_planning_en_temps_reel(): void
    {
        Event::fake([GardesModifiees::class]);
        $canal = 'private-clinique.'.$this->clinique->id.'.planning';

        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertOk();
        Event::assertDispatched(GardesModifiees::class, fn (GardesModifiees $e): bool => $e->broadcastOn()[0]->name === $canal && $e->broadcastAs() === 'gardes.modifiees');

        // Une absence se pose avec le droit « Gérer les absences » (audit C04, 14.11.2026)
        ['jeton' => $jetonManager] = $this->employeAvecJeton($this->clinique, [], 'manager');
        Event::fake([GardesModifiees::class]);
        $this->postJson('/api/v1/absences', ['employe_id' => $this->vets[1]->id, 'type' => 'conge', 'debut_le' => '2026-11-20', 'fin_le' => '2026-11-20', 'statut' => 'ok'], $this->enTetes($jetonManager))->assertCreated();
        Event::assertDispatched(GardesModifiees::class, fn (GardesModifiees $e): bool => $e->broadcastOn()[0]->name === $canal);
    }

    public function test_une_nuit_choisie_ou_corrigee_tient_dans_le_registre_meme_avec_une_absence_posee_apres_coup(): void
    {
        // Corrigée (É28) : la nuit du 2 novembre est à Lena, enregistrée par qui l'a corrigée
        ['user' => $manager, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-02', 'employe_id' => $this->vets[2]->id], $this->enTetes($jeton))->assertOk();
        $garde = Garde::query()->whereDate('jour', '2026-11-02')->sole();
        $this->assertNotNull($garde->enregistree_le);
        $this->assertSame($manager->id, $garde->choisie_par_user_id);
        // Choisie d'avance pour jeudi, puis enregistrée le lendemain matin
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[0]->id], $this->enTetes($jeton))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Zurich'));
        $this->artisan('gardes:enregistrer')->assertSuccessful();
        $this->assertNotNull(Garde::query()->whereDate('jour', '2026-11-12')->sole()->enregistree_le);

        // Des maladies posées après coup ne changent ni l'une ni l'autre
        foreach ([[$this->vets[2], '2026-11-02'], [$this->vets[0], '2026-11-12']] as [$vet, $jour]) {
            Absence::factory()->create(['employe_id' => $vet->id, 'type' => TypeAbsence::Maladie, 'statut' => StatutAbsence::Validee, 'debut_le' => $jour, 'fin_le' => $jour]);
        }
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        $nuits = array_column($this->nuits('2026-11-02', '2026-11-12', $jeton)['data'], 'employe_id', 'jour');
        $this->assertSame($this->vets[2]->id, $nuits['2026-11-02']);
        $this->assertSame($this->vets[0]->id, $nuits['2026-11-12']);
    }

    public function test_le_premier_passage_enregistre_les_quatre_semaines_et_continue_si_une_clinique_echoue(): void
    {
        $this->artisan('gardes:enregistrer')->assertSuccessful();

        // Les 27 nuits avant aujourd'hui : la suggestion ne bouge plus d'un jour à l'autre
        $this->assertSame(27, Garde::query()->where('clinique_id', $this->clinique->id)->count());
        $this->assertSame('2026-10-15', Garde::query()->orderBy('jour')->first()->jour->toDateString());
    }

    public function test_une_correction_ne_peut_ni_effacer_le_registre_ni_viser_trop_loin(): void
    {
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-02', 'employe_id' => null], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '9999-12-31', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('jour');
        // Hors contrat cette nuit-là : refusé ; un vétérinaire parti depuis se nomme encore pour une nuit passée
        $this->vets[1]->update(['fin_contrat_le' => '2026-11-05']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-06', 'employe_id' => $this->vets[1]->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $this->vets[1]->update(['acces_suspendu' => true]);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-04', 'employe_id' => $this->vets[1]->id], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame('Dr Kofi Adjei', collect($this->nuits('2026-11-04', '2026-11-04')['data'])->first()['nom']);
    }

    public function test_le_dimanche_ne_va_pas_a_un_veterinaire_suspendu_nomme_le_samedi(): void
    {
        // Un dimanche : samedi corrigé au nom d'un vétérinaire parti depuis (accès suspendu)
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, ['role_libelle' => 'ASV']);
        $this->vets[1]->update(['acces_suspendu' => true]);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-14', 'employe_id' => $this->vets[1]->id], $this->enTetes($jeton))->assertOk();

        $nuits = $this->nuits('2026-11-09', '2026-11-15', $jeton);
        $parJour = array_column($nuits['data'], 'employe_id', 'jour');
        $this->assertSame($this->vets[1]->id, $parJour['2026-11-14']);
        $this->assertNotSame($this->vets[1]->id, $parJour['2026-11-15']);
        // Ses nuits passées comptent encore dans le compteur des quatre semaines
        $this->assertContains($this->vets[1]->id, array_column($nuits['meta']['compteurs'][0]['nuits'], 'employe_id'));
    }

    public function test_un_choix_d_avance_d_un_veterinaire_suspendu_depuis_n_entre_pas_au_registre(): void
    {
        // Le registre a déjà ses quatre semaines (le premier passage) ; choisi pour jeudi, puis suspendu avant :
        // l'équipe a vu quelqu'un d'autre, le registre le dit
        $this->artisan('gardes:enregistrer')->assertSuccessful();
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $this->vets[1]->id], $this->enTetes($this->jeton))->assertOk();
        $this->vets[1]->update(['acces_suspendu' => true]);
        $vu = collect($this->nuits('2026-11-12', '2026-11-12')['data'])->first()['employe_id'];
        $this->assertNotSame($this->vets[1]->id, $vu);

        $this->travelTo(CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Zurich'));
        $this->artisan('gardes:enregistrer')->assertSuccessful();

        $this->assertSame($vu, Garde::query()->whereDate('jour', '2026-11-12')->sole()->employe_id);
    }

    public function test_une_periode_ancienne_se_lit(): void
    {
        $reponse = $this->nuits('2026-08-01', '2026-08-31');
        $this->assertCount(31, $reponse['data']);
        $this->assertNotNull($reponse['data'][0]['employe_id']);
    }

    public function test_une_periode_trop_longue_ou_un_non_veterinaire_sont_refuses(): void
    {
        $this->getJson('/api/v1/equipe/gardes?du=2026-01-01&au=2026-12-31', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('au');
        // Une saisie mal formée : une erreur de validation, jamais une erreur du serveur
        $this->putJson('/api/v1/equipe/gardes', ['jour' => 'abc', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('jour');
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-13-01', 'employe_id' => $this->vets[0]->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('jour');
        $this->getJson('/api/v1/equipe/gardes?du=2026-01-32&au=2026-02-02', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('du');
        $this->getJson('/api/v1/equipe/planning?du=2026-13-01&au=2026-12-31', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('du');
        // Trois ans autour d'aujourd'hui au plus ; une date en liste
        $this->getJson('/api/v1/equipe/gardes?du=1900-01-01&au=1900-01-31', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('du');
        $this->getJson('/api/v1/equipe/gardes?du=9999-12-01&au=9999-12-31', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('au');
        $this->getJson('/api/v1/equipe/gardes?du[]=1&au=2026-11-12', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('du');
        $this->postJson('/api/v1/equipe/demandes-conge', ['debut_le' => ['2026-12-01'], 'fin_le' => '2026-12-02'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('debut_le');
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => [$this->vets[0]->id]], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $asv = Employe::factory()->dans($this->clinique)->create(['role_libelle' => 'ASV']);
        $this->putJson('/api/v1/equipe/gardes', ['jour' => '2026-11-12', 'employe_id' => $asv->id], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('employe_id');
    }
}
