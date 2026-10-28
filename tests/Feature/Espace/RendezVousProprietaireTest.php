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
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — rendez-vous : liste à venir / passés, annulation
 * auditée, demande de report ; annuaire des cliniques avec créneaux et
 * dépôt d'une demande de rendez-vous.
 */
class RendezVousProprietaireTest extends TestCase
{
    use DeposeSansCompte;
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

        // La liste le dit : plus de bouton « Déplacer » tant que la clinique n'a pas répondu
        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.a_venir.0.peut_reporter', false)
            ->assertJsonPath('data.a_venir.0.report_demande', true);

        $this->assertSame($rdv->id, DemandeRendezVous::withoutGlobalScopes()->sole()->report_de_id);

        // Un autre animal à la même heure, dans la même clinique : son report reste possible (24.10.2026)
        $nala = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Nala']);
        $memeHeure = RendezVous::factory()->pourPatient($nala)->create(['clinique_id' => $rdv->clinique_id, 'starts_at' => $rdv->starts_at, 'ends_at' => $rdv->ends_at, 'reason' => 'Vaccin']);
        $liste = collect($this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))->json('data.a_venir'))->keyBy('id');
        $this->assertTrue($liste[$memeHeure->id]['peut_reporter']);
        $this->postJson("/api/v1/espace/rendez-vous/{$memeHeure->id}/reporter", [], $this->enTetes($jeton))->assertCreated();
        $memeHeure->delete();
        DemandeRendezVous::withoutGlobalScopes()->whereNull('report_de_id')->orWhere('report_de_id', '!=', $rdv->id)->delete();

        // Déplacé par la clinique sans répondre : le report en cours est toujours reconnu
        $rdv->update(['starts_at' => $rdv->starts_at->addHour(), 'ends_at' => $rdv->ends_at->addHour()]);
        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))->assertJsonPath('data.a_venir.0.report_demande', true);
        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/reporter", [], $this->enTetes($jeton))->assertUnprocessable();

        // La clinique a traité la demande : un nouveau report redevient possible
        DemandeRendezVous::withoutGlobalScopes()->update(['statut' => 'refusee']);
        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))
            ->assertJsonPath('data.a_venir.0.peut_reporter', true)
            ->assertJsonPath('data.a_venir.0.report_demande', false);
    }

    /** Un report compte dans le plafond des demandes ouvertes chez la clinique (24.10.2026). */
    public function test_un_report_compte_dans_le_plafond_des_demandes(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id]);
        $rdv = RendezVous::factory()->pourPatient($max)->create(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20)]);
        // Cinq demandes déjà ouvertes chez cette clinique
        DemandeRendezVous::factory()->count(5)->create(['clinique_id' => $rdv->clinique_id, 'user_id' => $user->id, 'statut' => 'a_traiter']);

        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/reporter", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->assertSame(5, DemandeRendezVous::withoutGlobalScopes()->count());
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
        // Sur la grille du calendrier (20 minutes depuis 8 h), même aujourd'hui où le trou commence à l'heure qu'il est.
        foreach ($creneaux as $c) {
            $local = CarbonImmutable::parse($c['debut'])->setTimezone('Europe/Zurich');
            $this->assertSame(0, ($local->hour * 60 + $local->minute - 8 * 60) % 20, $c['debut']);
        }
        // Étalés sur les jours : les trois créneaux tombent sur des dates différentes.
        $this->assertCount(3, array_unique(array_map(fn (array $c): string => substr($c['debut'], 0, 10), $creneaux)));
    }

    /** Un rendez-vous annulé libère son créneau aussi pour la réservation en ligne (RDV annulés dans la grille, 28.10.2026). */
    public function test_un_rendez_vous_annule_laisse_son_creneau_libre_en_ligne(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 07:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        // Toute la journée prise, puis annulée
        RendezVous::factory()->create(['clinique_id' => $clinique->id, 'ligne_planning_id' => $ligne->id, 'status' => StatutRendezVous::Annule,
            'starts_at' => CarbonImmutable::parse('2026-10-26 07:00', 'Europe/Zurich')->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-26 20:00', 'Europe/Zurich')->utc()]);

        $premier = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux.0.debut');

        $this->assertSame('2026-10-26 08:00', CarbonImmutable::parse($premier)->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
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

    /** Le dimanche même du passage à l'heure d'hiver, une clinique ouverte : la grille part de 8 h à Zurich, pas de 7 h (24.10.2026). */
    public function test_le_jour_du_changement_d_heure_la_grille_reste_a_l_heure_locale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 16:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id, 'dimanche_ferme' => false, 'samedi_ferme' => true]);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        $creneaux = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux');

        // Dimanche 25 octobre, 8 h à Zurich : 7 h en UTC (heure d'hiver depuis 3 h du matin)
        $this->assertSame('2026-10-25 08:00', CarbonImmutable::parse($creneaux[0]['debut'])->setTimezone('Europe/Zurich')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25T07:00:00+00:00', CarbonImmutable::parse($creneaux[0]['debut'])->utc()->toIso8601String());
    }

    public function test_a_14h00_et_quelques_secondes_le_premier_creneau_est_14h20(): void
    {
        // Un mardi en heures d'ouverture, quelques secondes après l'heure ronde
        $this->travelTo(CarbonImmutable::parse('next tuesday 14:00:20', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich']);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        $premier = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux.0.debut');

        // 14:00 est passé de 20 secondes : l'heure suivante de la grille
        $this->assertSame('14:20', CarbonImmutable::parse($premier)->setTimezone('Europe/Zurich')->format('H:i'));
    }

    /**
     * « Le plus vite possible » envoie le premier créneau de l'annuaire :
     * la demande l'accepte, avec ou sans compte, même quand la clinique
     * ouvre à une heure qui n'est pas ronde (24.10.2026).
     */
    public function test_chaque_creneau_de_l_annuaire_est_accepte_par_la_demande(): void
    {
        $this->travelTo(CarbonImmutable::parse('next tuesday 14:07:30', 'Europe/Zurich'));
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['fuseau_horaire' => 'Europe/Zurich', 'rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id, 'ouverture' => '08:10']);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $creneaux = $this->getJson('/api/v1/espace/cliniques', $this->enTetes($jeton))->assertOk()->json('data.0.creneaux');
        $this->assertCount(3, $creneaux);
        // 14:07:30 sur une grille qui part de 8:10 : 14:10
        $this->assertSame('14:10', CarbonImmutable::parse($creneaux[0]['debut'])->setTimezone('Europe/Zurich')->format('H:i'));

        foreach ($creneaux as $c) {
            ['jeton' => $jeton] = $this->proprietaireAvecJeton();
            $this->postJson('/api/v1/espace/demandes', [
                'clinique_id' => $clinique->id,
                'debut_souhaite' => $c['debut'],
                'mode' => 'place',
                'patient_name' => 'Max',
                'espece' => 'CH',
                'motif' => 'Consultation',
            ], $this->enTetes($jeton))->assertCreated();
        }

        // Sans compte, depuis l'annuaire public : accepté, et le code est consommé une fois
        $premier = $this->getJson('/api/v1/public/cliniques')->assertOk()->json('data.0.creneaux.0.debut');
        $this->assertSame($creneaux[0]['debut'], $premier);
        $this->postJson('/api/v1/public/demandes', [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => $premier,
            'mode' => 'place',
            'patient_name' => 'Pacha',
            'espece' => 'CT',
            'motif' => 'Consultation',
            'nom_proprietaire' => 'Léa Rochat',
            'email' => 'lea.rochat@example.ch',
            'telephone' => '+41 79 123 45 67',
            'code' => $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch'),
        ])->assertCreated();
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

    /** « Un mois » : le jour du mois suivant est proposé, le lendemain non (avant, +32 jours passaient la requête). */
    public function test_le_calendrier_s_arrete_au_meme_jour_du_mois_suivant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'rdv_a_valider' => true]);
        $demande = fn (string $jour) => $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => CarbonImmutable::parse($jour.' 10:00', 'Europe/Zurich')->toIso8601String(),
            'mode' => 'place',
            'patient_name' => 'Max',
            'espece' => 'CH',
            'motif' => 'Consultation',
        ], $this->enTetes($jeton));

        // Jeudi 3 décembre : le dernier jour du mois montré
        $demande('2026-12-03')->assertCreated();
        // Vendredi 4 décembre : au-delà, même à moins de 32 jours
        $demande('2026-12-04')->assertUnprocessable()->assertJsonValidationErrors(['debut_souhaite' => 'Choisissez une heure proposée par le calendrier.']);
    }

    /** En fin de mois, le mois s'arrête au dernier jour du mois suivant (le 31 octobre : le 30 novembre). */
    public function test_le_calendrier_ne_deborde_pas_en_fin_de_mois(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'Europe/Zurich'));
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'rdv_a_valider' => true]);
        $demande = fn (string $jour) => $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => CarbonImmutable::parse($jour.' 10:00', 'Europe/Zurich')->toIso8601String(),
            'mode' => 'place', 'patient_name' => 'Max', 'espece' => 'CH', 'motif' => 'Consultation',
        ], $this->enTetes($jeton));

        // Lundi 30 novembre : compris ; mardi 1er décembre : au-delà
        $demande('2026-11-30')->assertCreated();
        $demande('2026-12-01')->assertUnprocessable()->assertJsonValidationErrors('debut_souhaite');
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
