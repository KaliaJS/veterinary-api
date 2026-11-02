<?php

namespace Tests\Feature\Notifications;

use App\Enums\PushType;
use App\Jobs\SendPush;
use App\Models\Absence;
use App\Models\AppareilNotification;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\ExamenImagerie;
use App\Models\HoraireOuverture;
use App\Models\Intervention;
use App\Models\JetonApi;
use App\Models\LignePlanning;
use App\Models\ListeAttente;
use App\Models\NotificationSetting;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\UrgenceAttente;
use App\Models\User;
use App\Services\Notifications\CanalApns;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les notifications du téléphone (choix de Jim, 02.11.2026) : envoyées en
 * direct chez Apple aux iPhone d'un compte ; chacun choisit les sortes
 * reçues et sa nuit (oui ou non, et ses heures) ; les événements du
 * propriétaire et de l'équipe les déclenchent.
 */
class PushNotificationsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @var list<array{jetons: list<string>, environnement: string, sujet: string, contenu: array<string, mixed>, visible: bool}> */
    private array $envois = [];

    private function fauxApple(array $morts = []): void
    {
        $test = $this;
        $this->app->instance(CanalApns::class, new class($test, $morts) implements CanalApns
        {
            public function __construct(private PushNotificationsTest $test, private array $morts) {}

            public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu, bool $visible = false): array
            {
                $this->test->noter(compact('jetons', 'environnement', 'sujet', 'contenu', 'visible'));

                return array_values(array_intersect($jetons, $this->morts));
            }
        });
        config(['services.apns.cle_base64' => base64_encode('clé de test')]);
    }

    public function noter(array $envoi): void
    {
        $this->envois[] = $envoi;
    }

    // ————— Réglages —————

    public function test_par_defaut_tout_est_permis_et_la_nuit_va_de_22_h_a_7_h_reglable(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        Client::factory()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/auth/notifications', $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.quiet_enabled', true)
            ->assertJsonPath('data.quiet_from', '22:00')
            ->assertJsonPath('data.quiet_to', '07:00')
            ->assertJsonPath('data.has_emergencies', false)
            ->assertJsonPath('data.types.0.key', 'consent_request')
            ->assertJsonCount(4, 'data.types');

        $this->putJson('/api/v1/auth/notifications', [
            'disabled_types' => ['quote'],
            'quiet_enabled' => true,
            'quiet_from' => '21:30',
            'quiet_to' => '06:15',
            'urgent_at_night' => false,
            'timezone' => 'Europe/Zurich',
        ], $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.quiet_from', '21:30')
            ->assertJsonPath('data.types.1.enabled', false);

        $this->putJson('/api/v1/auth/notifications', ['disabled_types' => [], 'quiet_enabled' => true, 'quiet_from' => '25:00', 'quiet_to' => '7h', 'urgent_at_night' => true, 'timezone' => 'Lune/Mer'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['quiet_from', 'quiet_to', 'timezone']);
    }

    public function test_l_equipe_voit_ses_sortes_et_les_urgences(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::factory()->create());
        $types = collect($this->getJson('/api/v1/auth/notifications', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.has_emergencies', true)->json('data.types'))->pluck('key')->all();
        $this->assertSame(['consent_signed', 'emergency', 'team_message'], $types);
    }

    public function test_la_nuit_qui_passe_minuit_coupe_sauf_les_urgences_si_on_les_veut(): void
    {
        $s = new NotificationSetting(['disabled_types' => [], 'quiet_enabled' => true, 'quiet_from' => '22:00', 'quiet_to' => '07:00', 'urgent_at_night' => true, 'timezone' => 'Europe/Zurich']);
        $a = fn (string $heure) => CarbonImmutable::parse('2026-11-05 '.$heure, 'Europe/Zurich');

        $this->assertFalse($s->allows(PushType::Quote, $a('23:30')));
        $this->assertFalse($s->allows(PushType::Quote, $a('06:59')));
        $this->assertTrue($s->allows(PushType::Quote, $a('07:00')));
        $this->assertTrue($s->allows(PushType::Emergency, $a('03:00')));
        $s->urgent_at_night = false;
        $this->assertFalse($s->allows(PushType::Emergency, $a('03:00')));
        $s->quiet_enabled = false;
        $this->assertTrue($s->allows(PushType::Quote, $a('03:00')));
        $s->disabled_types = ['quote'];
        $this->assertFalse($s->allows(PushType::Quote, $a('12:00')));
    }

    // ————— L'envoi —————

    public function test_l_envoi_va_aux_iphone_du_compte_affiche_et_oublie_les_jetons_morts(): void
    {
        $this->fauxApple(morts: [str_repeat('b', 64)]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        $user = User::factory()->create();
        AppareilNotification::query()->create(['user_id' => $user->id, 'jeton' => str_repeat('a', 64), 'environnement' => 'production', 'plateforme' => 'ios']);
        AppareilNotification::query()->create(['user_id' => $user->id, 'jeton' => str_repeat('b', 64), 'environnement' => 'production', 'plateforme' => 'ios']);
        AppareilNotification::query()->create(['user_id' => $user->id, 'jeton' => str_repeat('c', 64), 'environnement' => 'production', 'plateforme' => 'watchos']);

        (new SendPush($user->id, 'quote', 'Deux Rives', 'Devis reçu pour Bagheera', '/espace/documents'))->handle($this->app->make(CanalApns::class));

        $this->assertCount(1, $this->envois);
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], $this->envois[0]['jetons']);
        $this->assertTrue($this->envois[0]['visible']);
        $this->assertSame('ch.veterinary.client', $this->envois[0]['sujet']);
        $this->assertSame('Devis reçu pour Bagheera', $this->envois[0]['contenu']['aps']['alert']['body']);
        $this->assertSame('/espace/documents', $this->envois[0]['contenu']['url']);
        $this->assertFalse(AppareilNotification::query()->where('jeton', str_repeat('b', 64))->exists());
        $this->assertTrue(AppareilNotification::query()->where('jeton', str_repeat('c', 64))->exists());
    }

    public function test_rien_ne_part_la_nuit_ni_pour_une_sorte_coupee(): void
    {
        $this->fauxApple();
        $user = User::factory()->create();
        AppareilNotification::query()->create(['user_id' => $user->id, 'jeton' => str_repeat('a', 64), 'environnement' => 'sandbox', 'plateforme' => 'ios']);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 23:15', 'Europe/Zurich'));
        (new SendPush($user->id, 'quote', 'Deux Rives', 'x'))->handle($this->app->make(CanalApns::class));
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        NotificationSetting::for($user->id)->fill(['disabled_types' => ['quote']])->save();
        (new SendPush($user->id, 'quote', 'Deux Rives', 'x'))->handle($this->app->make(CanalApns::class));

        $this->assertSame([], $this->envois);
    }

    public function test_a_la_deconnexion_le_telephone_est_oublie(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/auth/appareil', ['jeton' => str_repeat('A', 64), 'environnement' => 'production', 'plateforme' => 'ios'], $this->enTetes($jeton))->assertNoContent();
        $this->assertTrue(AppareilNotification::query()->where('user_id', $user->id)->exists());
        $this->deleteJson('/api/v1/auth/appareil', ['jeton' => str_repeat('A', 64)], $this->enTetes($jeton))->assertNoContent();
        $this->assertFalse(AppareilNotification::query()->where('user_id', $user->id)->exists());
    }

    // ————— Les événements —————

    public function test_une_urgence_previent_l_equipe_du_planning_sauf_qui_l_ecrit(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create();
        ['employe' => $auteur, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);
        ['employe' => $collegue] = $this->employeAvecJeton($clinique);
        ['employe' => $ailleurs] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->postJson('/api/v1/urgences', ['patient_name' => 'Pacha', 'owner_name' => 'Weber', 'reason' => 'Ingestion de chocolat', 'triage' => 3, 'duree_minutes' => 30], $this->enTetes($jeton))->assertCreated();

        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $collegue->user_id && $j->type === 'emergency' && $j->title === 'Nouvelle urgence : Vital' && str_contains($j->body, 'Pacha'));
        Queue::assertNotPushed(SendPush::class, fn (SendPush $j): bool => in_array($j->userId, [$auteur->user_id, $ailleurs->user_id], true));
    }

    public function test_un_message_d_un_collegue_le_previent(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create();
        ['employe' => $anne, 'jeton' => $jetonAnne] = $this->employeAvecJeton($clinique, ['nom' => 'Dre Anne Meyer']);
        ['employe' => $marc] = $this->employeAvecJeton($clinique, ['nom' => 'Dr Marc Dubois']);

        $this->postJson('/api/v1/messages/e'.$marc->id, ['texte' => 'Tu peux reprendre mon 16 h ?'], $this->enTetes($jetonAnne))->assertCreated();

        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $marc->user_id && $j->type === 'team_message' && $j->title === 'Dre Anne Meyer');
        Queue::assertNotPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $anne->user_id);
    }

    public function test_un_rendez_vous_annule_par_la_clinique_previent_le_proprietaire(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'nom' => 'Deux Rives']);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $proprietaire = User::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $proprietaire->id]);
        $patient = Patient::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'client_id' => $client->id, 'nom' => 'Félix']);
        $rdv = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 20)]);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))->assertOk();

        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $proprietaire->id && $j->type === 'appointment' && str_contains($j->body, 'annulé') && $j->url === '/espace/rendez-vous');
    }

    public function test_un_telephone_dont_la_session_est_fermee_ou_expiree_ne_recoit_plus_rien(): void
    {
        $this->fauxApple();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/auth/appareil', ['jeton' => str_repeat('a', 64), 'environnement' => 'production', 'plateforme' => 'ios'], $this->enTetes($jeton))->assertNoContent();
        $appareil = AppareilNotification::query()->sole();
        $this->assertNotNull($appareil->personal_access_token_id);

        // Expirée : rien ne part
        JetonApi::query()->whereKey($appareil->personal_access_token_id)->update(['expires_at' => now()->subMinute()]);
        (new SendPush($user->id, 'quote', 'Deux Rives', 'x'))->handle($this->app->make(CanalApns::class));
        $this->assertSame([], $this->envois);

        // Fermée (« Déconnecter les autres appareils », déconnexion) : l'appareil disparaît avec elle
        JetonApi::query()->whereKey($appareil->personal_access_token_id)->delete();
        $this->assertSame(0, AppareilNotification::query()->count());
    }

    public function test_un_rendez_vous_deplace_depuis_la_fiche_previent_mais_pas_un_rendez_vous_passe(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $proprietaire = User::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $proprietaire->id]);
        $patient = Patient::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'client_id' => $client->id]);
        $rdv = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 20)]);

        $this->putJson("/api/v1/rendez-vous/{$rdv->id}", ['starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $proprietaire->id && str_contains($j->body, 'déplacé'));

        $passe = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'starts_at' => now()->subDays(2)->setTime(9, 0), 'ends_at' => now()->subDays(2)->setTime(9, 20)]);
        Queue::fake([SendPush::class]);
        $this->postJson("/api/v1/rendez-vous/{$passe->id}/annuler", [], $this->enTetes($jeton));
        Queue::assertNotPushed(SendPush::class);
    }

    public function test_un_message_a_une_autre_clinique_ne_previent_pas_son_auteur(): void
    {
        Queue::fake([SendPush::class]);
        $lausanne = Clinique::factory()->create(['nom' => 'Lausanne']);
        $morges = Clinique::factory()->create(['nom' => 'Morges']);
        ['employe' => $anne, 'jeton' => $jeton] = $this->employeAvecJeton($lausanne);
        $anne->cliniques()->attach($morges->id);
        // Marc lit les Messages de Morges (droit patients)
        ['employe' => $marc] = $this->administrateurAvecJeton($morges);

        $this->postJson('/api/v1/messages/k'.$morges->id, ['texte' => 'Bonjour'], $this->enTetes($jeton))->assertCreated();

        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $marc->user_id);
        Queue::assertNotPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $anne->user_id);
    }

    // ————— Tous les chemins d'un rendez-vous (vérification du 02.11.2026) —————

    /** Un propriétaire avec un compte et une fiche à la clinique, et son animal */
    private function animalDUnProprietaire(Clinique $clinique, string $nom = 'Félix'): array
    {
        $proprietaire = User::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $proprietaire->id]);

        return [$proprietaire, Patient::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'client_id' => $client->id, 'nom' => $nom])];
    }

    private function poussePour(int $userId, string $mot): \Closure
    {
        return fn (SendPush $j): bool => $j->userId === $userId && $j->type === 'appointment' && str_contains($j->body, $mot);
    }

    public function test_un_rendez_vous_pris_en_ligne_sans_fiche_previent_son_proprietaire_confirme_deplace_annule(): void
    {
        Queue::fake([SendPush::class]);
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->setTime(9, 0));
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $proprietaire = User::factory()->create();
        $demande = DemandeRendezVous::factory()->create(['clinique_id' => $clinique->id, 'user_id' => $proprietaire->id, 'patient_name' => 'Nala', 'duree_minutes' => 30]);

        $id = $this->postJson("/api/v1/demandes/{$demande->id}/placer", ['ligne_planning_id' => $ligne->id, 'starts_at' => now()->addDay()->setTime(16, 0)->toISOString()], $this->enTetes($jeton))
            ->assertCreated()->json('data.id');
        $this->assertNull(RendezVous::withoutGlobalScopes()->findOrFail($id)->client_id);
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'Nala confirmé'));

        $this->postJson("/api/v1/rendez-vous/{$id}/deplacer", ['starts_at' => now()->addDay()->setTime(15, 0)->toISOString()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'déplacé'));

        $this->postJson("/api/v1/rendez-vous/{$id}/annuler", [], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'annulé'));
    }

    public function test_une_demande_placee_ou_un_rendez_vous_glisse_au_planning_previent_le_proprietaire_qui_a_une_fiche(): void
    {
        Queue::fake([SendPush::class]);
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->setTime(9, 0));
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        [$proprietaire, $patient] = $this->animalDUnProprietaire($clinique);
        $demande = DemandeRendezVous::factory()->create(['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'client_id' => $patient->client_id, 'patient_name' => 'Félix', 'duree_minutes' => 30]);

        $id = $this->postJson("/api/v1/demandes/{$demande->id}/placer", ['ligne_planning_id' => $ligne->id, 'starts_at' => now()->addDay()->setTime(16, 0)->toISOString()], $this->enTetes($jeton))
            ->assertCreated()->json('data.id');
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'Félix confirmé'));

        $this->postJson("/api/v1/rendez-vous/{$id}/deplacer", ['starts_at' => now()->addDay()->setTime(15, 0)->toISOString()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'déplacé'));
    }

    public function test_une_absence_validee_previent_les_proprietaires_des_rendez_vous_annules(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        [$proprietaire, $patient] = $this->animalDUnProprietaire($clinique);
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'ligne_planning_id' => $ligne->id, 'starts_at' => aujourdhui()->addDay()->setTime(9, 0), 'ends_at' => aujourdhui()->addDay()->setTime(9, 30)]);
        $absence = Absence::factory()->enAttente()->create(['employe_id' => $ligne->employe_id, 'debut_le' => aujourdhui()->addDay(), 'fin_le' => aujourdhui()->addDays(2)]);

        $this->postJson("/api/v1/absences/{$absence->id}/valider", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('meta.rendez_vous_annules', 1);

        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'annulé'));
    }

    public function test_une_intervention_ou_un_examen_deplace_previent_le_proprietaire_de_son_rendez_vous(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        [$proprietaire, $patient] = $this->animalDUnProprietaire($clinique);
        $quand = now()->addDays(3)->setTime(9, 0);
        $rdvBloc = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'type' => 'chir', 'starts_at' => $quand, 'ends_at' => $quand->copy()->addHour()]);
        $intervention = Intervention::factory()->create(['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'rendez_vous_id' => $rdvBloc->id, 'prevu_le' => $quand, 'duree_minutes' => 60]);
        $rdvImagerie = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'starts_at' => $quand->copy()->addHours(3), 'ends_at' => $quand->copy()->addHours(3)->addMinutes(30)]);
        $examen = ExamenImagerie::factory()->create(['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'rendez_vous_id' => $rdvImagerie->id, 'realise_le' => $quand->copy()->addHours(3), 'duree_minutes' => 30]);

        // La durée seule ne déplace rien
        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", ['duree_minutes' => 90], $this->enTetes($jeton))->assertOk();
        Queue::assertNotPushed(SendPush::class);

        $this->patchJson("/api/v1/bloc/interventions/{$intervention->id}", ['prevu_le' => $quand->copy()->addDay()->toIso8601String()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, 1);
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'déplacé'));

        $this->patchJson("/api/v1/imagerie/examens/{$examen->id}", ['realise_le' => $quand->copy()->addDays(2)->toIso8601String()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, 2);
    }

    public function test_changer_l_animal_d_un_rendez_vous_previent_l_ancien_et_le_nouveau_proprietaire(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        [$ancien, $felix] = $this->animalDUnProprietaire($clinique, 'Félix');
        [$nouveau, $nala] = $this->animalDUnProprietaire($clinique, 'Nala');
        $rdv = RendezVous::factory()->pourPatient($felix)->create(['clinique_id' => $clinique->id, 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 20)]);

        $this->putJson("/api/v1/rendez-vous/{$rdv->id}", ['patient_id' => $nala->id, 'starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String()], $this->enTetes($jeton))->assertOk();

        Queue::assertPushed(SendPush::class, $this->poussePour($ancien->id, 'Félix annulé'));
        Queue::assertPushed(SendPush::class, $this->poussePour($nouveau->id, 'Nala confirmé'));
        Queue::assertNotPushed(SendPush::class, $this->poussePour($nouveau->id, 'déplacé'));
    }

    // ————— Les mêmes comptes que « Mes rendez-vous » (deuxième vérification du 02.11.2026) —————

    public function test_rattacher_l_animal_d_un_nouveau_client_ne_lui_annonce_pas_une_annulation(): void
    {
        Queue::fake([SendPush::class]);
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->setTime(9, 0));
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $proprietaire = User::factory()->create();
        $demande = DemandeRendezVous::factory()->create(['clinique_id' => $clinique->id, 'user_id' => $proprietaire->id, 'patient_name' => 'Nala', 'duree_minutes' => 30]);
        $id = $this->postJson("/api/v1/demandes/{$demande->id}/placer", ['ligne_planning_id' => $ligne->id, 'starts_at' => now()->addDay()->setTime(16, 0)->toISOString()], $this->enTetes($jeton))->assertCreated()->json('data.id');

        // L'accueil crée sa fiche (sans compte lié) et rattache l'animal au rendez-vous
        $fiche = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => null]);
        $nala = Patient::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'client_id' => $fiche->id, 'nom' => 'Nala']);
        Queue::fake([SendPush::class]);
        $this->putJson("/api/v1/rendez-vous/{$id}", ['patient_id' => $nala->id], $this->enTetes($jeton))->assertOk();
        Queue::assertNotPushed(SendPush::class);

        // Il voit toujours ce rendez-vous dans son espace : un déplacement le prévient encore
        $this->postJson("/api/v1/rendez-vous/{$id}/deplacer", ['starts_at' => now()->addDay()->setTime(15, 0)->toISOString()], $this->enTetes($jeton))->assertOk();
        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'déplacé'));
    }

    public function test_l_animal_rattache_au_compte_par_la_puce_previent_son_proprietaire(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $proprietaire = User::factory()->create();
        $fiche = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => null]);
        $patient = Patient::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'client_id' => $fiche->id, 'proprietaire_id' => $proprietaire->id, 'nom' => 'Pacha']);
        $rdv = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $clinique->id, 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 20)]);

        $this->postJson("/api/v1/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))->assertOk();

        Queue::assertPushed(SendPush::class, $this->poussePour($proprietaire->id, 'Pacha annulé'));
    }

    public function test_changer_l_animal_d_un_rendez_vous_annule_ne_previent_personne(): void
    {
        Queue::fake([SendPush::class]);
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        [, $felix] = $this->animalDUnProprietaire($clinique, 'Félix');
        [, $nala] = $this->animalDUnProprietaire($clinique, 'Nala');
        $rdv = RendezVous::factory()->pourPatient($felix)->create(['clinique_id' => $clinique->id, 'status' => 'annule', 'starts_at' => now()->addDays(2)->setTime(9, 0), 'ends_at' => now()->addDays(2)->setTime(9, 20)]);

        $this->putJson("/api/v1/rendez-vous/{$rdv->id}", ['patient_id' => $nala->id], $this->enTetes($jeton));

        Queue::assertNotPushed(SendPush::class);
    }

    // ————— Un rendez-vous créé par la clinique (décision de Jim, 02.11.2026) —————

    public function test_un_rendez_vous_cree_par_la_clinique_previent_le_proprietaire_confirme(): void
    {
        Queue::fake([SendPush::class]);
        $this->travelTo(CarbonImmutable::now('Europe/Zurich')->setTime(9, 0));
        $clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        $ligne = LignePlanning::factory()->create(['clinique_id' => $clinique->id]);
        ['user' => $auteur, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);
        [$proprietaire, $patient] = $this->animalDUnProprietaire($clinique, 'Félix');
        $demain = fn (int $h) => aujourdhui()->addDay()->setTime($h, 0)->toISOString();
        $compte = fn () => Queue::pushed(SendPush::class, $this->poussePour($proprietaire->id, 'Félix confirmé'))->count();

        // Au planning
        $this->postJson('/api/v1/rendez-vous', ['ligne_planning_id' => $ligne->id, 'starts_at' => $demain(10), 'duree_minutes' => 20, 'patient_id' => $patient->id, 'reason' => 'Contrôle'], $this->enTetes($jeton))->assertCreated();
        $this->assertSame(1, $compte());

        // Depuis la liste d'attente
        $inscription = ListeAttente::factory()->create(['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'patient_name' => 'Félix', 'duree_minutes' => 20]);
        $this->postJson("/api/v1/liste-attente/{$inscription->id}/placer", ['ligne_planning_id' => $ligne->id, 'starts_at' => $demain(11)], $this->enTetes($jeton))->assertCreated();
        $this->assertSame(2, $compte());

        // Une urgence placée un autre jour
        $urgence = UrgenceAttente::factory()->create(['clinique_id' => $clinique->id, 'patient_id' => $patient->id, 'patient_name' => 'Félix', 'duree_minutes' => 30]);
        $this->postJson("/api/v1/urgences/{$urgence->id}/placer", ['ligne_planning_id' => $ligne->id, 'starts_at' => $demain(14)], $this->enTetes($jeton))->assertCreated();
        $this->assertSame(3, $compte());

        // Le prochain créneau libre
        $this->postJson('/api/v1/rendez-vous/prochain-creneau', ['patient_id' => $patient->id, 'reason' => 'Suivi', 'date' => aujourdhui()->addDay()->toDateString()], $this->enTetes($jeton))->assertCreated();
        $this->assertSame(4, $compte());

        // Jamais à l'auteur, rien pour un créneau sans animal
        Queue::assertNotPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $auteur->id);
    }
}
