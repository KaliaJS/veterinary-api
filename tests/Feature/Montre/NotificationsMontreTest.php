<?php

namespace Tests\Feature\Montre;

use App\Enums\StatutRendezVous;
use App\Jobs\PrevenirMontres;
use App\Models\AppareilNotification;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\RendezVous;
use App\Services\Notifications\CanalApns;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La montre prévenue par Apple (décision de Jim, 28.10.2026, « Ok pour la
 * montre ») : quand un rendez-vous de la clinique change, l'API envoie une
 * notification invisible aux montres de l'équipe, qui relisent leur journée.
 */
class NotificationsMontreTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @var list<array{jetons: list<string>, environnement: string, sujet: string, contenu: array<string, mixed>}> */
    private array $envois = [];

    private Clinique $clinique;

    private string $jeton;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich'));
        config(['services.apns.cle_base64' => 'cle-essai', 'services.apns.key_id' => 'K', 'services.apns.team_id' => 'T']);
        $test = $this;
        $this->app->instance(CanalApns::class, new class($test) implements CanalApns
        {
            public function __construct(private NotificationsMontreTest $test) {}

            public function envoyer(array $jetons, string $environnement, string $sujet, array $contenu): array
            {
                $this->test->noter($jetons, $environnement, $sujet, $contenu);

                return array_values(array_filter($jetons, fn (string $j): bool => str_starts_with($j, 'perime')));
            }
        });
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        $this->userId = $user->id;
    }

    /** @param  list<string>  $jetons @param  array<string, mixed>  $contenu */
    public function noter(array $jetons, string $environnement, string $sujet, array $contenu): void
    {
        $this->envois[] = compact('jetons', 'environnement', 'sujet', 'contenu');
    }

    public function test_la_montre_enregistre_son_jeton_d_apple(): void
    {
        $this->postJson('/api/v1/auth/appareil', ['jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos'], $this->enTetes($this->jeton))->assertNoContent();
        $this->postJson('/api/v1/auth/appareil', ['jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos'], $this->enTetes($this->jeton))->assertNoContent();

        $this->assertSame(1, AppareilNotification::query()->where('user_id', $this->userId)->count());
        $this->postJson('/api/v1/auth/appareil', ['jeton' => 'pas-un-jeton', 'environnement' => 'sandbox', 'plateforme' => 'watchos'], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_un_rendez_vous_pris_previent_les_montres_de_l_equipe_par_une_notification_invisible(): void
    {
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos']);

        RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(20)]);

        $this->assertCount(1, $this->envois);
        $this->assertSame([str_repeat('ab', 32)], $this->envois[0]['jetons']);
        $this->assertSame('sandbox', $this->envois[0]['environnement']);
        $this->assertSame('ch.veterinary.client.watchkitapp', $this->envois[0]['sujet']);
        // Invisible : aucune alerte, la montre relit sa journée
        $this->assertSame(['aps' => ['content-available' => 1], 'veterinary' => 'journee'], $this->envois[0]['contenu']);
    }

    public function test_un_rendez_vous_lointain_ne_reveille_aucune_montre(): void
    {
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => str_repeat('ab', 32), 'environnement' => 'production', 'plateforme' => 'watchos']);

        RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addMinutes(20)]);

        $this->assertSame([], $this->envois);
    }

    public function test_une_autre_clinique_ne_previent_pas_ces_montres(): void
    {
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos']);

        RendezVous::factory()->create(['clinique_id' => Clinique::factory()->create()->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        $this->assertSame([], $this->envois);
    }

    public function test_un_jeton_qu_apple_refuse_est_oublie(): void
    {
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => 'perime'.str_repeat('0', 58), 'environnement' => 'sandbox', 'plateforme' => 'watchos']);

        RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        $this->assertSame(0, AppareilNotification::query()->count());
    }

    public function test_sans_cle_d_apple_rien_ne_part(): void
    {
        config(['services.apns.cle_base64' => null]);
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos']);

        RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        $this->assertSame([], $this->envois);
    }

    private function montre(): void
    {
        AppareilNotification::query()->create(['user_id' => $this->userId, 'jeton' => str_repeat('ab', 32), 'environnement' => 'sandbox', 'plateforme' => 'watchos']);
    }

    /** Déplacé, annulé, modifié, supprimé : chaque changement prévient aussi, pas seulement la prise */
    public function test_deplace_annule_modifie_ou_supprime_previent_aussi(): void
    {
        $this->montre();
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(20)]);

        foreach ([
            'déplacé' => fn () => $rendezVous->update(['starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(3)->addMinutes(20)]),
            'annulé' => fn () => $rendezVous->update(['status' => StatutRendezVous::Annule]),
            'modifié' => fn () => $rendezVous->update(['reason' => 'Vaccin et contrôle']),
            'supprimé' => fn () => $rendezVous->delete(),
        ] as $geste => $faire) {
            $this->envois = [];
            $faire();
            $this->assertCount(1, $this->envois, "Rendez-vous $geste : la montre doit être prévenue");
        }
    }

    /** Déplacé de « dans 2 h » à « dans 10 jours » : il quitte la journée, la montre est prévenue */
    public function test_un_rendez_vous_qui_quitte_la_journee_previent_aussi(): void
    {
        $this->montre();
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(20)]);
        $this->envois = [];

        $rendezVous->update(['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addMinutes(20)]);

        $this->assertCount(1, $this->envois);
    }

    /** Un rendez-vous du jour à 07:30, annulé à 20:00 : la montre montre toute la journée, elle est prévenue */
    public function test_un_rendez_vous_du_matin_annule_le_soir_previent_aussi(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 20:00', 'Europe/Zurich'));
        // 07:30 à Zurich, 12 h 30 plus tôt
        $rendezVous = RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMinutes(750), 'ends_at' => now()->subMinutes(730)]);
        $this->montre();

        $rendezVous->update(['status' => StatutRendezVous::Annule]);

        $this->assertCount(1, $this->envois);
    }

    /** Un employé suspendu ne reçoit plus rien */
    public function test_un_employe_suspendu_n_est_pas_prevenu(): void
    {
        $this->montre();
        Employe::withoutGlobalScopes()->where('user_id', $this->userId)->update(['acces_suspendu' => true]);

        RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);

        $this->assertSame([], $this->envois);
    }

    /** Une rafale (10 rendez-vous glissés l'un après l'autre) : un seul réveil, 15 s après le premier, qui voit tout */
    public function test_une_rafale_ne_fait_qu_un_reveil_differe_de_15_secondes(): void
    {
        Queue::fake([PrevenirMontres::class]);

        for ($i = 0; $i < 10; $i++) {
            RendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(20)]);
            $this->travel(5)->seconds();
        }

        Queue::assertPushed(PrevenirMontres::class, 1);
        Queue::assertPushed(PrevenirMontres::class, fn (PrevenirMontres $tache): bool => $tache->delay instanceof \DateTimeInterface
            && CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Zurich')->diffInSeconds($tache->delay) >= 15
            // Le verrou tient jusqu'à l'envoi : il dure plus que la pause
            && $tache->uniqueFor > 15);
    }
}
