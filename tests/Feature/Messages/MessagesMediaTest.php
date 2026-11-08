<?php

namespace Tests\Feature\Messages;

use App\Auth\EmetteurJetons;
use App\Events\MessagesChangent;
use App\Jobs\SendPush;
use App\Jobs\TranscribeMessageMedia;
use App\Mail\AlerteTranscription;
use App\Mail\PlafondTranscriptionOrganisation;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\MessageClientele;
use App\Models\MessageMedia;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Services\Messages\MessageMediaService;
use App\Services\Transcription\ConsommationTranscription;
use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\TranscriptionImpossible;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\EnregistrementFactice;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * Messages audio et vidéo façon Telegram (demande de Jim, 04.11.2026 ;
 * feu vert du 06.11.2026) : dans le fil d'un client, la clinique et le
 * propriétaire envoient un message audio ou une vidéo ronde de deux minutes
 * au plus ; l'onde et la durée sont servies avec le message. Le bouton
 * « →A » transcrit un message par Deepgram, une seule fois, compté dans
 * l'utilisation de l'organisation et refacturé comme la transcription des
 * consultations (mêmes plafonds, même budget) ; seulement dans une
 * clinique qui paie, et pour son équipe.
 */
class MessagesMediaTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private TranscripteurFactice $transcripteur;

    private Clinique $clinique;

    private Organisation $organisation;

    private string $accueil;

    private User $proprietaire;

    private string $jetonProprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->app->instance(Transcripteur::class, $this->transcripteur = new TranscripteurFactice);
        $this->transcripteur->duree = 7.4;
        $this->transcripteur->segments = [['voix' => 0, 'debut' => 0.2, 'fin' => 7.0, 'texte' => 'Biscuit se gratte encore l’oreille gauche.']];

        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $this->organisation = $this->clinique->societe->organisation;
        ['jeton' => $this->accueil] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller'], 'asv');
        ['user' => $this->proprietaire, 'jeton' => $this->jetonProprietaire] = $this->proprietaireAvecJeton();
        Client::factory()->create(['user_id' => $this->proprietaire->id, 'organisation_id' => $this->organisation->id, 'prenom' => 'Léa', 'nom' => 'Martin']);
    }

    /** Un enregistrement de structure réelle, de cette durée (lue par le serveur) et de cette taille */
    private function fichier(string $format = 'webm', int $ms = 7400, int $octets = 4000, string $nom = 'message.webm'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, EnregistrementFactice::{$format}($ms, $octets));
    }

    /** @param  array<string, mixed>  $champs */
    private function envoyerCommeClinique(array $champs = [], ?string $jeton = null, ?string $cle = null): TestResponse
    {
        return $this->post('/api/v1/messages/'.($cle ?? 'u'.$this->proprietaire->id).'/media', [
            'kind' => 'audio', 'duration_ms' => 7400, 'peaks' => array_fill(0, 46, 0.5), 'file' => $this->fichier(), ...$champs,
        ], ['Accept' => 'application/json', ...$this->enTetes($jeton ?? $this->accueil)]);
    }

    /** @param  array<string, mixed>  $champs */
    private function envoyerCommeProprietaire(array $champs = []): TestResponse
    {
        return $this->post('/api/v1/espace/messages/media', [
            'clinique_id' => $this->clinique->id, 'kind' => 'audio', 'duration_ms' => 7400, 'peaks' => array_fill(0, 46, 0.5), 'file' => $this->fichier(), ...$champs,
        ], ['Accept' => 'application/json', ...$this->enTetes($this->jetonProprietaire)]);
    }

    private function fil(?string $jeton = null): TestResponse
    {
        return $this->getJson('/api/v1/messages/u'.$this->proprietaire->id, $this->enTetes($jeton ?? $this->accueil));
    }

    private function transcrire(int $mediaId, ?string $jeton = null): TestResponse
    {
        return $this->postJson("/api/v1/messages/media/{$mediaId}/transcription", [], $this->enTetes($jeton ?? $this->accueil));
    }

    public function test_la_clinique_envoie_un_message_audio_que_le_proprietaire_ecoute_avec_son_onde_et_sa_duree(): void
    {
        Event::fake([MessagesChangent::class]);
        $peaks = array_map(fn (int $i): float => round(($i % 10) / 10, 2), range(0, 45));

        $media = $this->envoyerCommeClinique(['peaks' => $peaks])->assertCreated()
            ->assertJsonPath('data.auteur', 'clinique')
            ->assertJsonPath('data.signature', 'Julie Muller')
            ->assertJsonPath('data.media.kind', 'audio')
            ->assertJsonPath('data.media.mime', 'audio/webm')
            ->assertJsonPath('data.media.duration_ms', 7400)
            ->json('data.media');
        $this->assertEquals($peaks, $media['peaks']);
        Storage::disk('prive')->assertExists(MessageMedia::query()->sole()->path);
        // Le texte du message dit ce que c'est (aperçu, e-mail, anciennes versions de l'app)
        $this->assertSame('Message audio', MessageClientele::query()->sole()->texte);
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$this->proprietaire->id);

        $fil = $this->getJson("/api/v1/espace/messages?clinique_id={$this->clinique->id}", $this->enTetes($this->jetonProprietaire))->assertOk();
        $fil->assertJsonPath('data.messages.0.media.kind', 'audio')->assertJsonPath('data.messages.0.media.duration_ms', 7400);
        $this->assertEquals($peaks, $fil->json('data.messages.0.media.peaks'));

        $reponse = $this->get("/api/v1/espace/messages/media/{$media['id']}", $this->enTetes($this->jetonProprietaire))->assertOk();
        $this->assertSame('audio/webm', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x1A\x45\xDF\xA3", $reponse->streamedContent());
    }

    public function test_le_proprietaire_envoie_une_video_ronde_de_l_iphone_que_la_clinique_regarde(): void
    {
        Event::fake([MessagesChangent::class]);

        $id = $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 14000, 'file' => $this->fichier('mp4', 14000, 9000, 'video.mp4')])->assertCreated()
            ->assertJsonPath('data.de_moi', true)
            ->assertJsonPath('data.media.kind', 'video')
            ->assertJsonPath('data.media.mime', 'video/mp4')
            ->json('data.media.id');
        $this->assertSame('Message vidéo', MessageClientele::query()->sole()->texte);
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id);

        $this->fil()->assertOk()->assertJsonPath('data.messages.0.media.kind', 'video')->assertJsonPath('data.messages.0.media.duration_ms', 14000);
        $reponse = $this->get("/api/v1/messages/media/{$id}", $this->enTetes($this->accueil))->assertOk();
        $this->assertSame('video/mp4', $reponse->headers->get('Content-Type'));
    }

    public function test_safari_enregistre_du_mp4_et_chrome_du_webm_les_deux_passent(): void
    {
        $this->envoyerCommeClinique(['file' => $this->fichier('mp4', 7400, 4000, 'audio.m4a')])->assertCreated()->assertJsonPath('data.media.mime', 'audio/mp4');
        $this->envoyerCommeClinique(['file' => $this->fichier()])->assertCreated()->assertJsonPath('data.media.mime', 'audio/webm');
        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('webm', 9000, 9000, 'video.webm')])->assertCreated()->assertJsonPath('data.media.mime', 'video/webm');
    }

    public function test_le_type_reel_du_fichier_compte_pas_son_extension(): void
    {
        // Un texte nommé .webm, un PDF nommé .mp4, un fichier Matroska qui n'est pas du WebM
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('audio.webm', str_repeat('Bonjour ', 600))])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('video.mp4', '%PDF-1.7 '.str_repeat('x', 3000))])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('audio.webm', EnregistrementFactice::webm(7400, 4000, 'matroska'))])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, MessageClientele::query()->count());
        $this->assertSame([], Storage::disk('prive')->allFiles());
    }

    public function test_les_limites_de_duree_de_taille_et_d_onde(): void
    {
        // Décision de Jim (07.11.2026) : audio de 2 minutes au plus ; vidéo de 2 minutes pile au plus (08.11.2026)
        // 2,5 s de tolérance : l'app s'arrête pile à la limite, un onglet caché peut retarder l'arrêt d'une seconde
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 122600, 'file' => $this->fichier('webm', 122600)])->assertUnprocessable()
            ->assertJsonPath('errors.duration_ms.0', 'Une vidéo dure deux minutes au plus.');
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 122400, 'file' => $this->fichier('webm', 122400)])->assertCreated();
        $this->envoyerCommeClinique(['duration_ms' => 122600, 'file' => $this->fichier('webm', 122600)])->assertUnprocessable()->assertJsonValidationErrors('duration_ms')
            ->assertJsonPath('errors.duration_ms.0', 'Un message audio dure deux minutes au plus.');
        $this->envoyerCommeClinique(['duration_ms' => 122400, 'file' => $this->fichier('webm', 122400)])->assertCreated();
        // Une vraie vidéo de deux minutes, au débit de l'app (1 Mbit/s d'image, 64 kbit/s de son : 16 Mo environ),
        // envoyée par un propriétaire en MP4 (Safari, iPhone) comme par la clinique en WebM
        $poids = (int) (120 * (1_000_000 + 64_000) / 8);
        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 120000, 'file' => $this->fichier('mp4', 120000, $poids)])->assertCreated();
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 120000, 'file' => $this->fichier('webm', 120000, $poids)])->assertCreated();
        // Arrêtée pile à deux minutes, un onglet caché la laisse filer un peu : la bulle dit 2:00, jamais 2:01
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 120000, 'file' => $this->fichier('webm', 120600)])->assertCreated()
            ->assertJsonPath('data.media.duration_ms', 120000);
        $this->envoyerCommeClinique(['duration_ms' => 200])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        // Audio de 3 Mo au plus, vidéo de 40 Mo au plus (deux minutes depuis le 08.11.2026)
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 7400, 3 * 1024 * 1024 + 2048)])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('webm', 9000, 40 * 1024 * 1024 + 2048)])->assertUnprocessable()->assertJsonValidationErrors('file');
        // L'onde : des valeurs de 0 à 1, 64 au plus
        $this->envoyerCommeClinique(['peaks' => [0.2, 1.4]])->assertUnprocessable()->assertJsonValidationErrors('peaks.1');
        $this->envoyerCommeClinique(['peaks' => array_fill(0, 65, 0.5)])->assertUnprocessable()->assertJsonValidationErrors('peaks');
        $this->envoyerCommeClinique(['kind' => 'photo'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    /* ————— Vérification du 07.11.2026 ————— */

    public function test_la_vraie_duree_se_lit_dans_le_fichier_et_un_ecart_est_refuse(): void
    {
        // Une heure annoncée comme 7,4 s, en WebM de Chrome et en MP4 fragmenté
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 3600000)])->assertUnprocessable()
            ->assertJsonPath('errors.duration_ms.0', 'La durée annoncée ne correspond pas à l’enregistrement.');
        $this->envoyerCommeProprietaire(['file' => $this->fichier('mp4', 3600000)])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        // Au-delà de la limite, lu dans le fichier même si la durée annoncée la respecte
        $this->envoyerCommeClinique(['duration_ms' => 119000, 'file' => $this->fichier('webm', 126000)])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        // Un fichier dont la durée ne se lit pas
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('a.webm', EnregistrementFactice::webmSansBloc())])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'La durée de cet enregistrement ne se lit pas.');
        $this->assertSame(0, MessageMedia::query()->count());
        $this->assertSame([], Storage::disk('prive')->allFiles());
    }

    public function test_les_formats_de_chrome_safari_et_firefox_se_lisent_et_la_vraie_duree_est_servie(): void
    {
        // Annoncée 7,6 s, l'enregistrement en dure 7,4 : c'est la sienne qui est gardée
        $this->envoyerCommeClinique(['duration_ms' => 7600, 'file' => $this->fichier('webm', 7400)])->assertCreated()->assertJsonPath('data.media.duration_ms', 7400);
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('a.webm', EnregistrementFactice::webm(7400, 4000, 'webm', 7400))])->assertCreated();
        $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('a.mp4', EnregistrementFactice::mp4(7400, 4000, 'M4A ', false))])->assertCreated()->assertJsonPath('data.media.duration_ms', 7400);
        $this->envoyerCommeProprietaire(['file' => $this->fichier('ogg', 7400, 4000, 'a.ogg')])->assertCreated()->assertJsonPath('data.media.mime', 'audio/ogg')->assertJsonPath('data.media.duration_ms', 7400);
    }

    public function test_seules_les_marques_mp4_d_un_enregistrement_passent(): void
    {
        foreach (['heic', 'mif1', 'avif'] as $marque) {
            $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('a.mp4', EnregistrementFactice::mp4(7400, 4000, $marque))])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        foreach (['isom', 'iso5', 'mp42', 'M4A '] as $marque) {
            $this->envoyerCommeClinique(['file' => UploadedFile::fake()->createWithContent('a.mp4', EnregistrementFactice::mp4(7400, 4000, $marque))])->assertCreated();
        }
    }

    public function test_les_fichiers_sont_servis_sans_que_le_navigateur_devine_leur_type(): void
    {
        $id = $this->envoyerCommeClinique()->assertCreated()->json('data.media.id');

        $this->assertSame('nosniff', $this->get("/api/v1/messages/media/{$id}", $this->enTetes($this->accueil))->headers->get('X-Content-Type-Options'));
        $this->assertSame('nosniff', $this->get("/api/v1/espace/messages/media/{$id}", $this->enTetes($this->jetonProprietaire))->headers->get('X-Content-Type-Options'));
    }

    public function test_une_video_ne_se_transcrit_pas(): void
    {
        $id = $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000)])->assertCreated()->json('data.media.id');

        $this->fil()->assertJsonPath('data.messages.0.media.can_transcribe', false);
        $this->transcrire($id)->assertUnprocessable()->assertJsonPath('message', 'Seuls les messages audio se transcrivent.');
        $this->assertSame([], $this->transcripteur->appels);
    }

    public function test_la_duree_facturee_ne_depasse_jamais_la_limite_du_genre(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        // Le fournisseur dit 121,5 s : dans la marge, facturé 120 s ; la plateforme compte les 122 s payées
        $this->transcripteur->duree = 121.5;

        $this->transcrire($id)->assertAccepted();

        $this->assertDatabaseHas('message_media', ['id' => $id, 'transcript_status' => 'done', 'transcript_seconds' => 120, 'provider_seconds' => 122]);
        $this->assertSame(120, app(ConsommationTranscription::class)->secondes());
    }

    /**
     * Deuxième vérification (07.11.2026) : un fichier qui ment sur sa durée
     * est payé à Deepgram pour sa vraie longueur. Le budget de la plateforme
     * la compte ; la clinique n'est pas refacturée, le texte n'est pas gardé,
     * et le message ne repart plus.
     */
    public function test_plus_long_que_permis_chez_deepgram_le_budget_le_compte_la_clinique_ne_paie_pas(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->transcripteur->duree = 3900.0;
        config(['transcription.cout_minute_deepgram_usd' => 1.0]);

        $this->transcrire($id)->assertAccepted();

        $this->assertDatabaseHas('message_media', ['id' => $id, 'transcript_status' => 'failed', 'transcript' => null, 'transcript_seconds' => null, 'provider_seconds' => 3900]);
        $consommation = app(ConsommationTranscription::class);
        $this->assertSame(0, $consommation->secondesDeLaPeriode($this->organisation->refresh()));
        $this->assertSame(65.0, $consommation->depenseDeepgram());
        $this->transcrire($id)->assertUnprocessable();
        $this->assertCount(1, $this->transcripteur->appels);
    }

    public function test_un_fichier_plus_lourd_que_sa_duree_ne_le_permet_est_refuse_a_l_envoi(): void
    {
        // Audio : 64 kbit/s × durée + 16 Ko (l'app enregistre à 32) ; 7,4 s font 75 Ko au plus
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 7400, 80 * 1024)])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Ce fichier est trop lourd pour sa durée.');
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 7400, 70 * 1024)])->assertCreated();
        // Vidéo : 3 Mbit/s × durée + 64 Ko ; 9 s font 3,4 Mo au plus
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('webm', 9000, 5 * 1024 * 1024)])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    /**
     * Le débit permis dépend du format (07.11.2026) : Safari peut ignorer le
     * débit demandé et écrire son AAC à 128 kbit/s ; Chrome et Firefox
     * enregistrent à 32. MP4 : 192 kbit/s ; WebM et Ogg : 64 kbit/s.
     */
    public function test_un_mp4_aac_de_safari_a_128_kbits_passe_le_meme_debit_en_webm_est_refuse(): void
    {
        $octets = 120 * 128000 / 8;
        $this->envoyerCommeProprietaire(['duration_ms' => 120000, 'file' => $this->fichier('mp4', 120000, $octets, 'audio.m4a')])
            ->assertCreated()->assertJsonPath('data.media.mime', 'audio/mp4');
        $this->envoyerCommeProprietaire(['duration_ms' => 120000, 'file' => $this->fichier('webm', 120000, $octets)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Ce fichier est trop lourd pour sa durée.');
        // Bien au-delà de 192 kbit/s (+ 16 Ko), même en MP4 : 10 s à 300 kbit/s
        $this->envoyerCommeProprietaire(['duration_ms' => 10000, 'file' => $this->fichier('mp4', 10000, 10 * 300000 / 8, 'audio.m4a')])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    /** Quatrième vérification : la marge du MP4 suit la durée (la table stsz, 4 octets par trame) */
    public function test_un_vrai_aac_a_192_kbits_de_deux_minutes_passe(): void
    {
        $fichier = EnregistrementFactice::aac(120000, 192);
        $this->assertLessThan(3 * 1024 * 1024, strlen($fichier));

        $this->envoyerCommeProprietaire(['duration_ms' => 120000, 'file' => UploadedFile::fake()->createWithContent('a.m4a', $fichier)])
            ->assertCreated()->assertJsonPath('data.media.mime', 'audio/mp4');
    }

    public function test_une_video_mp4_d_une_boite_geante_ne_fait_pas_monter_la_memoire_de_la_route(): void
    {
        $upload = UploadedFile::fake()->createWithContent('v.mp4', EnregistrementFactice::mp4BoiteGeante('stts', 20 * 1000 * 1000));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $avant = memory_get_usage();

        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $upload])->assertUnprocessable();

        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $avant);
    }

    /**
     * Quatrième vérification : un fichier en silence (DTX, 0,4 kbit/s) peut
     * cacher des heures. Les secondes payées à Deepgram pour les messages
     * d'une organisation sont plafonnées par jour ; au-delà, plus de
     * transcription de messages jusqu'au lendemain.
     */
    public function test_les_secondes_payees_a_deepgram_par_organisation_et_par_jour_sont_plafonnees(): void
    {
        // 3 h par jour (décision de la quatrième vérification)
        $this->assertSame(10800, (int) config('messages.media.daily_provider_seconds'));
        $this->travelTo(now('Europe/Zurich')->setTime(10, 0));
        config(['messages.media.daily_provider_seconds' => 8]);
        $premier = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $second = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');

        $this->transcrire($premier)->assertAccepted();
        $this->transcrire($second)->assertUnprocessable()
            ->assertJsonPath('message', 'La transcription des messages de votre organisation a atteint sa limite du jour (0:00:08 de son). Elle reprend demain.');
        $this->assertCount(1, $this->transcripteur->appels);

        // Le lendemain, elle reprend
        $this->travel(1)->days();
        $this->transcrire($second)->assertAccepted();
        $this->assertCount(2, $this->transcripteur->appels);
    }

    /**
     * Cinquième vérification : le plafond du jour se réserve sous verrou.
     * Trois « →A » partis en même temps (le deuxième pendant l'appel du
     * premier, le troisième pendant celui du deuxième), plafond de 10 s :
     * la somme payée ne dépasse jamais le plafond plus une seule durée.
     */
    public function test_trois_transcriptions_simultanees_ne_depassent_pas_le_plafond_du_jour_de_plus_d_une_duree(): void
    {
        config(['messages.media.daily_provider_seconds' => 10]);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        }
        Queue::fake([TranscribeMessageMedia::class]);
        foreach ($ids as $id) {
            $this->transcrire($id)->assertAccepted();
        }
        Queue::assertPushed(TranscribeMessageMedia::class, 3);
        $suivants = [$ids[1], $ids[2]];
        $this->transcripteur->pendant = function () use (&$suivants): void {
            $suivant = array_shift($suivants);
            if ($suivant !== null) {
                app()->call([new TranscribeMessageMedia($suivant), 'handle']);
            }
        };

        app()->call([new TranscribeMessageMedia($ids[0]), 'handle']);

        $payees = (int) MessageMedia::query()->sum('provider_seconds');
        $this->assertLessThanOrEqual(10 + 8, $payees);
        $this->assertCount(2, $this->transcripteur->appels);
        // Le troisième n'a plus trouvé de reste : refusé, sans rien payer
        $this->assertDatabaseHas('message_media', ['id' => $ids[2], 'transcript_status' => 'failed', 'provider_seconds' => null]);
        $this->assertNotNull(MessageMediaService::dailyRefusal($this->organisation->id));
    }

    public function test_a_la_reponse_la_reservation_du_jour_devient_la_duree_reelle(): void
    {
        config(['messages.media.daily_provider_seconds' => 3600]);
        // 70 Ko : 96 s réservées au débit le plus bas ; Deepgram en traite 7,4
        $id = $this->envoyerCommeProprietaire(['file' => $this->fichier('webm', 7400, 70 * 1024)])->assertCreated()->json('data.media.id');
        $pendant = null;
        $this->transcripteur->pendant = function () use (&$pendant): void {
            $pendant = MessageMediaService::providerSecondsToday($this->organisation->id);
        };

        $this->transcrire($id)->assertAccepted();

        $this->assertSame(96, $pendant);
        $this->assertSame(8, MessageMediaService::providerSecondsToday($this->organisation->id));
        // Un refus net de Deepgram libère la réservation
        $autre = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->transcripteur->pendant = fn () => throw new TranscriptionImpossible('Deepgram refuse la transcription (HTTP 400).');
        $this->transcrire($autre)->assertAccepted();
        $this->assertSame(8, MessageMediaService::providerSecondsToday($this->organisation->id));
    }

    public function test_l_estimation_pendant_l_appel_ne_depasse_pas_ce_qui_reste_du_jour_a_l_organisation(): void
    {
        // 70 Ko au débit le plus bas d'Opus : 96 s estimées ; il reste 20 s à l'organisation aujourd'hui
        config(['messages.media.daily_provider_seconds' => 20, 'transcription.cout_minute_deepgram_usd' => 60.0]);
        $id = $this->envoyerCommeProprietaire(['file' => $this->fichier('webm', 7400, 70 * 1024)])->assertCreated()->json('data.media.id');
        $consommation = app(ConsommationTranscription::class);
        $pendant = null;
        $this->transcripteur->pendant = function () use ($consommation, &$pendant): void {
            $pendant = $consommation->depenseDeepgram();
        };

        $this->transcrire($id)->assertAccepted();

        $this->assertSame(20.0, $pendant);
    }

    public function test_une_duree_forgee_dans_le_fichier_est_refusee(): void
    {
        // Ogg : une fausse dernière page à 0,39 s sur une heure de son
        $this->envoyerCommeClinique(['duration_ms' => 2000, 'file' => UploadedFile::fake()->createWithContent('a.ogg', EnregistrementFactice::ogg(3600000, 20000, EnregistrementFactice::pageOgg(312 + 390 * 48)))])
            ->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        // WebM : tous les blocs au temps 0
        $this->envoyerCommeClinique(['duration_ms' => 2000, 'file' => UploadedFile::fake()->createWithContent('a.webm', EnregistrementFactice::webmBlocsAuTempsZero())])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'La durée de cet enregistrement ne se lit pas.');
        // MP4 : mvhd et mdhd disent 2 s, la table stts une heure
        $this->envoyerCommeClinique(['duration_ms' => 2000, 'file' => UploadedFile::fake()->createWithContent('a.mp4', EnregistrementFactice::mp4Stts(2000, 3600000))])
            ->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        $this->assertSame(0, MessageMedia::query()->count());
    }

    /**
     * Troisième vérification (07.11.2026) : 400 000 blocs, chacun d'une
     * piste différente, faisaient grandir la mémoire jusqu'au débordement (500).
     */
    public function test_un_webm_aux_pistes_innombrables_est_refuse_sans_deborder_la_memoire(): void
    {
        $fichier = EnregistrementFactice::webmPistesInnombrables(400000);
        $this->assertGreaterThan(2700000, strlen($fichier));
        $upload = UploadedFile::fake()->createWithContent('a.webm', $fichier);
        $avant = memory_get_usage();
        memory_reset_peak_usage();

        $this->envoyerCommeProprietaire(['duration_ms' => 2000, 'file' => $upload])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'La durée de cet enregistrement ne se lit pas.');

        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $avant);
        $this->assertSame(0, MessageMedia::query()->count());
    }

    public function test_un_refus_de_deepgram_retire_l_estimation_du_budget(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->transcripteur->refus = new TranscriptionImpossible('Deepgram refuse la transcription (HTTP 400).');
        config(['transcription.cout_minute_deepgram_usd' => 60.0]);
        $consommation = app(ConsommationTranscription::class);
        $pendant = null;
        $this->transcripteur->pendant = function () use ($consommation, &$pendant): void {
            $pendant = $consommation->depenseDeepgram();
        };
        // Le refus arrive pendant l'appel : l'estimation était bien comptée avant
        $this->transcripteur->refus = null;
        $this->transcripteur->pendant = function () use ($consommation, &$pendant): void {
            $pendant = $consommation->depenseDeepgram();
            throw new TranscriptionImpossible('Deepgram refuse la transcription (HTTP 400).');
        };

        $this->transcrire($id)->assertAccepted();

        $this->assertGreaterThan(0, $pendant);

        // Rien n'a été facturé par Deepgram : rien ne reste compté au budget du mois
        $this->assertSame(0.0, app(ConsommationTranscription::class)->depenseDeepgram());
    }

    public function test_un_fichier_efface_avec_le_compte_ne_compte_plus_dans_le_stockage(): void
    {
        $avant = $this->organisation->stockageOctets();
        $this->envoyerCommeProprietaire(['file' => $this->fichier('webm', 7400, 5000)])->assertCreated();
        $this->assertSame($avant + 5000, $this->organisation->refresh()->stockageOctets());

        $this->proprietaire->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertSame($avant, $this->organisation->refresh()->stockageOctets());
    }

    public function test_les_poids_annonces_dans_les_erreurs_sont_ceux_de_la_configuration(): void
    {
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 7400, 3 * 1024 * 1024 + 2048)])->assertJsonPath('errors.file.0', 'Ce message audio est trop lourd (3 Mo au plus).');
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('webm', 9000, 40 * 1024 * 1024 + 2048)])->assertJsonPath('errors.file.0', 'Cette vidéo est trop lourde (40 Mo au plus).');
        config(['messages.media.audio.max_kb' => 1024]);
        $this->envoyerCommeClinique(['file' => $this->fichier('webm', 7400, 1024 * 1024 + 2048)])->assertJsonPath('errors.file.0', 'Ce message audio est trop lourd (1 Mo au plus).');
    }

    public function test_les_quotas_du_proprietaire_se_comptent_sous_verrou(): void
    {
        // Deux envois au même instant : le compte est verrouillé du contrôle à l'écriture
        $source = (string) file_get_contents(app_path('Http/Controllers/Api/V1/Espace/MessageProprietaireController.php'));
        $this->assertMatchesRegularExpression('/DB::transaction\(function \(\).*?lockForUpdate\(\).*?exigerQuota\(.*?->store\(/s', $source);
    }

    public function test_un_fichier_plus_lourd_que_sa_duree_ne_le_permet_n_est_jamais_transcrit(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        MessageMedia::query()->whereKey($id)->update(['size_bytes' => 3 * 1024 * 1024 + 1]);

        $this->transcrire($id)->assertUnprocessable();
        MessageMedia::query()->whereKey($id)->update(['size_bytes' => 4000, 'duration_ms' => 122600]);
        $this->transcrire($id)->assertUnprocessable();

        $this->assertSame([], $this->transcripteur->appels);
    }

    public function test_un_compte_efface_emporte_ses_fichiers_le_texte_du_fil_et_la_transcription_restent(): void
    {
        $audio = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $video = $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000)])->assertCreated()->json('data.media.id');
        $deLaClinique = $this->envoyerCommeClinique()->assertCreated()->json('data.media.id');
        $this->transcrire($audio)->assertAccepted();
        $chemins = MessageMedia::query()->orderBy('id')->pluck('path', 'id');

        $this->proprietaire->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->artisan('comptes:effacer')->assertSuccessful();

        $this->assertFalse(User::query()->whereKey($this->proprietaire->id)->exists());
        Storage::disk('prive')->assertMissing([$chemins[$audio], $chemins[$video]]);
        Storage::disk('prive')->assertExists($chemins[$deLaClinique]);
        $fil = $this->getJson('/api/v1/messages', $this->enTetes($this->accueil))->assertOk()->json('data.0.cle');
        $messages = $this->getJson('/api/v1/messages/'.$fil, $this->enTetes($this->accueil))->assertOk()->json('data.messages');
        $this->assertSame(['Message audio', 'Message vidéo', 'Message audio'], array_column($messages, 'texte'));
        $this->assertTrue($messages[0]['media']['erased']);
        $this->assertTrue($messages[1]['media']['erased']);
        $this->assertFalse($messages[2]['media']['erased']);
        $this->assertSame('Biscuit se gratte encore l’oreille gauche.', $messages[0]['media']['transcript']['text']);
        $this->get("/api/v1/messages/media/{$audio}", ['Accept' => 'application/json', ...$this->enTetes($this->accueil)])->assertStatus(410);
    }

    public function test_le_fichier_ne_s_efface_qu_apres_la_validation_de_la_base(): void
    {
        $this->envoyerCommeProprietaire()->assertCreated();
        $chemin = MessageMedia::query()->sole()->path;

        try {
            DB::transaction(function (): void {
                MessageClientele::query()->sole()->delete();
                throw new \RuntimeException('Annulé');
            });
        } catch (\RuntimeException) {
        }

        Storage::disk('prive')->assertExists($chemin);
        $this->assertSame(1, MessageMedia::query()->count());
        DB::transaction(fn () => MessageClientele::query()->sole()->delete());
        Storage::disk('prive')->assertMissing($chemin);
    }

    public function test_un_proprietaire_envoie_au_plus_dix_videos_par_heure_et_200_mo_par_jour(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000)])->assertCreated();
        }
        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000)])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Dix vidéos par heure au plus : réessayez un peu plus tard.');
        // Un message audio passe encore
        $this->envoyerCommeProprietaire()->assertCreated();
        $this->travel(61)->minutes();
        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000)])->assertCreated();

        // 200 Mo par jour, audio et vidéo ensemble
        MessageMedia::query()->latest('id')->firstOrFail()->update(['size_bytes' => 200 * 1024 * 1024 - 1000]);
        $this->envoyerCommeProprietaire()->assertUnprocessable()->assertJsonPath('errors.file.0', '200 Mo d’envois par jour au plus : réessayez demain.');
    }

    public function test_un_compte_qui_n_est_pas_client_de_l_organisation_ne_recoit_rien(): void
    {
        $this->envoyerCommeClinique([], null, 'u'.User::factory()->create()->id)->assertNotFound();
        $this->assertSame(0, MessageMedia::query()->count());
    }

    /* ————— Entre collègues et entre cliniques (décision de Jim, 07.11.2026) ————— */

    public function test_un_message_audio_a_un_collegue_que_lui_seul_ecoute(): void
    {
        ['employe' => $marc, 'jeton' => $deMarc] = $this->employeAvecJeton($this->clinique, ['nom' => 'Marc Dubois']);
        ['employe' => $julie] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Perret']);
        ['jeton' => $troisieme] = $this->employeAvecJeton($this->clinique, ['nom' => 'Paul Roy']);
        $julieId = $julie->id;
        Queue::fake([SendPush::class]);
        Event::fake([MessagesChangent::class]);

        $id = $this->envoyerCommeClinique([], $deMarc, 'e'.$julieId)->assertCreated()
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.texte', 'Message audio')
            ->assertJsonPath('data.media.kind', 'audio')
            ->assertJsonPath('data.media.can_transcribe', true)
            ->json('data.media.id');
        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->body === 'Message audio');
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$julie->user_id);

        // Julie l'écoute ; un troisième collègue, non
        $jetonJulie = app(EmetteurJetons::class)->emettre($julie->user)['jeton']->plainTextToken;
        $this->getJson('/api/v1/messages/e'.$marc->id, $this->enTetes($jetonJulie))->assertOk()
            ->assertJsonPath('data.messages.0.media.duration_ms', 7400)
            ->assertJsonPath('data.messages.0.media.can_transcribe', true);
        $this->get("/api/v1/messages/media/{$id}", $this->enTetes($jetonJulie))->assertOk();
        $this->get("/api/v1/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($troisieme)])->assertNotFound();
        $this->transcrire($id, $troisieme)->assertNotFound();
    }

    public function test_un_collegue_qui_ne_lit_plus_les_messages_ne_recoit_pas_d_enregistrement(): void
    {
        ['employe' => $compta] = $this->employeAvecJeton($this->clinique, ['nom' => 'Compta']);
        $compta->update(['role_id' => Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->organisation->id, 'nom' => 'Compta', 'domaines' => ['facturation']])->id]);

        $this->envoyerCommeClinique([], null, 'e'.$compta->id)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, MessageMedia::query()->count());
        $this->assertSame([], Storage::disk('prive')->allFiles());
    }

    public function test_une_video_a_une_autre_clinique_lue_par_les_deux_cliniques_du_fil_et_aucune_autre(): void
    {
        $morges = Clinique::factory()->create(['nom' => 'Centre vétérinaire de Morges']);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $ailleurs = Clinique::factory()->create();
        ['jeton' => $deMorges] = $this->employeAvecJeton($morges, ['nom' => 'Julie Perret'], 'asv');
        ['jeton' => $deLaSoeur] = $this->employeAvecJeton($soeur, [], 'asv');
        ['jeton' => $dAilleurs] = $this->employeAvecJeton($ailleurs, [], 'asv');
        Event::fake([MessagesChangent::class]);

        $id = $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 14000, 'file' => $this->fichier('mp4', 14000, 9000, 'video.mp4')], null, 'k'.$morges->id)
            ->assertCreated()->assertJsonPath('data.texte', 'Message vidéo')->assertJsonPath('data.media.kind', 'video')->json('data.media.id');
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$morges->id);

        $this->getJson('/api/v1/messages/k'.$this->clinique->id, $this->enTetes($deMorges))->assertOk()->assertJsonPath('data.messages.0.media.kind', 'video');
        $this->get("/api/v1/messages/media/{$id}", $this->enTetes($deMorges))->assertOk();
        $this->get("/api/v1/messages/media/{$id}", $this->enTetes($this->accueil))->assertOk();
        $this->get("/api/v1/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($deLaSoeur)])->assertNotFound();
        $this->get("/api/v1/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($dAilleurs)])->assertNotFound();
        $this->transcrire($id, $dAilleurs)->assertNotFound();
    }

    public function test_une_clinique_fermee_ne_recoit_pas_d_enregistrement(): void
    {
        $fermee = Clinique::factory()->create();
        $fermee->societe->organisation->forceFill(['statut_abonnement' => null, 'periode_debut' => null, 'periode_fin' => null])->save();

        $this->envoyerCommeClinique([], null, 'k'.$fermee->id)->assertNotFound();
        $this->assertSame(0, MessageMedia::query()->count());
    }

    public function test_entre_cliniques_la_transcription_est_facturee_a_la_clinique_qui_transcrit(): void
    {
        $morges = Clinique::factory()->create();
        ['jeton' => $deMorges] = $this->employeAvecJeton($morges, [], 'asv');
        $id = $this->envoyerCommeClinique([], null, 'k'.$morges->id)->assertCreated()->json('data.media.id');
        Event::fake([MessagesChangent::class]);

        $this->transcrire($id, $deMorges)->assertAccepted();

        $consommation = app(ConsommationTranscription::class);
        $this->assertSame(8, $consommation->secondesDeLaPeriode($morges->societe->organisation->refresh()));
        $this->assertSame(0, $consommation->secondesDeLaPeriode($this->organisation->refresh()));
        foreach ([$this->clinique->id, $morges->id] as $cliniqueId) {
            Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$cliniqueId);
        }
        // L'autre clinique du fil relit le texte sans repayer
        $this->transcrire($id)->assertOk()->assertJsonPath('data.transcript.status', 'done');
        $this->assertCount(1, $this->transcripteur->appels);
    }

    public function test_entre_collegues_la_transcription_est_annoncee_aux_deux(): void
    {
        ['employe' => $julie, 'user' => $compteJulie] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Perret']);
        ['user' => $compteAnna, 'jeton' => $anna] = $this->employeAvecJeton($this->clinique, ['nom' => 'Anna Meyer']);
        $id = $this->envoyerCommeClinique([], $anna, 'e'.$julie->id)->assertCreated()->json('data.media.id');
        Event::fake([MessagesChangent::class]);

        $this->transcrire($id, $anna)->assertAccepted();

        foreach ([$compteJulie->id, $compteAnna->id] as $userId) {
            Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$userId);
        }
        $this->assertSame(8, app(ConsommationTranscription::class)->secondesDeLaPeriode($this->organisation->refresh()));
    }

    /* ————— Fichiers effacés avec leurs messages (données personnelles) ————— */

    public function test_un_message_efface_emporte_son_fichier(): void
    {
        $this->envoyerCommeProprietaire()->assertCreated();
        $chemin = MessageMedia::query()->sole()->path;

        MessageClientele::query()->sole()->delete();

        Storage::disk('prive')->assertMissing($chemin);
        $this->assertSame(0, MessageMedia::query()->count());
    }

    public function test_les_fichiers_des_messages_effaces_en_cascade_s_effacent_la_nuit(): void
    {
        $this->envoyerCommeProprietaire()->assertCreated();
        $garde = MessageMedia::query()->sole()->path;
        $morges = Clinique::factory()->create();
        $this->envoyerCommeClinique([], null, 'k'.$morges->id)->assertCreated();
        $orphelin = MessageMedia::query()->latest('id')->first()->path;

        // La base efface les messages de Morges en cascade (clinique effacée) : aucun code ne passe par le modèle
        DB::table('cliniques')->where('id', $morges->id)->delete();
        $this->assertSame(1, MessageMedia::query()->count());
        Storage::disk('prive')->assertExists($orphelin);
        // Un envoi en cours (fichier rangé, message pas encore écrit) n'est pas touché : seuls les fichiers de plus d'une heure
        Storage::disk('prive')->put('messages/'.$this->clinique->id.'/en-cours.webm', EnregistrementFactice::webm(3000));
        // Les dates des fichiers suivent l'horloge de l'essai (la suite se joue aussi à une autre heure, HORLOGE)
        touch(Storage::disk('prive')->path('messages/'.$this->clinique->id.'/en-cours.webm'), now()->getTimestamp());
        foreach ([$garde, $orphelin] as $chemin) {
            touch(Storage::disk('prive')->path($chemin), now()->getTimestamp() - 7200);
        }

        $this->artisan('messages:purge-media')->expectsOutput('1 fichier(s) effacé(s).')->assertSuccessful();

        Storage::disk('prive')->assertMissing($orphelin);
        Storage::disk('prive')->assertExists($garde);
        Storage::disk('prive')->assertExists('messages/'.$this->clinique->id.'/en-cours.webm');
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($e): bool => str_contains((string) $e->command, 'messages:purge-media')));
    }

    public function test_les_e_mails_de_plafond_parlent_des_consultations_et_des_messages(): void
    {
        $plafond = new PlafondTranscriptionOrganisation(100, 200.0, 200.0, 'Deux Rives');
        $this->assertSame('Transcription des consultations et des messages : plafond atteint', $plafond->envelope()->subject);
        $this->assertSame('Transcription des consultations et des messages : 80 % du plafond', (new PlafondTranscriptionOrganisation(80, 160.0, 200.0, 'Deux Rives'))->envelope()->subject);
        $this->assertStringContainsString('Transcription des consultations et des messages', $plafond->render());
        $this->assertStringContainsString('Transcription des consultations et des messages', (new AlerteTranscription(100, 52.0, 50.0, 8000))->render());
    }

    public function test_le_proprietaire_ne_peut_pas_envoyer_si_la_clinique_ne_recoit_pas_de_messages(): void
    {
        $this->clinique->forceFill(['messages_recus' => false])->save();

        $this->envoyerCommeProprietaire()->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->assertSame(0, MessageClientele::query()->count());

        $ailleurs = Clinique::factory()->create();
        $this->envoyerCommeProprietaire(['clinique_id' => $ailleurs->id])->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
    }

    public function test_isolement_une_clinique_soeur_un_autre_proprietaire_ou_une_autre_organisation_ne_lisent_pas_le_fichier(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $autreOrganisation = Clinique::factory()->create();
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');

        ['jeton' => $deLaSoeur] = $this->employeAvecJeton($soeur, [], 'asv');
        $this->get("/api/v1/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($deLaSoeur)])->assertNotFound();
        $this->transcrire($id, $deLaSoeur)->assertNotFound();

        ['jeton' => $ailleurs] = $this->employeAvecJeton($autreOrganisation, [], 'asv');
        $this->get("/api/v1/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($ailleurs)])->assertNotFound();

        ['jeton' => $autreCompte] = $this->proprietaireAvecJeton();
        $this->get("/api/v1/espace/messages/media/{$id}", ['Accept' => 'application/json', ...$this->enTetes($autreCompte)])->assertNotFound();
        $this->assertSame([], $this->transcripteur->appels);
    }

    public function test_la_transcription_par_deepgram_une_seule_fois_relue_sans_repayer_et_annoncee_en_temps_reel(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->fil()->assertJsonPath('data.messages.0.media.can_transcribe', true)->assertJsonPath('data.messages.0.media.transcript', null);
        Event::fake([MessagesChangent::class]);

        $this->transcrire($id)->assertAccepted();

        $this->assertCount(1, $this->transcripteur->appels);
        $this->assertSame('audio/webm', $this->transcripteur->appels[0]['format']);
        Event::assertDispatched(MessagesChangent::class, fn (MessagesChangent $e): bool => $e->broadcastOn()[0]->name === 'private-clinique.'.$this->clinique->id
            && ! str_contains((string) json_encode($e->broadcastWith()), 'oreille'));
        $this->fil()->assertJsonPath('data.messages.0.media.transcript.status', 'done')
            ->assertJsonPath('data.messages.0.media.transcript.text', 'Biscuit se gratte encore l’oreille gauche.');

        // Relue ensuite : rien ne repart chez Deepgram
        $this->transcrire($id)->assertOk()->assertJsonPath('data.transcript.text', 'Biscuit se gratte encore l’oreille gauche.');
        $this->assertCount(1, $this->transcripteur->appels);

        // Le texte est chiffré en base ; l'audio du message reste (c'est le message)
        $this->assertStringNotContainsString('oreille', (string) DB::table('message_media')->value('transcript'));
        Storage::disk('prive')->assertExists(MessageMedia::query()->sole()->path);
    }

    public function test_la_transcription_est_comptee_dans_l_utilisation_avec_les_consultations(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $consommation = app(ConsommationTranscription::class);
        $avant = $consommation->secondesDeLaPeriode($this->organisation);

        $this->transcrire($id)->assertAccepted();

        // 7,4 s transcrites : 8 s comptées, comme une partie de consultation
        $this->assertSame($avant + 8, $consommation->secondesDeLaPeriode($this->organisation->refresh()));
        $this->assertSame(8, $consommation->secondes());
        $this->assertDatabaseHas('message_media', ['id' => $id, 'transcript_seconds' => 8, 'transcript_organisation_id' => $this->organisation->id]);
        // Les minutes dans les réglages de la transcription
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);
        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($admin))->assertOk()->assertJsonPath('data.minutes_periode', 1);
    }

    public function test_l_essai_est_compte_au_budget_deepgram_avant_l_envoi(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $consommation = app(ConsommationTranscription::class);
        $pendant = null;
        $this->transcripteur->pendant = function () use ($consommation, &$pendant): void {
            $pendant = $consommation->depenseDeepgram();
        };
        config(['transcription.cout_minute_deepgram_usd' => 60.0]);

        $this->transcrire($id)->assertAccepted();

        // Pendant l'appel : la durée estimée est déjà comptée ; après : la durée réelle seule
        $this->assertGreaterThan(0, $pendant);
        $this->assertSame(8.0, $consommation->depenseDeepgram());
    }

    public function test_les_plafonds_et_le_budget_de_la_transcription_des_consultations_tiennent(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');

        $this->organisation->forceFill(['plafond_transcription' => 0])->save();
        $this->transcrire($id)->assertUnprocessable()->assertJsonPath('message', 'Le plafond de transcription de votre organisation est atteint pour cette période. Un administrateur peut le relever dans les réglages de l’organisation.');

        $this->organisation->forceFill(['plafond_transcription' => 20000])->save();
        config(['transcription.budget_deepgram_usd' => 0.0]);
        $this->transcrire($id)->assertUnprocessable()->assertJsonPath('message', 'La transcription est momentanément indisponible. Réessayez plus tard.');

        $this->assertSame([], $this->transcripteur->appels);
    }

    public function test_seule_une_clinique_qui_paie_transcrit(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');

        $this->organisation->forceFill(['statut_abonnement' => 'impaye'])->save();
        $this->fil()->assertJsonPath('data.messages.0.media.can_transcribe', false);
        $this->transcrire($id)->assertUnprocessable();

        $this->organisation->forceFill(['statut_abonnement' => 'actif', 'identite_avant_le' => now()->addDays(3)])->save();
        $this->fil()->assertJsonPath('data.messages.0.media.can_transcribe', false);
        $this->transcrire($id)->assertUnprocessable();

        $this->assertSame([], $this->transcripteur->appels);
    }

    public function test_un_message_deja_transcrit_se_relit_meme_quand_la_clinique_ne_peut_plus_transcrire(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->transcrire($id)->assertAccepted();

        $this->organisation->forceFill(['statut_abonnement' => 'impaye'])->save();

        $this->fil()->assertJsonPath('data.messages.0.media.can_transcribe', true);
        $this->transcrire($id)->assertOk()->assertJsonPath('data.transcript.status', 'done');
        $this->assertCount(1, $this->transcripteur->appels);
    }

    public function test_le_proprietaire_n_a_pas_la_transcription(): void
    {
        $id = $this->envoyerCommeClinique()->assertCreated()->json('data.media.id');
        $this->transcrire($id)->assertAccepted();

        $media = $this->getJson("/api/v1/espace/messages?clinique_id={$this->clinique->id}", $this->enTetes($this->jetonProprietaire))->json('data.messages.0.media');
        $this->assertArrayNotHasKey('transcript', $media);
        $this->assertArrayNotHasKey('can_transcribe', $media);
        // Sa route n'existe pas, et celle de la clinique lui est fermée (jeton sans clinique)
        $this->postJson("/api/v1/espace/messages/media/{$id}/transcription", [], $this->enTetes($this->jetonProprietaire))->assertNotFound();
        $this->postJson("/api/v1/messages/media/{$id}/transcription", [], $this->enTetes($this->jetonProprietaire))->assertForbidden();
    }

    public function test_un_echec_de_deepgram_se_relance_trois_fois_au_plus(): void
    {
        $id = $this->envoyerCommeProprietaire()->assertCreated()->json('data.media.id');
        $this->transcripteur->refus = new TranscriptionImpossible('Audio illisible.');

        $this->transcrire($id)->assertAccepted();
        $this->fil()->assertJsonPath('data.messages.0.media.transcript.status', 'failed')->assertJsonPath('data.messages.0.media.transcript.text', null);
        $this->transcrire($id)->assertAccepted();
        $this->transcrire($id)->assertAccepted();
        $this->transcrire($id)->assertUnprocessable();

        $this->assertCount(3, $this->transcripteur->appels);
        $this->assertSame(0, app(ConsommationTranscription::class)->secondes());
    }

    public function test_les_fichiers_des_messages_comptent_dans_le_stockage(): void
    {
        $avant = $this->organisation->stockageOctets();

        $this->envoyerCommeProprietaire(['file' => $this->fichier('webm', 7400, 5000)])->assertCreated();

        $this->assertSame($avant + 5000, $this->organisation->refresh()->stockageOctets());
    }

    /** Un JPEG de la caméra, avec un bloc EXIF qui ne doit jamais être rangé */
    private static function premiereImage(): UploadedFile
    {
        $im = imagecreatetruecolor(480, 480);
        ob_start();
        imagejpeg($im);
        $jpg = (string) ob_get_clean();
        $app1 = "Exif\0\0".'II*'."\0".pack('V', 8).pack('v', 0).pack('V', 0).'GPS 46.5N';

        return UploadedFile::fake()->createWithContent('poster.jpg', substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2));
    }

    public function test_une_video_part_avec_sa_premiere_image_rangee_sans_metadonnees_et_servie_a_ceux_du_fil(): void
    {
        $media = $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000), 'poster' => self::premiereImage()])
            ->assertCreated()->assertJsonPath('data.media.has_poster', true)->json('data.media');

        $poster = MessageMedia::query()->sole()->poster_path;
        $this->assertNotNull($poster);
        $this->assertStringNotContainsString('GPS 46.5N', Storage::disk('prive')->get($poster));
        $this->get('/api/v1/messages/media/'.$media['id'].'/apercu', $this->enTetes($this->accueil))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/api/v1/espace/messages/media/'.$media['id'].'/apercu', $this->enTetes($this->jetonProprietaire))->assertOk();
        ['jeton' => $autre] = $this->proprietaireAvecJeton();
        $this->get('/api/v1/espace/messages/media/'.$media['id'].'/apercu', $this->enTetes($autre))->assertNotFound();

        // Effacé par le modèle : la vidéo et sa première image partent
        $chemin = MessageMedia::query()->sole()->path;
        MessageMedia::query()->sole()->message->delete();
        Storage::disk('prive')->assertMissing([$chemin, $poster]);
    }

    public function test_une_image_illisible_n_empeche_pas_la_video_un_audio_n_a_pas_de_premiere_image(): void
    {
        $this->envoyerCommeClinique(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000), 'poster' => UploadedFile::fake()->createWithContent('poster.jpg', 'pas une image')])
            ->assertCreated()->assertJsonPath('data.media.has_poster', false);
        $this->get('/api/v1/messages/media/'.MessageMedia::query()->sole()->id.'/apercu', $this->enTetes($this->accueil))->assertNotFound();

        $this->envoyerCommeClinique(['poster' => self::premiereImage()])->assertUnprocessable()->assertJsonValidationErrors('poster');
    }

    public function test_un_compte_efface_emporte_aussi_la_premiere_image_de_ses_videos_et_la_purge_la_garde_tant_que_la_video_est_la(): void
    {
        $this->envoyerCommeProprietaire(['kind' => 'video', 'duration_ms' => 9000, 'file' => $this->fichier('mp4', 9000), 'poster' => self::premiereImage()])->assertCreated();
        $media = MessageMedia::query()->sole();
        foreach ([$media->path, $media->poster_path] as $chemin) {
            touch(Storage::disk('prive')->path($chemin), now()->getTimestamp() - 7200);
        }
        $this->artisan('messages:purge-media')->expectsOutput('0 fichier(s) effacé(s).')->assertSuccessful();
        Storage::disk('prive')->assertExists($media->poster_path);

        $this->proprietaire->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->artisan('comptes:effacer')->assertSuccessful();
        Storage::disk('prive')->assertMissing([$media->path, $media->poster_path]);
        $this->assertNull($media->fresh()?->poster_path);
    }
}
