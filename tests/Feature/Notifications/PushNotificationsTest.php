<?php

namespace Tests\Feature\Notifications;

use App\Enums\PushType;
use App\Jobs\SendPush;
use App\Models\AppareilNotification;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\JetonApi;
use App\Models\NotificationSetting;
use App\Models\Patient;
use App\Models\RendezVous;
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
}
