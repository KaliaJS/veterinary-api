<?php

namespace Tests\Feature\Espace;

use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — rendez-vous : liste à venir / passés, annulation
 * auditée, demande de report ; annuaire des cliniques avec créneaux et
 * dépôt d'une demande de rendez-vous.
 */
class RendezVousProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_liste_separe_a_venir_et_passes(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);

        $demain = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20), 'reason' => 'Contrôle otite']);
        $passe = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20), 'status' => StatutRendezVous::Fini]);
        RendezVous::factory()->pourPatient($max)->annule()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(20)]);
        RendezVous::factory()->create(['starts_at' => now()->addDay()]); // un autre client

        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.a_venir')
            ->assertJsonPath('data.a_venir.0.id', $demain->id)
            ->assertJsonPath('data.a_venir.0.motif', 'Contrôle otite')
            ->assertJsonPath('data.a_venir.0.patient.nom', 'Max')
            ->assertJsonPath('data.a_venir.0.peut_annuler', true)
            ->assertJsonMissingPath('data.a_venir.0.no_shows_client')
            ->assertJsonCount(2, 'data.passes')
            ->assertJsonPath('data.passes.1.id', $passe->id)
            ->assertJsonPath('data.passes.1.peut_annuler', false);
    }

    public function test_le_proprietaire_annule_son_rendez_vous_a_venir_et_le_geste_est_audite(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        $rdv = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20)]);

        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'annule')
            ->assertJsonPath('data.peut_annuler', false);

        $this->assertDatabaseHas('rendez_vous_audits', ['rendez_vous_id' => $rdv->id, 'user_id' => $user->id, 'champ' => 'status']);
        $this->assertDatabaseHas('rendez_vous_audits', ['rendez_vous_id' => $rdv->id, 'champ' => 'motifAnnulation']);

        // Déjà annulé : refusé. Un rendez-vous d'un autre compte : introuvable.
        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Ce rendez-vous ne peut plus être annulé en ligne : contactez la clinique.']);
        $autre = RendezVous::factory()->create(['starts_at' => now()->addDay()]);
        $this->postJson("/api/v1/espace/rendez-vous/{$autre->id}/annuler", [], $this->enTetes($jeton))->assertNotFound();
    }

    public function test_une_demande_de_report_rejoint_la_file_de_la_clinique(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $rdv = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30), 'reason' => 'Vaccin']);

        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/reporter", [], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.clinique.id', $rdv->clinique_id);

        $this->assertDatabaseHas('demandes_rendez_vous', [
            'clinique_id' => $rdv->clinique_id,
            'patient_id' => $max->id,
            'client_id' => $fiche->id,
            'reason' => 'Report : Vaccin',
            'mode' => 'valider',
            'duree_minutes' => 30,
        ]);

        // Un seul report à la fois pour ce rendez-vous : un script ne remplit pas la file
        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/reporter", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    public function test_l_annuaire_des_cliniques_porte_leurs_prochains_creneaux(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['nom' => 'Deux Rives', 'ville' => 'Lausanne']);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        Clinique::factory()->dansLAnnuaire()->create(['nom' => 'Sans ligne']);

        $reponse = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nom', 'Deux Rives')
            ->assertJsonPath('data.0.ville', 'Lausanne')
            ->assertJsonStructure(['data' => [['id', 'nom', 'ville', 'latitude', 'longitude', 'tel', 'organisation' => ['id', 'nom'], 'creneaux']]])
            ->assertJsonPath('data.1.creneaux', []);

        $creneaux = $reponse->json('data.0.creneaux');
        $this->assertCount(3, $creneaux);
        $this->assertTrue(strtotime($creneaux[0]['debut']) > now()->getTimestamp());
        $this->assertTrue(strtotime($creneaux[0]['debut']) < strtotime($creneaux[1]['debut']));
        $this->assertTrue(strtotime($creneaux[1]['debut']) < strtotime($creneaux[2]['debut']));
        // Au quart d'heure, même aujourd'hui où le trou commence à l'heure qu'il est.
        foreach ($creneaux as $c) {
            $this->assertSame(0, strtotime($c['debut']) % 900);
        }
        // Étalés sur les jours : les trois créneaux tombent sur des dates différentes.
        $this->assertCount(3, array_unique(array_map(fn (array $c): string => substr($c['debut'], 0, 10), $creneaux)));
    }

    public function test_apres_le_changement_d_heure_le_premier_creneau_reste_a_8h00_a_zurich(): void
    {
        // Samedi 24 octobre 2026 à 16 h : fermé, dimanche aussi ; la nuit
        // de dimanche, Zurich repasse à l'heure d'hiver (UTC+1)
        $this->travelTo(CarbonImmutable::parse('2026-10-24 16:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        $premier = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux.0.debut');

        // Lundi 26 à l'ouverture, 8 h à Zurich : 7 h en UTC, plus 6 h
        $this->assertSame('2026-10-26 08:00', CarbonImmutable::parse($premier)->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26T07:00:00+00:00', CarbonImmutable::parse($premier)->utc()->toIso8601String());
    }

    public function test_a_14h00_et_quelques_secondes_le_premier_creneau_est_14h15(): void
    {
        // Un mardi en heures d'ouverture, quelques secondes après l'heure ronde
        $this->travelTo(CarbonImmutable::parse('next tuesday 14:00:20', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        $premier = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux.0.debut');

        // 14:00 est passé de 20 secondes : le quart d'heure suivant, pas 14:20
        $this->assertSame('14:15', CarbonImmutable::parse($premier)->setTimezone('Europe/Zurich')->format('H:i'));
    }

    public function test_le_nombre_de_requetes_de_l_annuaire_ne_depend_pas_du_nombre_de_cliniques(): void
    {
        $peupler = function (int $nombre): void {
            Clinique::factory()->dansLAnnuaire()->count($nombre)->create()->each(function (Clinique $clinique): void {
                $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
                RendezVous::factory()->create([
                    'clinique_id' => $clinique->id,
                    'ligne_planning_id' => $ligne->id,
                    'starts_at' => now()->addDay()->setTime(9, 0),
                    'ends_at' => now()->addDay()->setTime(10, 0),
                ]);
            });
        };

        $requetes = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/public/cliniques')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $peupler(2);
        $avecDeux = $requetes();

        $peupler(10);
        $this->assertSame($avecDeux, $requetes());
    }

    public function test_une_demande_de_rendez_vous_est_deposee_pour_un_animal_du_compte_ou_un_nom_libre(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);
        // Elle valide chaque rendez-vous : la demande reste dans sa file
        $clinique = Clinique::factory()->create(['rdv_a_valider' => true]);
        $fiche = Client::factory()->create(['user_id' => $user->id, 'organisation_id' => $clinique->societe->organisation_id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        // Une heure de la grille du calendrier (20 minutes depuis 8 h), un jour ouvert
        $debut = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(14, 20);

        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => 'place',
            'patient_id' => $max->id,
            'motif' => 'Vaccination',
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.clinique.id', $clinique->id)
            ->assertJsonPath('data.patient_name', 'Max')
            ->assertJsonPath('data.mode', 'place');

        $this->assertDatabaseHas('demandes_rendez_vous', ['clinique_id' => $clinique->id, 'patient_id' => $max->id, 'client_id' => $fiche->id, 'reason' => 'Vaccination', 'mode' => 'valider']);

        // Nom libre, en visio, dans une clinique où le compte n'a pas de fiche.
        $autreClinique = Clinique::factory()->create(['rdv_a_valider' => true]);
        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $autreClinique->id,
            'debut_souhaite' => $debut->toIso8601String(),
            'mode' => 'visio',
            'patient_name' => 'Nala',
            'espece' => 'CH',
            'motif' => 'Consultation',
        ], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.patient_name', 'Nala');

        $this->assertDatabaseHas('demandes_rendez_vous', ['clinique_id' => $autreClinique->id, 'patient_id' => null, 'client_id' => null, 'patient_name' => 'Nala', 'owner_name' => 'Anna Meyer']);

        // Sans animal : refusé ; l'animal d'un autre compte : refusé.
        $this->postJson('/api/v1/espace/demandes', ['clinique_id' => $clinique->id, 'debut_souhaite' => $debut->toIso8601String(), 'mode' => 'place', 'motif' => 'Consultation'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('patient_name');
        $intrus = Patient::factory()->create(['client_id' => Client::factory()->create(['user_id' => User::factory()->create()->id])->id]);
        $this->postJson('/api/v1/espace/demandes', ['clinique_id' => $clinique->id, 'debut_souhaite' => $debut->toIso8601String(), 'mode' => 'place', 'patient_id' => $intrus->id, 'motif' => 'Consultation'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
    }

    public function test_une_urgence_dit_ce_que_voit_le_proprietaire(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);
        $clinique = Clinique::factory()->create(['rdv_a_valider' => true]);
        $demande = [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(9, 0)->toIso8601String(),
            'mode' => 'place',
            'patient_name' => 'Nala',
            'espece' => 'CH',
            'motif' => 'Urgence',
            'urgence' => true,
        ];

        // Une urgence sans symptômes : refusée
        $this->postJson('/api/v1/espace/demandes', $demande, $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('symptomes');

        $this->postJson('/api/v1/espace/demandes', [...$demande, 'symptomes' => 'Vomit depuis ce matin, ne boit plus'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.urgence', true);
        $this->assertDatabaseHas('demandes_rendez_vous', ['patient_name' => 'Nala', 'urgence' => true, 'symptomes' => 'Vomit depuis ce matin, ne boit plus']);

        // Pas urgent : les symptômes ne sont pas gardés
        $this->postJson('/api/v1/espace/demandes', [...$demande, 'patient_name' => 'Rex', 'motif' => 'Vaccination', 'urgence' => false, 'symptomes' => 'rien'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.urgence', false);
        $this->assertDatabaseHas('demandes_rendez_vous', ['patient_name' => 'Rex', 'urgence' => false, 'symptomes' => null]);
    }

    public function test_le_calendrier_donne_une_semaine_d_heures_libres_ou_prises(): void
    {
        $clinique = Clinique::factory()->create();
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        $lundi = CarbonImmutable::today('Europe/Zurich')->startOfWeek()->addWeek();
        // Un rendez-vous le mardi de 10:00 à 10:40 : 10:00 et 10:20 sont prises
        RendezVous::factory()->create([
            'clinique_id' => $clinique->id,
            'ligne_planning_id' => $ligne->id,
            'starts_at' => $lundi->addDay()->setTime(10, 0)->utc(),
            'ends_at' => $lundi->addDay()->setTime(10, 40)->utc(),
        ]);

        $semaine = $this->getJson("/api/v1/public/cliniques/{$clinique->id}/disponibilites?semaine={$lundi->addDays(2)->toDateString()}")
            ->assertOk()
            ->assertJsonPath('data.semaine', $lundi->toDateString())
            ->assertJsonPath('data.precedente', $lundi->subWeek()->toDateString())
            ->assertJsonPath('data.duree_minutes', 20)
            ->assertJsonCount(7, 'data.jours')
            ->json('data');

        // Horaires par défaut : 08:00 à 19:30, pause de 12:30 à 14:00, dimanche fermé
        $mardi = collect($semaine['jours'][1]['heures'])->keyBy(fn (array $h): string => CarbonImmutable::parse($h['debut'])->setTimezone('Europe/Zurich')->format('H:i'));
        $this->assertSame('08:00', $mardi->keys()->first());
        $this->assertTrue($mardi['09:40']['libre']);
        $this->assertFalse($mardi['10:00']['libre']);
        $this->assertFalse($mardi['10:20']['libre']);
        $this->assertTrue($mardi['10:40']['libre']);
        $this->assertFalse($mardi->has('12:40'));
        $this->assertTrue($semaine['jours'][6]['ferme']);
        $this->assertSame([], $semaine['jours'][6]['heures']);

        // Cette semaine : les jours passés ne sont pas proposés
        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $courante = $this->getJson("/api/v1/public/cliniques/{$clinique->id}/disponibilites?semaine=2020-01-06")
            ->assertOk()->assertJsonPath('data.precedente', null)->json('data.jours');
        foreach ($courante as $jour) {
            $this->assertSame($jour['date'] >= $aujourdhui->toDateString(), $jour['visible']);
        }

        // Un mois au plus : la dernière semaine s'arrête au même jour du mois suivant
        $derniere = $this->getJson("/api/v1/public/cliniques/{$clinique->id}/disponibilites?semaine=2099-01-05")
            ->assertOk()->assertJsonPath('data.suivante', null)
            ->assertJsonPath('data.semaine', $aujourdhui->addMonth()->startOfWeek()->toDateString())
            ->json('data.jours');
        foreach ($derniere as $jour) {
            $this->assertSame($jour['date'] <= $aujourdhui->addMonth()->toDateString(), $jour['visible']);
        }
    }
}
