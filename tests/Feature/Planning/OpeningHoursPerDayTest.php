<?php

namespace Tests\Feature\Planning;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Role;
use App\Models\UrgenceAttente;
use App\Services\HorairesSiteWeb;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Horaires jour par jour (demande de Jim, 04.11.2026) : chaque jour
 * ouvert ou fermé, avec une ou deux plages (matin et après-midi). Tous
 * les lecteurs des horaires suivent ces plages, et aucun créneau ne
 * tombe dans la coupure de midi : planning, calendrier des clients,
 * demandes en ligne, horaires proposés par la clinique, urgences au plus
 * tôt, annuaire et site.
 */
class OpeningHoursPerDayTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    private CarbonImmutable $lundi;

    /** Une semaine type : lundi, mardi et vendredi coupés à midi, mercredi le matin seulement, jeudi en continu, fermé le week-end. */
    private function semaine(): array
    {
        $coupe = ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:30', 'end' => '18:00']]];

        return [
            $coupe,
            $coupe,
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '12:00']]],
            ['is_open' => true, 'ranges' => [['start' => '08:00', 'end' => '18:00']]],
            $coupe,
            ['is_open' => false, 'ranges' => [['start' => '09:00', 'end' => '12:00']]],
            ['is_open' => false, 'ranges' => []],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id, 'days' => $this->semaine()]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->lundi = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::MONDAY);

        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    private function local(CarbonImmutable $jour, string $heure): CarbonImmutable
    {
        [$h, $m] = explode(':', $heure);

        return CarbonImmutable::parse($jour->toDateString(), 'Europe/Zurich')->setTime((int) $h, (int) $m);
    }

    // ————— Les horaires eux-mêmes —————

    public function test_les_horaires_se_lisent_jour_par_jour_avec_leurs_plages(): void
    {
        $this->getJson('/api/v1/horaires', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fuseau_horaire', 'Europe/Zurich')
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.days.0.day', 1)
            ->assertJsonPath('data.days.0.is_open', true)
            ->assertJsonPath('data.days.0.ranges', [['start' => '08:00', 'end' => '12:00'], ['start' => '13:30', 'end' => '18:00']])
            ->assertJsonPath('data.days.2.ranges', [['start' => '08:00', 'end' => '12:00']])
            ->assertJsonPath('data.days.5.is_open', false)
            ->assertJsonPath('data.days.6.day', 7);
    }

    public function test_une_clinique_neuve_ouvre_du_lundi_au_samedi_coupee_a_midi(): void
    {
        $neuve = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->getJson("/api/v1/organisation/cliniques/{$neuve->id}/horaires", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.0.ranges', [['start' => '08:00', 'end' => '12:30'], ['start' => '14:00', 'end' => '19:30']])
            ->assertJsonPath('data.days.5.is_open', true)
            ->assertJsonPath('data.days.6.is_open', false);
    }

    public function test_chaque_jour_s_enregistre_ouvert_ou_ferme_avec_une_ou_deux_plages(): void
    {
        $jours = $this->semaine();
        $jours[2] = ['is_open' => false, 'ranges' => [['start' => '08:00', 'end' => '12:00']]];
        $jours[5] = ['is_open' => true, 'ranges' => [['start' => '09:00', 'end' => '12:00']]];

        $this->putJson('/api/v1/horaires', ['days' => $jours], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.2.is_open', false)
            ->assertJsonPath('data.days.5.is_open', true)
            ->assertJsonPath('data.days.5.ranges', [['start' => '09:00', 'end' => '12:00']]);

        // La même chose depuis l'Organisation, pour une clinique sœur
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->putJson("/api/v1/organisation/cliniques/{$soeur->id}/horaires", ['days' => $jours], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.0.ranges.1.start', '13:30');

        $this->assertSame($jours[5]['ranges'], HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $soeur->id)->sole()->days[5]['ranges']);
    }

    public function test_les_plages_mal_formees_sont_refusees_sous_leur_jour(): void
    {
        $jours = $this->semaine();
        $jours[0]['ranges'][] = ['start' => '18:30', 'end' => '20:00']; // trois plages
        $jours[1]['ranges'][0] = ['start' => '08:00', 'end' => '08:15']; // trop courte
        $jours[2]['ranges'] = [['start' => '08:00', 'end' => '12:00'], ['start' => '12:05', 'end' => '18:00']]; // coupure trop courte, hors du pas
        $jours[3] = ['is_open' => true, 'ranges' => []]; // ouvert sans plage
        $jours[4]['ranges'] = [['start' => '13:30', 'end' => '18:00'], ['start' => '08:00', 'end' => '12:00']]; // l'après-midi avant le matin

        $this->putJson('/api/v1/horaires', ['days' => $jours], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days.0.ranges', 'days.1.ranges.0.end', 'days.2.ranges.1.start', 'days.3.ranges', 'days.4.ranges.1.start']);

        $this->putJson('/api/v1/horaires', ['days' => array_slice($jours, 0, 6)], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }

    public function test_la_reprise_des_anciens_horaires_coupe_chaque_jour_par_la_pause(): void
    {
        $ajout = 'database/migrations/2026_11_04_000100_opening_hours_per_day.php';
        $retrait = 'database/migrations/2026_11_04_000101_drop_legacy_opening_hours.php';
        HoraireOuverture::withoutGlobalScopes()->delete();

        // Retour au modèle d'avant (base de test seulement), puis les anciennes lignes
        Artisan::call('migrate:rollback', ['--path' => $retrait, '--force' => true]);
        Artisan::call('migrate:rollback', ['--path' => $ajout, '--force' => true]);
        $avec = Clinique::factory()->create();
        $sansPause = Clinique::factory()->create();
        $samediCourt = Clinique::factory()->create();
        DB::table('horaires_ouverture')->insert([
            ['clinique_id' => $avec->id, 'ouverture' => '08:00:00', 'fermeture' => '19:30:00', 'fermeture_samedi' => '15:00:00', 'samedi_ferme' => false, 'dimanche_ferme' => true, 'pause_active' => true, 'pause_debut' => '12:30:00', 'pause_fin' => '14:00:00'],
            ['clinique_id' => $sansPause->id, 'ouverture' => '07:30:00', 'fermeture' => '18:00:00', 'fermeture_samedi' => null, 'samedi_ferme' => true, 'dimanche_ferme' => false, 'pause_active' => false, 'pause_debut' => '12:00:00', 'pause_fin' => '13:00:00'],
            ['clinique_id' => $samediCourt->id, 'ouverture' => '08:00:00', 'fermeture' => '18:00:00', 'fermeture_samedi' => '12:00:00', 'samedi_ferme' => false, 'dimanche_ferme' => true, 'pause_active' => true, 'pause_debut' => '12:00:00', 'pause_fin' => '13:30:00'],
        ]);

        Artisan::call('migrate', ['--path' => $ajout, '--force' => true]);
        Artisan::call('migrate', ['--path' => $retrait, '--force' => true]);

        $jours = fn (Clinique $c): array => HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $c->id)->sole()->days;
        $plages = fn (string ...$h): array => array_map(fn (array $p): array => ['start' => $p[0], 'end' => $p[1]], array_chunk($h, 2));

        // Du lundi au vendredi : l'ouverture et la fermeture, coupées par la pause
        $this->assertSame(['is_open' => true, 'ranges' => $plages('08:00', '12:30', '14:00', '19:30')], $jours($avec)[0]);
        $this->assertSame(['is_open' => true, 'ranges' => $plages('08:00', '12:30', '14:00', '19:30')], $jours($avec)[4]);
        // Le samedi ferme plus tôt, la pause le coupe aussi ; le dimanche est fermé
        $this->assertSame(['is_open' => true, 'ranges' => $plages('08:00', '12:30', '14:00', '15:00')], $jours($avec)[5]);
        $this->assertFalse($jours($avec)[6]['is_open']);
        // Sans pause : une plage ; samedi fermé, dimanche ouvert
        $this->assertSame(['is_open' => true, 'ranges' => $plages('07:30', '18:00')], $jours($sansPause)[2]);
        $this->assertFalse($jours($sansPause)[5]['is_open']);
        $this->assertSame(['is_open' => true, 'ranges' => $plages('07:30', '18:00')], $jours($sansPause)[6]);
        // Un samedi qui ferme avant la fin de la pause garde une seule plage, comme le planning l'affichait
        $this->assertSame(['is_open' => true, 'ranges' => $plages('08:00', '12:00')], $jours($samediCourt)[5]);
    }

    // ————— Les lecteurs des horaires —————

    public function test_le_planning_ne_propose_aucun_creneau_dans_la_coupure_de_midi(): void
    {
        $reponse = $this->getJson('/api/v1/planning/disponibilites?date='.$this->lundi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.ouverture', '08:00')
            ->assertJsonPath('data.fermeture', '18:00')
            ->assertJsonPath('data.pause.debut', '12:00')
            ->assertJsonPath('data.pause.fin', '13:30');

        $creneaux = array_map(fn (array $c): array => [$c['debut'], $c['fin']], $reponse->json('data.creneaux'));
        $this->assertSame([
            [$this->local($this->lundi, '08:00')->utc()->toISOString(), $this->local($this->lundi, '12:00')->utc()->toISOString()],
            [$this->local($this->lundi, '13:30')->utc()->toISOString(), $this->local($this->lundi, '18:00')->utc()->toISOString()],
        ], $creneaux);
    }

    public function test_chaque_jour_suit_ses_propres_plages(): void
    {
        $mercredi = $this->lundi->addDays(2);
        $jeudi = $this->lundi->addDays(3);
        $samedi = $this->lundi->addDays(5);

        $this->getJson('/api/v1/planning/disponibilites?date='.$mercredi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermeture', '12:00')
            ->assertJsonPath('data.pause', null)
            ->assertJsonCount(1, 'data.creneaux');
        $this->getJson('/api/v1/planning/disponibilites?date='.$jeudi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.pause', null)
            ->assertJsonPath('data.fermeture', '18:00');
        $this->getJson('/api/v1/planning/disponibilites?date='.$samedi->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.fermee', true)
            ->assertJsonPath('data.creneaux', []);
    }

    public function test_le_calendrier_des_clients_suit_la_grille_de_chaque_plage(): void
    {
        $this->travelTo($this->local($this->lundi, '07:00'));

        $jours = $this->getJson("/api/v1/public/cliniques/{$this->clinique->id}/disponibilites")->assertOk()->json('data.jours');
        $heures = array_map(fn (array $h): string => CarbonImmutable::parse($h['debut'])->setTimezone('Europe/Zurich')->format('H:i'), $jours[0]['heures']);

        $this->assertContains('11:40', $heures);
        $this->assertContains('13:30', $heures);
        $this->assertContains('13:50', $heures);
        $this->assertSame([], array_values(array_filter($heures, fn (string $h): bool => $h >= '12:00' && $h < '13:30')));
        $this->assertSame('17:30', end($heures));
        // Mercredi : le matin seulement ; samedi : fermé
        $this->assertSame('11:40', CarbonImmutable::parse(end($jours[2]['heures'])['debut'])->setTimezone('Europe/Zurich')->format('H:i'));
        $this->assertTrue($jours[5]['ferme']);
    }

    public function test_une_demande_en_ligne_dans_la_coupure_est_refusee_et_l_apres_midi_acceptee(): void
    {
        $this->travelTo($this->local($this->lundi, '07:00'));
        $demande = fn (string $heure): array => [
            'clinique_id' => $this->clinique->id,
            'debut_souhaite' => $this->local($this->lundi, $heure)->toIso8601String(),
            'mode' => 'place',
            'patient_name' => 'Max',
            'espece' => 'CH',
            'motif' => 'Consultation',
        ];

        ['jeton' => $proprietaire] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/espace/demandes', $demande('12:20'), $this->enTetes($proprietaire))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('debut_souhaite');
        $this->postJson('/api/v1/espace/demandes', $demande('13:30'), $this->enTetes($proprietaire))->assertCreated();
    }

    public function test_la_clinique_ne_propose_pas_d_horaire_dans_la_coupure(): void
    {
        $this->travelTo($this->local($this->lundi, '07:00'));
        $demande = DemandeRendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'email' => 'pineau@exemple.ch',
            'debut_souhaite' => $this->local($this->lundi, '10:00')->utc(),
            'duree_minutes' => 20,
            'jeton' => Str::random(48),
        ]);

        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", [
            'creneaux' => [$this->local($this->lundi, '12:30')->toIso8601String()],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('creneaux.0');

        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", [
            'creneaux' => [$this->local($this->lundi, '13:30')->toIso8601String()],
        ], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_une_urgence_au_plus_tot_attend_la_fin_de_la_coupure(): void
    {
        $this->travelTo($this->local($this->lundi, '12:05'));
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $this->clinique->id, 'duree_minutes' => 20]);

        $debut = $this->postJson("/api/v1/urgences/{$urgence->id}/placer", [], $this->enTetes($jeton))
            ->assertCreated()
            ->json('data.starts_at');

        $this->assertSame('13:30', CarbonImmutable::parse($debut)->setTimezone('Europe/Zurich')->format('H:i'));
    }

    public function test_l_annuaire_propose_ses_creneaux_hors_coupure_et_montre_ses_horaires(): void
    {
        $this->travelTo($this->local($this->lundi, '11:45'));

        $fiche = $this->getJson('/api/v1/public/cliniques')->assertOk()->json('data.0');

        // 11:45 : plus rien ce matin (12:00 ferme), le premier créneau est 13:30
        $this->assertSame($this->lundi->toDateString().' 13:30', CarbonImmutable::parse($fiche['creneaux'][0]['debut'])->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
        foreach ($fiche['creneaux'] as $c) {
            $heure = CarbonImmutable::parse($c['debut'])->setTimezone('Europe/Zurich')->format('H:i');
            $this->assertFalse($heure >= '12:00' && $heure < '13:30', $heure);
        }
        // Ouvert maintenant, jusqu'à midi ; les plages de chaque jour
        $this->assertTrue($fiche['horaires']['est_ouvert']);
        $this->assertSame('Ouvert · jusqu’à 12:00', $fiche['horaires']['statut']);
        $this->assertSame(['08:00 à 12:00', '13:30 à 18:00'], $fiche['horaires']['jours'][0]['plages']);
        $this->assertSame([], $fiche['horaires']['jours'][5]['plages']);
    }

    public function test_le_site_montre_les_plages_de_chaque_jour_et_ferme_pendant_la_coupure(): void
    {
        $this->travelTo($this->local($this->lundi, '12:15'));

        $horaires = app(HorairesSiteWeb::class)->pour($this->clinique);

        $this->assertFalse($horaires['est_ouvert']);
        $this->assertSame('Fermé · ouvre à 13:30', $horaires['statut']);
        $this->assertSame(['08:00 à 12:00'], $horaires['jours'][2]['plages']);
        $this->assertSame(['08:00 à 18:00'], $horaires['jours'][3]['plages']);
        $this->assertSame([], $horaires['jours'][6]['plages']);

        // Mercredi après-midi, fermé : le site annonce le jeudi
        $this->travelTo($this->local($this->lundi->addDays(2), '14:00'));
        $this->assertSame('Fermé · ouvre demain à 08:00', app(HorairesSiteWeb::class)->pour($this->clinique)['statut']);
    }

    // ————— Création d'une clinique —————

    public function test_une_clinique_creee_reprend_les_horaires_d_une_autre_et_garde_son_email(): void
    {
        $etrangere = Clinique::factory()->create();
        $id = $this->postJson('/api/v1/organisation/cliniques', [
            'societe_id' => $this->clinique->societe_id,
            'nom' => 'Clinique du Lac',
            'raison_sociale' => 'Clinique Vétérinaire du Lac',
            'rue' => 'Quai 1',
            'npa' => '1006',
            'ville' => 'Lausanne',
            'tel' => '+41 21 000 00 00',
            'email' => 'lac@exemple.ch',
            'hours_from' => $this->clinique->id,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.email', 'lac@exemple.ch')
            ->json('data.id');

        $this->getJson("/api/v1/organisation/cliniques/{$id}/horaires", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.days.2.ranges', [['start' => '08:00', 'end' => '12:00']])
            ->assertJsonPath('data.days.5.is_open', false);

        // Une clinique d'une autre organisation ne se recopie pas
        $this->postJson('/api/v1/organisation/cliniques', [
            'societe_id' => $this->clinique->societe_id,
            'nom' => 'Clinique du Port',
            'raison_sociale' => 'Clinique du Port',
            'rue' => 'Quai 2',
            'npa' => '1006',
            'ville' => 'Lausanne',
            'hours_from' => $etrangere->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hours_from');
    }
}
