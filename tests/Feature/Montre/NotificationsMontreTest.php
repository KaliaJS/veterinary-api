<?php

namespace Tests\Feature\Montre;

use App\Models\AppareilNotification;
use App\Models\Clinique;
use App\Models\RendezVous;
use App\Services\Notifications\CanalApns;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
