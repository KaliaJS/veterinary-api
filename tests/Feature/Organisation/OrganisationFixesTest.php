<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Enums\StatutRendezVous;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\Sejour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Corrections de l'écran Organisation après la vérification indépendante
 * (04.11.2026) : services modifiés un champ à la fois, boxes gardés quand
 * l'hospitalisation se coupe, localité d'un NPA pour qui gère
 * l'organisation, rendez-vous à venir hors des horaires signalés, reprise
 * des anciens horaires robuste.
 */
class OrganisationFixesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->sansServices()->create(['nombre_boxes' => 8, 'fuseau_horaire' => 'Europe/Zurich']);
        $this->clinique->services()->create(['service' => 'hospitalisation']);
        $this->clinique->services()->create(['service' => 'urgences', 'modalite' => 'permanence']);
        $this->clinique->especes()->create(['espece' => 'CH']);
        ['employe' => $administrateur, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $administrateur->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    private function services(array $donnees)
    {
        return $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $donnees, $this->enTetes($this->jeton));
    }

    public function test_un_seul_champ_change_sans_toucher_au_reste(): void
    {
        $this->services(['rdv_sans_compte' => true])
            ->assertOk()
            ->assertJsonPath('data.rdv_sans_compte', true)
            ->assertJsonPath('data.services', [['service' => 'hospitalisation', 'modalite' => null], ['service' => 'urgences', 'modalite' => 'permanence']])
            ->assertJsonPath('data.especes', ['CH'])
            ->assertJsonPath('data.nombre_boxes', 8);

        $this->services(['especes' => ['CH', 'CT']])
            ->assertOk()
            ->assertJsonPath('data.especes', ['CH', 'CT'])
            ->assertJsonPath('data.rdv_sans_compte', true)
            ->assertJsonPath('data.services.0.service', 'hospitalisation');

        $this->services(['affiche_veterinaires' => false])
            ->assertOk()
            ->assertJsonPath('data.affiche_veterinaires', false)
            ->assertJsonPath('data.especes', ['CH', 'CT']);
    }

    public function test_couper_l_hospitalisation_garde_le_nombre_de_boxes_pour_la_rallumer(): void
    {
        $this->services(['services' => [['service' => 'urgences', 'modalite' => 'permanence']]])
            ->assertOk()
            ->assertJsonPath('data.nombre_boxes', 8);

        $this->services(['services' => [['service' => 'hospitalisation'], ['service' => 'urgences', 'modalite' => 'permanence']]])
            ->assertOk()
            ->assertJsonPath('data.services.0.service', 'hospitalisation')
            ->assertJsonPath('data.nombre_boxes', 8);
    }

    public function test_la_localite_d_un_npa_se_cherche_avec_le_droit_organisation_sans_patients(): void
    {
        $gerance = Role::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Gérance',
            'domaines' => [DomainePermission::Organisation],
        ]);
        ['employe' => $gerant, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $gerant->update(['role_id' => $gerance->id]);

        $this->getJson('/api/v1/localites?npa=1006', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.0.localite', 'Lausanne');
    }

    public function test_les_rendez_vous_a_venir_hors_des_horaires_sont_signales(): void
    {
        $lundi = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::MONDAY);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rdv = fn (string $jour, string $heure, int $minutes, array $attributs = []) => RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $ligne->id,
            'starts_at' => CarbonImmutable::parse($jour.' '.$heure, 'Europe/Zurich')->utc(),
            'ends_at' => CarbonImmutable::parse($jour.' '.$heure, 'Europe/Zurich')->addMinutes($minutes)->utc(),
            'patient_name' => 'Filou',
            ...$attributs,
        ]);
        $dansLaCoupure = $rdv($lundi->toDateString(), '12:30', 20);
        $rdv($lundi->toDateString(), '10:00', 20); // dans le matin
        $samedi = $rdv($lundi->addDays(5)->toDateString(), '09:00', 20); // samedi, bientôt fermé
        $rdv($lundi->toDateString(), '12:40', 20, ['status' => StatutRendezVous::Annule]); // annulé : ignoré
        $rdv($lundi->subWeeks(2)->toDateString(), '12:30', 20); // passé : ignoré

        $jours = HoraireOuverture::weekLike(['ouverture' => '08:00', 'fermeture' => '18:00', 'pause_debut' => '12:00', 'pause_fin' => '13:30', 'samedi_ferme' => true]);

        $reponse = $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", ['days' => $jours], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data.appointments_outside_hours');

        $this->assertSame([$dansLaCoupure->id, $samedi->id], array_column($reponse->json('data.appointments_outside_hours'), 'id'));
        $this->assertSame('Filou', $reponse->json('data.appointments_outside_hours.0.patient_name'));

        // La lecture le dit aussi, tant qu'ils restent hors des horaires
        $this->getJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", $this->enTetes($this->jeton))
            ->assertJsonCount(2, 'data.appointments_outside_hours');
    }

    public function test_la_reprise_des_anciens_horaires_ignore_une_pause_absurde_et_les_plages_trop_courtes(): void
    {
        $migration = require database_path('migrations/2026_11_04_000100_opening_hours_per_day.php');
        $ligne = fn (array $a) => (object) [
            'ouverture' => '08:00:00', 'fermeture' => '18:00:00', 'fermeture_samedi' => null, 'samedi_ferme' => false,
            'dimanche_ferme' => true, 'pause_active' => true, 'pause_debut' => '12:00:00', 'pause_fin' => '13:30:00', ...$a,
        ];
        $plage = fn (string $debut, string $fin): array => ['start' => $debut, 'end' => $fin];

        // Pause inversée : ignorée, une seule plage
        $this->assertSame([$plage('08:00', '18:00')], $migration->days($ligne(['pause_debut' => '14:00:00', 'pause_fin' => '12:00:00']))[0]['ranges']);
        // Pause qui commence avant l'ouverture : ignorée
        $this->assertSame([$plage('08:00', '18:00')], $migration->days($ligne(['pause_debut' => '07:00:00', 'pause_fin' => '09:00:00']))[0]['ranges']);
        // Une pause qui laisse un matin de 15 minutes : seul l'après-midi reste
        $this->assertSame([$plage('09:30', '18:00')], $migration->days($ligne(['pause_debut' => '08:15:00', 'pause_fin' => '09:30:00']))[0]['ranges']);
        // Fermeture avant l'ouverture : jour fermé, sans plage, il se rouvrira sans refus
        $ferme = $migration->days($ligne(['fermeture' => '07:00:00', 'pause_active' => false]))[0];
        $this->assertSame(['is_open' => false, 'ranges' => []], $ferme);
        // Le cas ordinaire ne change pas
        $this->assertSame([$plage('08:00', '12:00'), $plage('13:30', '18:00')], $migration->days($ligne([]))[0]['ranges']);
    }

    // ————— Deuxième passe (04.11.2026) —————

    public function test_deux_onglets_n_eteignent_pas_un_service_ou_un_animal_allume_ailleurs(): void
    {
        // Onglet A allume l'imagerie ; onglet B, resté sur l'ancienne liste, allume le laboratoire
        $this->services(['service' => 'imagerie', 'service_enabled' => true])->assertOk();
        $this->services(['service' => 'laboratoire', 'service_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.services', [
                ['service' => 'hospitalisation', 'modalite' => null],
                ['service' => 'imagerie', 'modalite' => null],
                ['service' => 'laboratoire', 'modalite' => null],
                ['service' => 'urgences', 'modalite' => 'permanence'],
            ]);

        // Même chose pour les animaux soignés
        $this->services(['espece' => 'CT', 'espece_enabled' => true])->assertOk();
        $this->services(['espece' => 'LP', 'espece_enabled' => true])->assertOk()->assertJsonPath('data.especes', ['CH', 'CT', 'LP']);
        $this->services(['espece' => 'CH', 'espece_enabled' => false])->assertOk()->assertJsonPath('data.especes', ['CT', 'LP']);

        // Un seul élément retiré, la modalité des urgences changée sans toucher au reste
        $this->services(['service' => 'imagerie', 'service_enabled' => false])->assertOk()->assertJsonCount(3, 'data.services');
        $this->services(['service' => 'urgences', 'service_enabled' => true, 'modalite' => 'horaires'])
            ->assertOk()
            ->assertJsonPath('data.services.2', ['service' => 'urgences', 'modalite' => 'horaires']);
        // Allumer les urgences demande leur modalité
        $this->services(['service' => 'urgences', 'service_enabled' => false])->assertOk();
        $this->services(['service' => 'urgences', 'service_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('modalite');
    }

    public function test_la_localite_est_refusee_sans_le_droit_patients_ni_organisation(): void
    {
        $planning = Role::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'nom' => 'Accueil planning',
            'domaines' => [DomainePermission::Planning],
        ]);
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => $planning->id]);

        $this->getJson('/api/v1/localites?npa=1006', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_les_rendez_vous_hors_horaires_restent_ceux_de_la_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'fuseau_horaire' => 'Europe/Zurich']);
        $lundi = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::MONDAY);
        $dimanche = CarbonImmutable::parse($lundi->addDays(6)->toDateString().' 10:00', 'Europe/Zurich');
        foreach ([$this->clinique, $soeur] as $c) {
            RendezVous::factory()->create([
                'clinique_id' => $c->id,
                'ligne_planning_id' => LignePlanning::factory()->create(['clinique_id' => $c->id])->id,
                'starts_at' => $dimanche->utc(),
                'ends_at' => $dimanche->addMinutes(20)->utc(),
                'patient_name' => 'Chez '.$c->id,
            ]);
        }

        $liste = $this->getJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data.appointments_outside_hours');

        $this->assertSame(['Chez '.$this->clinique->id], array_column($liste, 'patient_name'));
    }

    public function test_les_rendez_vous_hors_horaires_se_lisent_dans_le_fuseau_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'America/New_York']);
        $mardi = CarbonImmutable::now('America/New_York')->next(CarbonImmutable::TUESDAY);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $rdv = function (string $heure, string $nom) use ($mardi, $ligne): void {
            $debut = CarbonImmutable::parse($mardi->toDateString().' '.$heure, 'America/New_York');
            RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'ligne_planning_id' => $ligne->id, 'starts_at' => $debut->utc(), 'ends_at' => $debut->addMinutes(20)->utc(), 'patient_name' => $nom]);
        };
        $rdv('03:00', 'Nuit à New York'); // 09:00 à Zurich : dans les heures de Zurich, pas de New York
        $rdv('14:00', 'Après-midi à New York'); // 20:00 à Zurich : hors des heures de Zurich, dans celles de New York

        $jours = HoraireOuverture::weekLike(['ouverture' => '08:00', 'fermeture' => '18:00', 'pause_debut' => '12:00', 'pause_fin' => '13:30']);
        $liste = $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", ['days' => $jours], $this->enTetes($this->jeton))
            ->assertOk()
            ->json('data.appointments_outside_hours');

        $this->assertSame(['Nuit à New York'], array_column($liste, 'patient_name'));
    }

    // ————— Troisième passe (04.11.2026) —————

    public function test_cocher_un_animal_ne_reecrit_pas_le_nombre_de_boxes(): void
    {
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->services(['espece' => 'CT', 'espece_enabled' => true])->assertOk();
        $this->services(['rdv_sans_compte' => true])->assertOk();

        $this->assertSame([], array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, 'nombre_boxes') && str_starts_with(strtolower(ltrim($sql)), 'update'))));
    }

    public function test_une_clinique_d_une_autre_organisation_repond_404_avant_toute_validation(): void
    {
        $etrangere = Clinique::factory()->sansServices()->create(['nombre_boxes' => 0]);

        $this->putJson("/api/v1/organisation/cliniques/{$etrangere->id}/services", ['service' => 'hospitalisation', 'service_enabled' => true], $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_un_element_mal_forme_est_refuse(): void
    {
        $this->services(['service' => 'astrologie', 'service_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->services(['espece' => 'XX', 'espece_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('espece');
        $this->services(['service' => 'imagerie'])->assertUnprocessable()->assertJsonValidationErrors('service_enabled');
        $this->services(['espece' => 'CT'])->assertUnprocessable()->assertJsonValidationErrors('espece_enabled');
    }

    public function test_allumer_un_service_deja_allume_ne_fait_pas_d_erreur(): void
    {
        // Deux allumages simultanés : le second trouve la ligne déjà là (index unique) ;
        // la course elle-même ne se rejoue pas sous SQLite, son issue oui
        $this->services(['service' => 'urgences', 'service_enabled' => true, 'modalite' => 'horaires'])
            ->assertOk()
            ->assertJsonPath('data.services.1', ['service' => 'urgences', 'modalite' => 'horaires']);
        $this->services(['espece' => 'CH', 'espece_enabled' => true])->assertOk()->assertJsonPath('data.especes', ['CH']);
    }

    // ————— Quatrième passe (04.11.2026) —————

    public function test_les_boxes_ne_se_changent_pas_par_la_fiche_de_la_clinique(): void
    {
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'box' => 5]);

        $this->patchJson("/api/v1/organisation/cliniques/{$this->clinique->id}", ['nombre_boxes' => 2], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre_boxes');

        $this->assertSame(8, Clinique::withoutGlobalScopes()->findOrFail($this->clinique->id)->nombre_boxes);
    }

    // ————— Sixième passe (04.11.2026) : l'ordre des envois d'un même onglet, sans horloge —————

    private function horairesA(string $reprise): array
    {
        return HoraireOuverture::weekLike(['pause_debut' => '12:00', 'pause_fin' => $reprise]);
    }

    private function envoyer(string $reprise, ?string $onglet = null, ?int $seq = null, ?string $url = null)
    {
        return $this->putJson($url ?? "/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", [
            'days' => $this->horairesA($reprise),
            ...($onglet !== null ? ['writer' => $onglet, 'seq' => $seq] : []),
        ], $this->enTetes($this->jeton));
    }

    private function repriseEnBase(?int $clinique = null): string
    {
        return HoraireOuverture::withoutGlobalScopes()->where('clinique_id', $clinique ?? $this->clinique->id)->sole()->days[0]['ranges'][1]['start'];
    }

    public function test_meme_onglet_dans_le_desordre_le_plus_ancien_est_ignore(): void
    {
        $onglet = (string) Str::uuid();

        $this->envoyer('14:00', $onglet, 2)->assertOk()->assertJsonPath('data.applied', true);
        $this->envoyer('13:00', $onglet, 1)
            ->assertOk()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.days.0.ranges.1.start', '14:00');

        $this->assertSame('14:00', $this->repriseEnBase());
    }

    public function test_deux_onglets_la_derniere_ecriture_gagne(): void
    {
        $this->envoyer('14:00', (string) Str::uuid(), 5)->assertOk();
        $this->envoyer('13:00', (string) Str::uuid(), 1)->assertOk()->assertJsonPath('data.applied', true);

        $this->assertSame('13:00', $this->repriseEnBase());
    }

    public function test_un_grand_numero_ne_bloque_que_son_onglet(): void
    {
        $this->envoyer('14:00', (string) Str::uuid(), 1_000_000_000)->assertOk();
        $this->envoyer('13:00', (string) Str::uuid(), 1)->assertOk()->assertJsonPath('data.applied', true);
        $this->assertSame('13:00', $this->repriseEnBase());

        // Au-delà d'un milliard, ou un identifiant qui n'est pas un uuid : refusé
        $this->envoyer('13:00', (string) Str::uuid(), 1_000_000_001)->assertUnprocessable()->assertJsonValidationErrors('seq');
        $this->envoyer('13:00', 'pas-un-uuid', 1)->assertUnprocessable()->assertJsonValidationErrors('writer');
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/horaires", ['days' => $this->horairesA('13:00'), 'writer' => (string) Str::uuid()], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('seq');
    }

    public function test_les_reglages_du_meme_onglet_passent_avant_un_vieux_keepalive_de_la_fiche(): void
    {
        $onglet = (string) Str::uuid();
        // Réglages, Horaires, dans le même onglet : son compteur continue
        $this->envoyer('14:00', $onglet, 3, '/api/v1/horaires')->assertOk()->assertJsonPath('data.applied', true);
        // Le keepalive de la fiche (numéro 2), parti avant, arrive après : ignoré
        $this->envoyer('15:00', $onglet, 2)->assertOk()->assertJsonPath('data.applied', false);
        $this->assertSame('14:00', $this->repriseEnBase());

        // Un envoi sans onglet (ancien client) s'écrit et remet l'ordre à zéro
        $this->envoyer('13:00', url: '/api/v1/horaires')->assertOk()->assertJsonPath('data.applied', true);
        $this->envoyer('15:00', $onglet, 1)->assertOk()->assertJsonPath('data.applied', true);
        $this->assertSame('15:00', $this->repriseEnBase());
    }

    public function test_une_clinique_creee_prend_le_premier_envoi_de_l_onglet(): void
    {
        $id = $this->postJson('/api/v1/organisation/cliniques', [
            'societe_id' => $this->clinique->societe_id, 'nom' => 'Clinique du Port', 'raison_sociale' => 'Clinique du Port',
            'rue' => 'Quai 2', 'npa' => '1006', 'ville' => 'Lausanne', 'hours_from' => $this->clinique->id,
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/organisation/cliniques/{$id}/horaires", ['days' => $this->horairesA('13:45'), 'writer' => (string) Str::uuid(), 'seq' => 1], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.applied', true);
        $this->assertSame('13:45', $this->repriseEnBase($id));
    }

    // ————— Septième passe (04.11.2026) —————

    public function test_un_meme_onglet_sur_deux_cliniques_n_interfere_pas(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $onglet = (string) Str::uuid();

        $this->envoyer('14:00', $onglet, 5)->assertOk()->assertJsonPath('data.applied', true);
        $this->putJson("/api/v1/organisation/cliniques/{$soeur->id}/horaires", ['days' => $this->horairesA('13:15'), 'writer' => $onglet, 'seq' => 1], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.applied', true);

        $this->assertSame('14:00', $this->repriseEnBase());
        $this->assertSame('13:15', $this->repriseEnBase($soeur->id));
    }
}
