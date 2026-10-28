<?php

namespace Tests\Feature\Tele;

use App\Auth\ContexteClinique;
use App\Events\SignalVisio;
use App\Mail\LienVisio;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SessionTeleconsultation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Téléconsultation maison en WebRTC (décision de Jim, 28.10.2026) : la
 * mise en relation passe par l'API et Reverb, réservée au vétérinaire de la
 * clinique et au client de la session (lien reçu par e-mail, sans compte) ;
 * STUN et TURN de Cloudflare, identifiants TURN temporaires.
 */
class VisioWebRtcTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private SessionTeleconsultation $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch']);
        $patient = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $client->id, 'nom' => 'Félix']);
        $id = $this->postJson('/api/v1/tele/sessions', ['patient_id' => $patient->id, 'motif' => 'Contrôle de plaie'], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->session = SessionTeleconsultation::withoutGlobalScopes()->findOrFail($id);
    }

    private function avecReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
    }

    private function lien(): string
    {
        Mail::fake();
        $url = $this->postJson("/api/v1/tele/sessions/{$this->session->id}/lien", [], $this->enTetes($this->jeton))->assertOk()->json('data.url');

        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    private function signalVeterinaire(string $type, array $donnees = []): TestResponse
    {
        return $this->postJson("/api/v1/tele/sessions/{$this->session->id}/signal", ['type' => $type, 'donnees' => $donnees], $this->enTetes($this->jeton));
    }

    public function test_le_lien_part_par_e_mail_au_client_de_la_session(): void
    {
        Mail::fake();

        $url = $this->postJson("/api/v1/tele/sessions/{$this->session->id}/lien", [], $this->enTetes($this->jeton))->assertOk()->json('data.url');

        $this->assertMatchesRegularExpression('#/visio/[A-Za-z0-9]{48}$#', $url);
        Mail::assertSent(LienVisio::class, fn (LienVisio $mail) => $mail->hasTo('lea.martin@example.ch') && str_contains($mail->render(), $url) && str_contains($mail->render(), 'Deux Rives'));
    }

    public function test_le_client_ouvre_sa_session_par_le_lien_sans_compte(): void
    {
        $jeton = $this->lien();

        $this->getJson("/api/v1/public/visio/{$jeton}")->assertOk()
            ->assertJsonPath('data.clinique', 'Deux Rives')
            ->assertJsonPath('data.patient', 'Félix')
            ->assertJsonPath('data.session_id', $this->session->id)
            ->assertJsonPath('data.terminee', false);
        $this->getJson('/api/v1/public/visio/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_un_signal_du_veterinaire_est_diffuse_au_canal_de_la_session(): void
    {
        Event::fake([SignalVisio::class]);

        $this->signalVeterinaire('offre', ['type' => 'offer', 'sdp' => "v=0\r\no=- 1 2 IN IP4 127.0.0.1"])->assertAccepted();

        Event::assertDispatched(SignalVisio::class, fn (SignalVisio $e): bool => $e->broadcastOn()[0]->name === 'private-visio.'.$this->session->id
            && $e->broadcastAs() === 'signal'
            && $e->broadcastWith()['de'] === 'veterinaire'
            && $e->broadcastWith()['type'] === 'offre'
            && $e->broadcastWith()['donnees']['type'] === 'offer');
    }

    public function test_un_signal_du_client_passe_par_son_lien(): void
    {
        $jeton = $this->lien();
        Event::fake([SignalVisio::class]);

        $this->postJson("/api/v1/public/visio/{$jeton}/signal", ['type' => 'reponse', 'donnees' => ['type' => 'answer', 'sdp' => 'v=0']])->assertAccepted();

        Event::assertDispatched(SignalVisio::class, fn (SignalVisio $e): bool => $e->broadcastWith()['de'] === 'client' && $e->broadcastWith()['type'] === 'reponse');
        $this->postJson("/api/v1/public/visio/{$jeton}/signal", ['type' => 'autre', 'donnees' => []])->assertUnprocessable();
    }

    /** Reverb refuse les messages de plus de 10 Ko : un gros signal (SDP) passe par une référence, relue par l'API. */
    public function test_un_gros_signal_passe_par_une_reference(): void
    {
        $jeton = $this->lien();
        Event::fake([SignalVisio::class]);
        $sdp = str_repeat("a=candidate:1 1 udp 2122260223 192.168.1.10 54321 typ host\r\n", 200);

        $this->signalVeterinaire('offre', ['type' => 'offer', 'sdp' => $sdp])->assertAccepted();

        $reference = null;
        Event::assertDispatched(SignalVisio::class, function (SignalVisio $e) use (&$reference): bool {
            $reference = $e->broadcastWith()['reference'] ?? null;

            return $reference !== null && ! array_key_exists('donnees', $e->broadcastWith()) && strlen((string) json_encode($e->broadcastWith())) < 1000;
        });
        $this->getJson("/api/v1/public/visio/{$jeton}/signaux/{$reference}")->assertOk()->assertJsonPath('data.sdp', $sdp);
    }

    public function test_le_canal_de_la_session_est_reserve_au_veterinaire_et_au_client(): void
    {
        $this->avecReverb();
        $jeton = $this->lien();
        $canal = 'private-visio.'.$this->session->id;
        app(ContexteClinique::class)->oublier();
        ['jeton' => $ailleurs] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $this->enTetes($this->jeton))->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $this->enTetes($ailleurs))->assertForbidden();
        $this->postJson("/api/v1/public/visio/{$jeton}/canal", ['socket_id' => '1234.5678', 'channel_name' => $canal])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson("/api/v1/public/visio/{$jeton}/canal", ['socket_id' => '1234.5678', 'channel_name' => 'private-clinique.'.$this->clinique->id])->assertForbidden();
    }

    public function test_sans_cle_turn_seulement_le_stun_de_cloudflare(): void
    {
        config(['services.cloudflare_turn.key_id' => null, 'services.cloudflare_turn.api_token' => null]);

        $this->getJson("/api/v1/tele/sessions/{$this->session->id}/ice", $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.ice_servers', [['urls' => ['stun:stun.cloudflare.com:3478']]]);
    }

    public function test_avec_la_cle_cloudflare_des_identifiants_turn_temporaires(): void
    {
        config(['services.cloudflare_turn.key_id' => 'cle-turn', 'services.cloudflare_turn.api_token' => 'jeton-turn']);
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => [['urls' => ['stun:stun.cloudflare.com:3478', 'turn:turn.cloudflare.com:3478?transport=udp'], 'username' => 'u', 'credential' => 'c']]], 201)]);
        $jeton = $this->lien();

        $this->getJson("/api/v1/public/visio/{$jeton}/ice")->assertOk()->assertJsonPath('data.ice_servers.0.username', 'u');

        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/v1/turn/keys/cle-turn/credentials/generate-ice-servers')
            && $requete->hasHeader('Authorization', 'Bearer jeton-turn')
            && $requete['ttl'] <= 4 * 3600);
    }

    public function test_un_appel_termine_n_accepte_plus_de_signal(): void
    {
        $jeton = $this->lien();
        $this->session->forceFill(['terminee_le' => now()])->save();

        $this->signalVeterinaire('offre', ['type' => 'offer', 'sdp' => 'v=0'])->assertStatus(410);
        $this->postJson("/api/v1/public/visio/{$jeton}/signal", ['type' => 'pret', 'donnees' => []])->assertStatus(410);
        $this->getJson("/api/v1/public/visio/{$jeton}")->assertOk()->assertJsonPath('data.terminee', true);
    }

    /** « Terminer » ou « Quitter » chez le vétérinaire : la page du client l'apprend aussitôt. */
    public function test_la_fin_de_l_appel_est_diffusee_au_client(): void
    {
        Event::fake([SignalVisio::class]);

        $this->postJson("/api/v1/tele/sessions/{$this->session->id}/terminer", ['cloturer' => false], $this->enTetes($this->jeton))->assertOk();

        Event::assertDispatched(SignalVisio::class, fn (SignalVisio $e): bool => $e->broadcastOn()[0]->name === 'private-visio.'.$this->session->id
            && $e->broadcastWith()['de'] === 'veterinaire' && $e->broadcastWith()['type'] === 'fin');
    }

    /** Un appel abandonné (onglet fermé sans « Terminer ») : le lien ne sert plus après quelques heures. */
    public function test_le_lien_ne_sert_plus_apres_l_appel(): void
    {
        $jeton = $this->lien();
        $this->session->forceFill(['demarre_le' => now()->subHours(SessionTeleconsultation::HEURES_LIEN + 1)])->save();

        $this->getJson("/api/v1/public/visio/{$jeton}")->assertOk()->assertJsonPath('data.terminee', true);
        $this->getJson("/api/v1/public/visio/{$jeton}/ice")->assertStatus(410);
        $this->postJson("/api/v1/public/visio/{$jeton}/signal", ['type' => 'pret', 'donnees' => []])->assertStatus(410);
    }

    /** Le lien est secret : seule son empreinte est en base, et renvoyer l'e-mail redonne le même lien. */
    public function test_le_lien_est_garde_en_empreinte_et_reste_le_meme(): void
    {
        $premier = $this->lien();
        $second = $this->lien();

        $this->assertSame($premier, $second);
        $this->assertSame(hash('sha256', $premier), $this->session->refresh()->jeton_client);
        $this->assertStringNotContainsString($premier, (string) json_encode($this->getJson("/api/v1/tele/sessions/{$this->session->id}", $this->enTetes($this->jeton))->json()));
    }

    /* ---------- Deuxième passe (28.10.2026) : propriétés de sécurité prouvées ---------- */

    private function autreSession(): SessionTeleconsultation
    {
        $patient = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $this->session->patient->client_id]);
        $id = $this->postJson('/api/v1/tele/sessions', ['patient_id' => $patient->id, 'motif' => 'Autre'], $this->enTetes($this->jeton))->assertCreated()->json('data.id');

        return SessionTeleconsultation::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_le_lien_d_une_session_n_ouvre_pas_le_canal_d_une_autre(): void
    {
        $this->avecReverb();
        $jeton = $this->lien();
        $autre = $this->autreSession();

        $this->postJson("/api/v1/public/visio/{$jeton}/canal", ['socket_id' => '1234.5678', 'channel_name' => 'private-visio.'.$autre->id])->assertForbidden();
    }

    public function test_le_canal_de_la_session_demande_le_droit_planning(): void
    {
        $this->avecReverb();
        ['employe' => $employe, 'jeton' => $sansPlanning] = $this->employeAvecJeton($this->clinique);
        $role = Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Stocks seulement', 'domaines' => ['stocks']]);
        $employe->update(['role_id' => $role->id]);

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-visio.'.$this->session->id], $this->enTetes($sansPlanning))->assertForbidden();
    }

    public function test_une_reference_ne_se_relit_pas_depuis_une_autre_session(): void
    {
        Event::fake([SignalVisio::class]);
        $this->signalVeterinaire('offre', ['type' => 'offer', 'sdp' => str_repeat('a=x'.PHP_EOL, 3000)])->assertAccepted();
        $reference = null;
        Event::assertDispatched(SignalVisio::class, function (SignalVisio $e) use (&$reference): bool {
            $reference = $e->broadcastWith()['reference'] ?? null;

            return $reference !== null;
        });
        $autre = $this->autreSession();
        $jetonAutre = basename((string) parse_url($this->postJson("/api/v1/tele/sessions/{$autre->id}/lien", [], $this->enTetes($this->jeton))->json('data.url'), PHP_URL_PATH));

        $this->getJson("/api/v1/public/visio/{$jetonAutre}/signaux/{$reference}")->assertNotFound();
    }

    public function test_le_canal_public_se_ferme_avec_l_appel_et_refuse_un_socket_malforme(): void
    {
        $this->avecReverb();
        $jeton = $this->lien();
        $canal = 'private-visio.'.$this->session->id;

        $this->postJson("/api/v1/public/visio/{$jeton}/canal", ['socket_id' => 'pas-un-socket', 'channel_name' => $canal])->assertForbidden();
        $this->session->forceFill(['terminee_le' => now()])->save();
        $this->postJson("/api/v1/public/visio/{$jeton}/canal", ['socket_id' => '1234.5678', 'channel_name' => $canal])->assertStatus(410);
    }
}
