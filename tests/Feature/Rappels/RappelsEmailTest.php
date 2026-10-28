<?php

namespace Tests\Feature\Rappels;

use App\Enums\TypeRendezVous;
use App\Mail\RappelControle;
use App\Mail\RappelVaccin;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Rappels\RappelsProprietaires;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Rappels par e-mail (décision de Jim, 28.10.2026) : vaccins et rendez-vous
 * de contrôle, au délai réglé par la clinique, une seule fois, en journée,
 * jamais à qui les a refusés ; chaque envoi est gardé ; désabonnement par
 * un lien ; « Rappeler le propriétaire » envoie vraiment.
 */
class RappelsEmailTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch']);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        // Vu à Deux Rives : c'est elle qui rappelle
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
    }

    private function vaccin(string $echeance, string $nom = 'Rage'): Vaccination
    {
        return Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => $nom, 'injectee_le' => CarbonImmutable::parse($echeance)->subYear(), 'echeance_le' => $echeance]);
    }

    private function envoyer(): void
    {
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    private function reglages(array $reglages): void
    {
        $this->putJson('/api/v1/rappels/reglages', $reglages, $this->enTetes($this->jeton))->assertOk();
    }

    public function test_les_reglages_sont_enregistres_par_le_serveur_et_partages_par_l_equipe(): void
    {
        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data', ['email' => true, 'sms' => false, 'delai_jours' => 7, 'vaccins' => true, 'controles' => true]);

        $this->reglages(['email' => true, 'delai_jours' => 3, 'vaccins' => false, 'controles' => true]);

        ['jeton' => $collegue] = $this->employeAvecJeton($this->clinique);
        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($collegue))->assertOk()
            ->assertJsonPath('data.delai_jours', 3)
            ->assertJsonPath('data.vaccins', false);
        $this->putJson('/api/v1/rappels/reglages', ['delai_jours' => 5], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['delai_jours' => 'Choisissez 7 jours, 3 jours ou la veille.']);
    }

    public function test_un_rappel_de_vaccin_part_au_delai_regle_une_seule_fois(): void
    {
        $this->vaccin('2026-11-12');

        // J-10 : trop tôt
        $this->envoyer();
        Mail::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        $this->envoyer();
        $this->envoyer();

        Mail::assertSent(RappelVaccin::class, 1);
        Mail::assertSent(RappelVaccin::class, fn (RappelVaccin $mail) => $mail->hasTo('lea.martin@example.ch')
            && str_contains($mail->render(), 'Félix')
            && str_contains($mail->render(), 'Rage')
            && str_contains($mail->render(), '12.11.2026')
            && str_contains($mail->render(), 'Deux Rives'));
    }

    public function test_le_delai_regle_par_la_clinique_est_respecte(): void
    {
        $this->reglages(['delai_jours' => 1]);
        $this->vaccin('2026-11-06');

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_une_echeance_remplacee_par_un_rappel_fait_ne_part_pas(): void
    {
        $this->vaccin('2026-11-09');
        // Revacciné depuis : la nouvelle échéance est dans un an
        $this->vaccin('2027-10-30');

        $this->envoyer();

        Mail::assertNothingSent();
    }

    public function test_un_rappel_de_rendez_vous_de_controle_part_au_delai_regle_une_seule_fois(): void
    {
        $debut = CarbonImmutable::parse('2026-11-09 14:30', 'Europe/Zurich')->utc();
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20), 'note_accueil' => 'Client difficile']);
        // Une consultation ordinaire n'est pas un contrôle
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Consultation, 'starts_at' => $debut->addDay(), 'ends_at' => $debut->addDay()->addMinutes(20)]);

        $this->envoyer();
        $this->envoyer();

        Mail::assertSent(RappelControle::class, 1);
        Mail::assertSent(RappelControle::class, fn (RappelControle $mail) => $mail->hasTo('lea.martin@example.ch')
            && str_contains($mail->render(), '09.11.2026')
            && str_contains($mail->render(), '14:30')
            // La note de l'accueil reste interne
            && ! str_contains($mail->render(), 'Client difficile'));
    }

    public function test_un_controle_annule_ne_part_pas(): void
    {
        $debut = CarbonImmutable::parse('2026-11-09 14:30', 'Europe/Zurich')->utc();
        RendezVous::factory()->pourPatient($this->patient)->annule()->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);

        $this->envoyer();

        Mail::assertNothingSent();
    }

    /** @return array<string, array{0: callable(self): void}> */
    public static function casSansEnvoi(): array
    {
        return [
            'e-mail coupé par la clinique' => [fn (self $t) => $t->reglages(['email' => false])],
            'rappels de vaccins coupés par la clinique' => [fn (self $t) => $t->reglages(['vaccins' => false])],
            'rappels coupés pour l’animal' => [fn (self $t) => $t->patient->forceFill(['rappels_coupes' => true])->save()],
            'animal décédé' => [fn (self $t) => $t->patient->forceFill(['decede_le' => '2026-10-01'])->save()],
            'propriétaire désabonné' => [fn (self $t) => $t->client->forceFill(['rappels_email' => false])->save()],
            'propriétaire qui a refusé dans son espace' => [function (self $t): void {
                $user = User::factory()->create(['email' => 'lea.martin@example.ch']);
                $t->client->forceFill(['user_id' => $user->id])->save();
                PreferencesProprietaire::query()->create(['user_id' => $user->id, 'rappels_email' => false]);
            }],
            'sans adresse e-mail' => [fn (self $t) => $t->client->forceFill(['email' => null])->save()],
        ];
    }

    #[DataProvider('casSansEnvoi')]
    public function test_rien_ne_part_quand(callable $situation): void
    {
        $situation($this);
        $this->vaccin('2026-11-09');

        $this->envoyer();

        Mail::assertNothingSent();
    }

    public function test_rien_ne_part_la_nuit_a_l_heure_de_la_clinique(): void
    {
        $this->vaccin('2026-11-09');

        $this->travelTo(CarbonImmutable::parse('2026-11-02 03:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_chaque_envoi_est_garde(): void
    {
        $vaccination = $this->vaccin('2026-11-09');

        $this->envoyer();

        $this->assertDatabaseHas('rappels_envoyes', [
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->patient->id,
            'client_id' => $this->client->id,
            'objet' => 'vaccin',
            'vaccination_id' => $vaccination->id,
            'canal' => 'email',
            'statut' => 'envoye',
            'adresse' => 'lea.martin@example.ch',
        ]);
    }

    public function test_un_envoi_en_echec_est_garde_puis_retente(): void
    {
        $this->vaccin('2026-11-09');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Resend injoignable'));

        $this->envoyer();

        $this->assertDatabaseHas('rappels_envoyes', ['objet' => 'vaccin', 'statut' => 'echec', 'tentatives' => 1]);
        $this->assertStringContainsString('Resend injoignable', (string) DB::table('rappels_envoyes')->value('erreur'));
    }

    public function test_la_fenetre_lit_les_prochains_rappels_et_les_envois_reels(): void
    {
        $this->vaccin('2026-11-09');
        $this->vaccin('2026-11-30', 'Leptospirose');
        $this->envoyer();

        $reponse = $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk();

        $reponse->assertJsonPath('data.envoyes.0.patient.nom', 'Félix')
            ->assertJsonPath('data.envoyes.0.objet', 'vaccin')
            ->assertJsonPath('data.envoyes.0.libelle', 'Rage')
            ->assertJsonPath('data.envoyes.0.statut', 'envoye')
            ->assertJsonPath('data.a_venir.0.libelle', 'Leptospirose')
            ->assertJsonPath('data.a_venir.0.envoi_le', '2026-11-23')
            ->assertJsonPath('data.a_venir.0.echeance_le', '2026-11-30');
        $this->assertStringNotContainsString('delivrabilite', $reponse->getContent());
    }

    public function test_le_proprietaire_se_desabonne_par_le_lien_de_l_e_mail(): void
    {
        $this->vaccin('2026-11-09');
        $this->envoyer();
        $lien = null;
        Mail::assertSent(RappelVaccin::class, function (RappelVaccin $mail) use (&$lien): bool {
            $lien = $mail->lienDesabonnement;

            return str_contains($mail->render(), $lien);
        });

        // Lien falsifié : refusé
        $this->post(preg_replace('/signature=[a-f0-9]+/', 'signature=faux', $lien))->assertForbidden();
        $this->assertTrue((bool) $this->client->refresh()->rappels_email);

        // Désabonnement en un clic (en-tête List-Unsubscribe-Post), sans jeton CSRF
        $this->post($lien)->assertOk();
        $this->assertFalse((bool) $this->client->refresh()->rappels_email);

        $this->vaccin('2026-11-30', 'Leptospirose');
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_le_lien_de_desabonnement_ouvert_dans_le_navigateur_confirme_d_abord(): void
    {
        $lien = URL::signedRoute('rappels.desabonner', ['client' => $this->client->id]);

        $this->get($lien)->assertOk()->assertSee('Ne plus recevoir les rappels');
        $this->assertTrue((bool) $this->client->refresh()->rappels_email);
    }

    public function test_rappeler_le_proprietaire_envoie_vraiment_et_le_garde(): void
    {
        $vaccination = $this->vaccin('2026-12-20');

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('data.adresse', 'lea.martin@example.ch');

        Mail::assertSent(RappelVaccin::class, 1);
        $this->assertDatabaseHas('rappels_envoyes', ['vaccination_id' => $vaccination->id, 'manuel' => true, 'statut' => 'envoye']);
    }

    public function test_rappeler_un_proprietaire_desabonne_est_refuse_avec_la_raison(): void
    {
        $vaccination = $this->vaccin('2026-12-20');
        $this->client->forceFill(['rappels_email' => false])->save();

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rappel' => 'Léa Martin a refusé les rappels par e-mail.']);

        Mail::assertNothingSent();
    }

    public function test_une_autre_organisation_ne_lit_ni_ne_rappelle(): void
    {
        $vaccination = $this->vaccin('2026-11-09');
        $this->envoyer();
        $ailleurs = Clinique::factory()->create();
        ['jeton' => $jetonAilleurs] = $this->administrateurAvecJeton($ailleurs);

        $this->getJson('/api/v1/rappels', $this->enTetes($jetonAilleurs))->assertOk()->assertJsonCount(0, 'data.envoyes');
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($jetonAilleurs))->assertNotFound();
    }

    /** Un combiné qui contient la rage renouvelle la rage : l'ancienne échéance n'est plus rappelée (comme le carnet). */
    public function test_un_combine_qui_couvre_la_valence_remplace_l_echeance(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'valences' => ['rage'], 'injectee_le' => '2025-11-09', 'echeance_le' => '2026-11-09']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Carré · Parvovirose · Leptospirose · Rage', 'valences' => ['carre', 'parvo', 'lepto', 'rage'], 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);

        $this->envoyer();

        Mail::assertNothingSent();
    }

    /** Le lien de désabonnement coupe aussi la préférence du compte ; la réactiver dans l'espace rétablit les rappels. */
    public function test_desabonnement_et_reabonnement_restent_d_accord_avec_l_espace(): void
    {
        ['user' => $user, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->client->forceFill(['user_id' => $user->id])->save();

        $this->post(URL::signedRoute('rappels.desabonner', ['client' => $this->client->id]))->assertOk();
        $this->getJson('/api/v1/espace/preferences', $this->enTetes($jetonProprietaire))->assertOk()->assertJsonPath('data.rappels_email', false);

        $this->putJson('/api/v1/espace/preferences', ['rappels_email' => true], $this->enTetes($jetonProprietaire))->assertOk();
        $this->assertTrue((bool) $this->client->refresh()->rappels_email);

        $this->vaccin('2026-11-09');
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, 1);
    }

    /** Une organisation fermée (abonnement terminé, vérification refusée) n'envoie plus de rappels. */
    public function test_une_organisation_fermee_n_envoie_pas(): void
    {
        $this->clinique->societe->organisation->forceFill(['statut_abonnement' => null, 'verification' => 'refusee'])->save();
        $this->vaccin('2026-11-09');

        $this->envoyer();

        Mail::assertNothingSent();
    }

    /** Un contrôle déplacé dans la même journée est rappelé à sa nouvelle heure. */
    public function test_un_controle_deplace_le_meme_jour_est_rappele_a_sa_nouvelle_heure(): void
    {
        $debut = CarbonImmutable::parse('2026-11-09 14:30', 'Europe/Zurich')->utc();
        $rendezVous = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);
        $this->envoyer();

        $nouveau = CarbonImmutable::parse('2026-11-09 09:00', 'Europe/Zurich')->utc();
        $rendezVous->forceFill(['starts_at' => $nouveau, 'ends_at' => $nouveau->addMinutes(20)])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:05', 'Europe/Zurich'));
        $this->envoyer();

        Mail::assertSent(RappelControle::class, 2);
        Mail::assertSent(RappelControle::class, fn (RappelControle $mail) => str_contains($mail->render(), '09:00'));
    }

    /** Les compteurs de la fenêtre viennent du serveur, sans plafond de liste. */
    public function test_les_compteurs_ne_sont_pas_plafonnes_par_la_liste(): void
    {
        foreach (range(1, 60) as $i) {
            RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'objet' => 'vaccin', 'libelle' => 'Rage', 'echeance_le' => '2026-11-09', 'canal' => 'email', 'statut' => 'envoye', 'envoye_le' => now()->subDays(2), 'cle' => 'essai:'.$i]);
        }

        $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.compteurs.envoyes', 60)
            ->assertJsonPath('data.compteurs.echecs', 0)
            ->assertJsonCount(50, 'data.envoyes');
    }

    /** Un envoi déjà pris par une autre exécution (concurrente) n'est pas refait. */
    public function test_un_envoi_deja_pris_par_une_autre_execution_n_est_pas_refait(): void
    {
        $vaccination = $this->vaccin('2026-11-09');
        RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'objet' => 'vaccin', 'vaccination_id' => $vaccination->id, 'libelle' => 'Rage', 'echeance_le' => '2026-11-09', 'canal' => 'email', 'statut' => 'envoi', 'cle' => 'vaccin:'.$vaccination->id.':2026-11-09:email']);

        $this->envoyer();

        Mail::assertNothingSent();
    }

    /** Peu de requêtes, quel que soit le nombre de vaccins dus. */
    public function test_le_nombre_de_requetes_ne_croit_pas_avec_les_vaccins(): void
    {
        foreach (range(1, 30) as $i) {
            $animal = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $this->client->id, 'nom' => 'Animal '.$i]);
            Vaccination::factory()->create(['patient_id' => $animal->id, 'nom' => 'Rage', 'injectee_le' => '2025-11-09', 'echeance_le' => '2026-11-09']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-02 03:00', 'Europe/Zurich'));

        DB::enableQueryLog();
        $this->envoyer();

        $this->assertLessThan(25, count(DB::getQueryLog()));
    }

    /** Un vaccin sans échéance rappelé à la main : l'e-mail ne dit pas « prévu pour le . ». */
    public function test_un_rappel_sans_echeance_dit_a_prevoir(): void
    {
        $vaccination = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2025-11-09', 'echeance_le' => null]);

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        Mail::assertSent(RappelVaccin::class, fn (RappelVaccin $mail) => str_contains($mail->render(), 'est à prévoir') && ! str_contains($mail->render(), 'pour le .'));
    }

    /* ---------- Deuxième passe de vérification (28.10.2026) ---------- */

    /** Un rappel abandonné (trois échecs) ou en cours d'envoi n'est plus annoncé « à venir » ; un échec qui sera repris l'est encore. */
    public function test_un_rappel_abandonne_ou_en_cours_ne_figure_plus_dans_les_prochains(): void
    {
        $abandonne = $this->vaccin('2026-11-09', 'Rage');
        $enCours = $this->vaccin('2026-11-10', 'Leucose');
        $repris = $this->vaccin('2026-11-11', 'Typhus');
        $trace = fn (Vaccination $v, string $statut, int $tentatives) => RappelEnvoye::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'objet' => 'vaccin', 'vaccination_id' => $v->id, 'libelle' => $v->nom,
            'echeance_le' => $v->echeance_le, 'canal' => 'email', 'statut' => $statut, 'tentatives' => $tentatives, 'cle' => 'vaccin:'.$v->id.':'.$v->echeance_le->toDateString().':email',
        ]);
        $trace($abandonne, 'echec', 3);
        $trace($enCours, 'envoi', 1);
        $trace($repris, 'echec', 1);

        $prochains = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'))->pluck('libelle')->all();

        $this->assertSame(['Typhus'], $prochains);
    }

    /** La fenêtre des rappels lit les refus des espaces en une fois : autant de requêtes pour 5 propriétaires que pour 40. */
    public function test_la_fenetre_des_rappels_ne_croit_pas_avec_les_proprietaires(): void
    {
        $requetes = function (int $nombre): int {
            foreach (range(1, $nombre) as $i) {
                $user = User::factory()->create();
                PreferencesProprietaire::pour($user);
                $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'user_id' => $user->id, 'email' => 'p'.$nombre.'-'.$i.'@example.ch']);
                $animal = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $client->id]);
                RendezVous::factory()->pourPatient($animal)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
                Vaccination::factory()->create(['patient_id' => $animal->id, 'nom' => 'Rage', 'injectee_le' => '2025-11-20', 'echeance_le' => '2026-11-20']);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Une première lecture à blanc, sans propriétaire : jeton et clinique déjà lus
        $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk();
        $cinq = $requetes(5);
        $quarante = $requetes(35);

        $this->assertLessThanOrEqual($cinq, $quarante);
    }

    /** Désabonnée par le lien sans compte, puis rattachée à un compte : son espace dit « Non », pas « Oui ». */
    public function test_l_espace_dit_non_quand_la_fiche_a_refuse_par_le_lien(): void
    {
        $this->post(RappelsProprietaires::lienDesabonnement($this->client))->assertOk();
        ['user' => $user, 'jeton' => $jetonEspace] = $this->proprietaireAvecJeton(['email' => 'lea.martin@example.ch']);
        $this->client->forceFill(['user_id' => $user->id])->save();

        $this->getJson('/api/v1/espace/preferences', $this->enTetes($jetonEspace))->assertOk()->assertJsonPath('data.rappels_email', false);
        $this->putJson('/api/v1/espace/preferences', ['rappels_email' => true], $this->enTetes($jetonEspace))->assertOk()->assertJsonPath('data.rappels_email', true);
        $this->assertTrue($this->client->refresh()->rappels_email);
    }

    /** Le contrôle d'un animal décédé n'est pas annoncé comme programmé. */
    public function test_le_controle_d_un_animal_decede_est_bloque_dans_la_fenetre(): void
    {
        $debut = CarbonImmutable::parse('2026-11-09 14:30', 'Europe/Zurich')->utc();
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);
        $this->patient->forceFill(['decede_le' => now()->subDay()])->save();

        $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.a_venir.0.bloque', 'Animal décédé');
    }

    /** Case 3 : le délai d'un contrôle suit le réglage de la clinique (la veille : rien à J-3 ni à J-2). */
    public function test_le_delai_d_un_controle_suit_le_reglage(): void
    {
        $this->reglages(['delai_jours' => 1]);
        $debut = CarbonImmutable::parse('2026-11-05 14:30', 'Europe/Zurich')->utc();
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);

        $this->envoyer();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelControle::class, 1);
    }

    /** Case 5 : la nuit se compte à l'heure de la clinique, pour les vaccins comme pour les contrôles. */
    public function test_la_nuit_se_compte_a_l_heure_de_la_clinique(): void
    {
        $this->clinique->forceFill(['fuseau_horaire' => 'America/New_York'])->save();
        $this->vaccin('2026-11-09');
        $debut = CarbonImmutable::parse('2026-11-09 14:30', 'America/New_York')->utc();
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);

        // 10 h à Zurich, 5 h à New York : la nuit de la clinique
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNothingSent();

        // 15 h à Zurich, 9 h à New York
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, 1);
        Mail::assertSent(RappelControle::class, 1);
    }

    /** Case 7 : le compteur des échecs vient du serveur. */
    public function test_le_compteur_des_echecs_vient_du_serveur(): void
    {
        foreach (range(1, 4) as $i) {
            RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'objet' => 'vaccin', 'libelle' => 'Rage', 'echeance_le' => '2026-11-09', 'canal' => 'email', 'statut' => 'echec', 'tentatives' => 3, 'cle' => 'echec:'.$i]);
        }

        $this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.compteurs.echecs', 4);
    }
}
