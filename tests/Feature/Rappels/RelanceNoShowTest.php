<?php

namespace Tests\Feature\Rappels;

use App\Enums\StatutRendezVous;
use App\Mail\RelanceNoShow;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FauxTwilio;
use Tests\TestCase;

/**
 * « Relance après no-show » (décision de Jim, 28.10.2026) : après une
 * absence non excusée, le soir même, un message avec un lien pour
 * reprendre rendez-vous, par le canal choisi par le client.
 */
class RelanceNoShowTest extends TestCase
{
    use EmetDesJetonsApi;
    use FauxTwilio;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    private RendezVous $rendezVous;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fauxTwilio();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:30', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'tel' => '032 000 00 00', 'fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '079 123 45 67']);
        $patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        $this->rendezVous = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMinutes(30), 'ends_at' => now()]);
        $this->postJson("/api/v1/rendez-vous/{$this->rendezVous->id}/non-venu", [], $this->enTetes($this->jeton))->assertOk();
    }

    private function relanceActive(): void
    {
        $this->putJson('/api/v1/rappels/reglages', ['relance_noshow' => true], $this->enTetes($this->jeton))->assertOk();
    }

    private function soir(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 18:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    public function test_le_soir_meme_le_proprietaire_recoit_un_lien_pour_reprendre_rendez_vous(): void
    {
        $this->relanceActive();
        $this->assertSame(StatutRendezVous::Noshow, $this->rendezVous->refresh()->status);

        $this->soir();

        Mail::assertSent(RelanceNoShow::class, fn (RelanceNoShow $mail): bool => $mail->hasTo('lea.martin@example.ch')
            && str_contains($mail->render(), 'Félix')
            && str_contains($mail->render(), 'Deux Rives')
            && str_contains($mail->render(), rtrim((string) config('app.frontend_url'), '/').'/cliniques/'.$this->clinique->id));
    }

    public function test_pas_avant_le_soir(): void
    {
        $this->relanceActive();

        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    public function test_relance_coupee_rien_ne_part(): void
    {
        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    public function test_une_seule_fois(): void
    {
        $this->relanceActive();

        $this->soir();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertSent(RelanceNoShow::class, 1);
    }

    public function test_par_sms_quand_le_client_l_a_choisi(): void
    {
        $this->relanceActive();
        $this->putJson('/api/v1/rappels/reglages', ['sms' => true], $this->enTetes($this->jeton))->assertOk();
        $this->client->update(['rappels_sms' => true]);

        $this->soir();

        $sms = array_values(array_filter($this->requetesTwilio, fn (array $r): bool => str_ends_with($r['url'], '/Messages.json')));
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('Félix', $sms[0]['data']['Body']);
        $this->assertStringContainsString('/cliniques/'.$this->clinique->id, $sms[0]['data']['Body']);
        $this->assertLessThanOrEqual(160, mb_strlen($sms[0]['data']['Body']));
    }

    public function test_un_rendez_vous_honore_n_est_pas_relance(): void
    {
        $this->relanceActive();
        $this->rendezVous->update(['status' => StatutRendezVous::Fini]);

        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }
}
