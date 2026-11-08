<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Enums\VerificationOrganisation;
use App\Events\VideoUpdated;
use App\Jobs\AssembleVideoDownload;
use App\Jobs\PurgeVideoFolder;
use App\Jobs\RecheckVideoFiles;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\PlusPoolShare;
use App\Models\SellerAccountClosure;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use App\Services\Comptes\SuppressionCompte;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\PlusPool;
use App\Services\Videos\SellerlessVideos;
use App\Services\Videos\SellerTerms;
use App\Services\Videos\VideoPlayback;
use App\Services\Videos\VideoPublisher;
use App\Services\Videos\VideoStorage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Vidéos de formation, les garde-fous relevés par la vérification
 * indépendante du 31.10.2026 : vendeur qui n'est plus vérifié,
 * organisation fermée, brouillon caché, adresses du bucket, fichiers
 * remplacés après le contrôle, place réelle, tâches planifiées,
 * certificat sans regarder, paiement d'un vieil onglet.
 */
class VideoSafeguardsTest extends TestCase
{
    use BuildsHlsPackages;
    use EmetDesJetonsApi;
    use PublishesVideos;
    use RefreshDatabase;

    private PasserellePaiementFactice $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->gateway = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->gateway);
    }

    private function base(string $id): string
    {
        return "/api/v1/espace/videos/seller/videos/{$id}";
    }

    private function play(string $jeton, string $id): TestResponse
    {
        return $this->postJson("/api/v1/espace/videos/{$id}/play", [], $this->enTetes($jeton));
    }

    private function progress(string $jeton, string $id, array $ranges): TestResponse
    {
        return $this->postJson("/api/v1/espace/videos/{$id}/progress", ['ranges' => $ranges], $this->enTetes($jeton));
    }

    /** Une clinique qui encaisse en ligne, et son administrateur vérifié */
    private function clinicSeller(): array
    {
        $clinique = Clinique::factory()->create();
        ['user' => $admin, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $admin->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $clinique->societe->forceFill(['stripe_compte_id' => 'acct_societe_'.$clinique->id, 'paiements_actifs' => true])->save();
        // Les conditions de vente des vidéos déjà acceptées pour l'organisation (leur propre essai les prend sans)
        $clinique->societe->organisation->forceFill(['video_terms_version' => SellerTerms::VERSION, 'video_terms_accepted_at' => now()])->save();

        return ['clinique' => $clinique, 'jeton' => $jeton];
    }

    // ————— Vendeurs (cases 1 et 2) —————

    public function test_un_veterinaire_qui_n_est_plus_verifie_n_envoie_plus_ne_publie_plus_et_ne_vend_plus(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published(vet: $vet);
        $draft = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $draft, $this->hlsPackage());
        $this->finish($vet['jeton'], $draft)->assertOk();
        $this->fillSheet($this->base($draft), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $owner = $this->proprietaireAvecJeton();

        $vet['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();

        $this->postJson($this->base($draft).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 100], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('seller');
        $this->postJson($this->base($draft).'/publish', [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('seller');
        // Ce qu'il avait déjà en vente ne s'achète plus
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('purchase');
        $this->assertSame([], $this->gateway->intentions);
    }

    public function test_une_video_de_clinique_se_vend_sur_le_compte_de_la_societe_tant_que_l_organisation_est_ouverte(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton));
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.status', 'published');

        $owner = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$id}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk()
            ->assertJsonPath('data.account', 'acct_societe_'.$clinique->id);
        $charge = collect($this->gateway->intentions)->sole();
        $this->assertSame('acct_societe_'.$clinique->id, $charge['compte']);
        $this->assertSame(600, $charge['commission']);

        // Organisation fermée : plus de vente
        $clinique->societe->organisation->forceFill(['verification' => VerificationOrganisation::Refusee])->save();
        $other = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$id}/purchase", ['accept_terms' => true], $this->enTetes($other['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('purchase');
    }

    public function test_les_videos_d_une_clinique_comptent_dans_le_stockage_de_l_organisation(): void
    {
        ['clinique' => $clinique] = $this->clinicSeller();
        $organisation = $clinique->societe->organisation;
        $before = $organisation->stockageOctets();
        Video::factory()->create(['seller_user_id' => null, 'seller_clinique_id' => $clinique->id, 'organisation_id' => $organisation->id, 'size_bytes' => 5 * 1000 ** 3]);

        $this->assertSame($before + 5 * 1000 ** 3, $organisation->refresh()->stockageOctets());
    }

    // ————— Mise en ligne (cases 4, 5, 7, 8, 9) —————

    public function test_un_brouillon_complet_reste_invisible_et_ne_se_regarde_ni_ne_s_achete(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'both'], $this->enTetes($vet['jeton']));
        $owner = $this->proprietaireAvecJeton();
        $otherVet = $this->verifiedVet();

        foreach ([$owner, $otherVet] as $someone) {
            $this->getJson('/api/v1/espace/videos', $this->enTetes($someone['jeton']))->assertOk()->assertJsonCount(0, 'data');
            $this->getJson("/api/v1/espace/videos/{$id}", $this->enTetes($someone['jeton']))->assertNotFound();
            $this->play($someone['jeton'], $id)->assertNotFound();
            $this->postJson("/api/v1/espace/videos/{$id}/purchase", ['accept_terms' => true], $this->enTetes($someone['jeton']))->assertNotFound();
            $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($someone['jeton']))->assertJsonMissing(['id' => $id]);
        }
        $this->play($vet['jeton'], $id)->assertOk()->assertJsonPath('data.access', 'seller');
    }

    public function test_au_bucket_chaque_adresse_ecrit_un_seul_fichier_du_dossier_pendant_15_minutes(): void
    {
        // Le SDK d'AWS signe avec l'heure réelle : l'essai aussi, même quand la suite se joue à une autre heure (HORLOGE)
        $this->travelBack();
        $this->freezeTime();
        config(['filesystems.disks.prive' => [
            'driver' => 's3', 'key' => 'essai', 'secret' => 'essai', 'region' => 'auto', 'bucket' => 'videos-essai',
            'endpoint' => 'https://essai.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true,
        ]]);
        Storage::forgetDisk('prive');
        $video = Video::factory()->create();
        $storage = app(VideoStorage::class);

        $upload = $storage->uploadUrl($video, 'segment-1-00001.m4s', now()->addMinutes(15));
        $this->assertStringContainsString('/videos-essai/'.$video->folder().'/segment-1-00001.m4s?', $upload);
        // 900 secondes ; le SDK compte avec l'heure réelle : une seconde peut passer entre-temps
        $this->assertMatchesRegularExpression('/X-Amz-Expires=(899|900)&/', $upload);

        $read = $storage->readUrl($video, 'download.mp4', now()->addHour(), 'nourrir-un-chat.mp4');
        $this->assertMatchesRegularExpression('/X-Amz-Expires=(3599|3600)&/', $read);
        $this->assertStringContainsString('response-content-disposition=attachment', $read);
    }

    public function test_une_adresse_de_lecture_n_ecrit_rien(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $read = app(VideoStorage::class)->readUrl($video, 'init-1.mp4', now()->addHour());
        $before = Storage::disk('prive')->get($video->folder().'/init-1.mp4');

        $this->call('PUT', $read, [], [], [], [], 'remplacé')->assertStatus(403);
        $this->assertSame($before, Storage::disk('prive')->get($video->folder().'/init-1.mp4'));
        $this->assertNotNull($sale['id']);
    }

    public function test_apres_le_controle_plus_aucune_adresse_d_envoi_et_un_fichier_remplace_est_rattrape(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();

        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('files');

        // Une adresse donnée avant le contrôle écrit encore : une 4K en HEVC remplace la 1080p
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));
        Storage::disk('prive')->put($video->folder().'/en-trop.m4s', str_repeat('x', 5000));
        RecheckVideoFiles::dispatchSync($video->id);

        $video->refresh();
        $this->assertSame('rejected', $video->files_status->value);
        $this->assertSame([], Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_la_mise_en_vente_relit_les_fichiers(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));

        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertSame('draft', $video->refresh()->status->value);
        $this->assertSame('rejected', $video->files_status->value);
    }

    public function test_un_morceau_qui_n_est_pas_un_fragment_mp4_est_refuse(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage();
        $package['segment-1-00001.m4s'] = str_repeat('n’importe quoi ', 50);
        $this->upload($vet['jeton'], $id, $package);

        $this->finish($vet['jeton'], $id)->assertUnprocessable();
        $this->assertSame('rejected', Video::query()->sole()->files_status->value);
        $this->assertSame([], Storage::disk('prive')->allFiles(Video::query()->sole()->folder()));
    }

    public function test_la_place_reelle_est_controlee_au_bout_de_l_envoi_avec_la_version_a_telecharger(): void
    {
        $vet = $this->verifiedVet();
        Video::factory()->create(['seller_user_id' => $vet['user']->id, 'size_bytes' => Video::PERSONAL_STORAGE_BYTES - 15_000]);
        $id = $this->draft($vet['jeton']);
        // Annoncé petit, il commence ; le vrai paquet (environ 11 Ko) et sa version à télécharger ne tiennent pas
        $this->upload($vet['jeton'], $id, $this->hlsPackage());

        $this->finish($vet['jeton'], $id)->assertUnprocessable();
        $this->assertSame('rejected', Video::query()->where('uuid', $id)->firstOrFail()->files_status->value);
    }

    public function test_les_taches_des_videos_sont_planifiees(): void
    {
        $events = collect(app(Schedule::class)->events());
        $purge = $events->first(fn (Event $e) => str_contains((string) $e->command, 'videos:purge-abandoned-uploads'));
        $pool = $events->first(fn (Event $e) => str_contains((string) $e->command, 'videos:plus-pool'));

        $this->assertNotNull($purge);
        $this->assertSame('10 3 * * *', $purge->expression);
        $this->assertNotNull($pool);
        $this->assertSame('0 5 1 * *', $pool->expression);
        $this->assertSame('Europe/Zurich', $pool->timezone);
        // Les comptes de paiement des vendeurs effacés, chaque nuit après les effacements (03:45)
        $closures = $events->first(fn (Event $e) => str_contains((string) $e->command, 'videos:close-seller-accounts'));
        $this->assertNotNull($closures);
        $this->assertSame('30 4 * * *', $closures->expression);
    }

    // ————— Mise en vente (case 10) —————

    public function test_en_vente_le_titre_la_description_le_prix_et_le_public_ne_s_effacent_pas(): void
    {
        $sale = $this->published();
        foreach (['title', 'description', 'price_cents', 'audience'] as $field) {
            $this->patchJson($this->base($sale['id']), [$field => null], $this->enTetes($sale['vet']['jeton']))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson($this->base($sale['id']), ['price_cents' => 99_999_999], $this->enTetes($sale['vet']['jeton']))->assertOk();
        $this->patchJson($this->base($sale['id']), ['price_cents' => 100_000_000], $this->enTetes($sale['vet']['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('price_cents');
    }

    // ————— Catalogue et achat (cases 14, 15, 20) —————

    public function test_un_proprietaire_n_achete_pas_une_video_reservee_aux_veterinaires(): void
    {
        $sale = $this->published(['audience' => 'vets']);
        $owner = $this->proprietaireAvecJeton();

        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertNotFound();
        $this->assertSame(0, VideoPurchase::query()->count());
    }

    public function test_les_achats_gardent_une_video_retiree(): void
    {
        $sale = $this->published();
        $owner = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => Video::query()->sole()->id, 'user_id' => $owner['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();

        $this->getJson('/api/v1/espace/videos/library', $this->enTetes($owner['jeton']))->assertOk()
            ->assertJsonPath('data.0.id', $sale['id'])->assertJsonPath('data.0.status', 'withdrawn')->assertJsonPath('data.0.access', 'owned');
    }

    public function test_les_adresses_des_morceaux_expirent_avec_la_lecture_et_le_telechargement_en_une_heure(): void
    {
        $this->freezeTime();
        $sale = $this->published();
        $video = Video::query()->sole();
        $master = $this->play($sale['vet']['jeton'], $sale['id'])->json('data.url');
        $playlist = collect(explode("\n", $this->get($master)->getContent()))->first(fn ($l) => str_starts_with($l, 'http'));
        $media = $this->get($playlist)->getContent();
        preg_match_all('/expires=(\d+)/', $media, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $expires) {
            $this->assertSame(now()->addHours(8)->getTimestamp(), (int) $expires);
        }

        $owner = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => $video->id, 'user_id' => $owner['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);
        $download = $this->getJson("/api/v1/espace/videos/{$sale['id']}/download", $this->enTetes($owner['jeton']))->json('data.url');
        $this->assertMatchesRegularExpression('/expires='.now()->addHour()->getTimestamp().'/', $download);
    }

    // ————— Vues et certificat (cases 22 et 25) —————

    public function test_une_absence_ne_compte_pas_comme_regardee(): void
    {
        $sale = $this->published(['in_plus' => true], seconds: 180);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);

        // Ouverte hier, puis une seule requête aujourd'hui : au plus une minute d'écoulé,
        // lue deux fois plus vite, plus la marge du premier envoi ; pas la vidéo entière
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->travel(1)->days();
        $this->progress($plus['jeton'], $sale['id'], [[0, 180]])->assertJsonPath('data.watched_seconds', 135)->assertJsonPath('data.completed', false);
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertUnprocessable();
        // Encore un jour d'absence : deux minutes de plus, pas un jour
        $this->travel(1)->days();
        $this->progress($plus['jeton'], $sale['id'], [[0, 180]])->assertJsonPath('data.watched_seconds', 180);
    }

    public function test_relancer_la_lecture_repart_l_horloge(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->travel(3)->days();
        $this->play($plus['jeton'], $sale['id'])->assertOk();

        $this->assertTrue(VideoView::query()->sole()->last_progress_at->isSameSecond(now()));
    }

    // ————— Paiement (vieil onglet, intention remplacée) —————

    public function test_une_intention_remplacee_est_annulee_et_un_vieil_onglet_qui_paie_donne_la_video(): void
    {
        $sale = $this->published();
        $owner = $this->proprietaireAvecJeton();
        $url = "/api/v1/espace/videos/{$sale['id']}/purchase";
        $this->postJson($url, ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk();
        $first = VideoPurchase::query()->sole()->payment_intent_id;

        // Le prix change : nouvelle intention, l'ancienne ne se paie plus
        $this->patchJson($this->base($sale['id']), ['price_cents' => 1900], $this->enTetes($sale['vet']['jeton']))->assertOk();
        $this->postJson($url, ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk()->assertJsonPath('data.amount_cents', 1900);
        $this->assertSame('canceled', $this->gateway->intentions[$first]['statut']);

        // Une intention payée avant d'être remplacée : la vidéo est à lui, pas de second paiement
        $second = VideoPurchase::query()->sole()->payment_intent_id;
        $this->gateway->conclure($second, 'succeeded');
        $this->postJson($url, ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertUnprocessable()->assertJsonValidationErrors('purchase');
        $this->assertNotNull(VideoPurchase::query()->sole()->paid_at);
    }

    public function test_le_webhook_retrouve_l_achat_d_une_intention_qui_n_est_plus_la_sienne(): void
    {
        $sale = $this->published();
        $owner = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk();
        $purchase = VideoPurchase::query()->sole();
        $account = 'acct_vet_'.$sale['vet']['user']->id;
        // Une autre intention du même achat, payée ailleurs (vieil onglet)
        $old = $this->gateway->pourCompte($account)->creerIntentionEnLigne(1500, ['objet' => 'video', 'video_purchase_id' => (string) $purchase->id], 'vieil-onglet', 450)['id'];
        $this->gateway->conclure($old, 'succeeded');

        $this->call('POST', '/api/v1/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'signature-valide', 'CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 'evt_old', 'type' => 'payment_intent.succeeded', 'account' => $account,
            'data' => ['object' => ['id' => $old, 'status' => 'succeeded', 'metadata' => ['objet' => 'video', 'video_purchase_id' => (string) $purchase->id]]],
        ]))->assertNoContent();

        $purchase->refresh();
        $this->assertNotNull($purchase->paid_at);
        $this->assertSame($old, $purchase->payment_intent_id);
    }

    // ————— Mineurs —————

    public function test_un_brouillon_regarde_par_son_vendeur_se_supprime(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->play($vet['jeton'], $id)->assertOk();

        $this->deleteJson($this->base($id), [], $this->enTetes($vet['jeton']))->assertNoContent();
        $this->assertSame(0, Video::query()->count());
    }

    public function test_qui_gere_les_videos_de_la_clinique_regarde_en_vendeur_les_autres_membres_comme_tout_le_monde(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(120), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();

        // Un autre administrateur de l'organisation, abonné à Plus : côté vendeur, ses vues ne comptent pas
        ['user' => $admin, 'jeton' => $adminJeton] = $this->administrateurAvecJeton($clinique);
        $admin->forceFill(['formule' => 'plus'])->save();
        $this->play($adminJeton, $id)->assertJsonPath('data.access', 'seller');

        // Un simple membre de l'équipe ne voit pas le brouillon
        ['user' => $employe, 'jeton' => $employeJeton] = $this->employeAvecJeton($clinique);
        $employe->forceFill(['formule' => 'plus'])->save();
        $this->play($employeJeton, $id)->assertNotFound();

        // En vente et incluse dans Plus : il la regarde comme tout abonné, et sa vue compte
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($jeton));
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertOk();
        $this->play($employeJeton, $id)->assertJsonPath('data.access', 'plus');
        for ($at = 0; $at < 90; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($adminJeton, $id, [[$at, $at + 15]]);
        }
        $this->assertFalse((bool) VideoView::query()->where('user_id', $admin->id)->value('counted_via_plus'));

        // Accès suspendu : plus du côté du vendeur
        Employe::withoutGlobalScopes()->where('user_id', $admin->id)->update(['acces_suspendu' => true]);
        $this->assertFalse(Video::query()->sole()->isOnSellerSide($admin->refresh()));
    }

    // ————— Deuxième vérification —————

    public function test_une_video_de_clinique_ne_se_publie_ni_par_un_administrateur_non_verifie_ni_dans_une_organisation_fermee(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton));

        ['user' => $other, 'jeton' => $otherJeton] = $this->administrateurAvecJeton($clinique);
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($otherJeton))->assertUnprocessable()->assertJsonValidationErrors('seller');
        $this->assertNotNull($other);

        $clinique->societe->organisation->forceFill(['verification' => VerificationOrganisation::EnAttente])->save();
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertForbidden();
        $this->assertSame('draft', Video::query()->sole()->status->value);
    }

    public function test_une_video_de_clinique_en_vente_paraît_a_tout_proprietaire_meme_sans_lien_avec_la_clinique(): void
    {
        ['jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton));
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertOk();

        $stranger = $this->proprietaireAvecJeton();
        $this->getJson('/api/v1/espace/videos', $this->enTetes($stranger['jeton']))->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_un_vendeur_qui_ne_vend_plus_sort_du_catalogue_et_de_plus_ses_acheteurs_gardent_la_video(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $buyer = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => Video::query()->sole()->id, 'user_id' => $buyer['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $sale['vet']['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();

        $this->getJson('/api/v1/espace/videos', $this->enTetes($plus['jeton']))->assertOk()->assertJsonCount(0, 'data');
        $this->play($plus['jeton'], $sale['id'])->assertNotFound();
        $this->play($buyer['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.access', 'owned');
    }

    public function test_un_vendeur_n_achete_pas_sa_propre_video(): void
    {
        $sale = $this->published();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($sale['vet']['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('purchase');
    }

    public function test_la_relecture_part_16_minutes_apres_le_controle(): void
    {
        Queue::fake();
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();

        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->delay !== null && now()->diffInSeconds($job->delay, true) >= 16 * 60 - 5);
        // Et une relecture complète tout de suite, que la mise en vente attend
        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->delay === null);
    }

    public function test_une_video_deja_achetee_n_est_jamais_effacee_par_la_relecture(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $buyer = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => $video->id, 'user_id' => $buyer['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));

        RecheckVideoFiles::dispatchSync($video->id);

        $video->refresh();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertSame('ready', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
        $this->play($buyer['jeton'], $sale['id'])->assertOk();
    }

    public function test_un_morceau_remplace_par_plus_lourd_apres_le_controle_est_rattrape(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $video = Video::query()->sole();
        // Le même morceau, valide, mais de 3 Mo : la place n'a été comptée qu'au contrôle
        Storage::disk('prive')->put($video->folder().'/segment-1-00002.m4s', $this->fragment(6, str_repeat('x', 3_000_000)));

        RecheckVideoFiles::dispatchSync($video->id);

        $this->assertSame('rejected', $video->refresh()->files_status->value);
    }

    public function test_une_panne_du_stockage_n_efface_rien(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('read')->andReturn(null));

        // 503 : pas un refus, le navigateur garde sa copie et réessaiera
        $this->finish($vet['jeton'], $id)->assertStatus(503)->assertJsonPath('message', 'Le stockage ne répond pas pour le moment : réessayez dans un instant.');
        $video = Video::query()->sole();
        $this->assertSame('pending', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));

        $video->forceFill(['files_status' => 'ready'])->save();
        $this->expectException(\RuntimeException::class);
        try {
            (new RecheckVideoFiles($video->id))->handle(app(HlsPackage::class), app(VideoStorage::class), app(VideoPublisher::class));
        } finally {
            $this->assertSame('ready', $video->refresh()->files_status->value);
            $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
        }
    }

    public function test_pas_d_adresse_d_envoi_sans_envoi_annonce_ni_pour_la_version_a_telecharger(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('files');

        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 100], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson($this->base($id).'/upload/urls', ['files' => ['download.mp4']], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_la_purge_prend_les_restes_d_un_refus_et_les_envois_qui_durent_plus_de_3_jours(): void
    {
        $vet = $this->verifiedVet();
        $rejected = $this->draft($vet['jeton']);
        $package = $this->hlsPackage();
        $package['init-1.mp4'] = $this->initSegment(3840, 2160, 'hvc1');
        $this->upload($vet['jeton'], $rejected, $package);
        $this->finish($vet['jeton'], $rejected)->assertUnprocessable();
        $rejectedVideo = Video::query()->where('uuid', $rejected)->firstOrFail();
        // Une adresse encore valable dépose un fichier après le refus
        Storage::disk('prive')->put($rejectedVideo->folder().'/segment-1-00001.m4s', 'reste');

        $slow = $this->draft($vet['jeton']);
        $this->postJson($this->base($slow).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 100], $this->enTetes($vet['jeton']))->assertOk();
        $slowVideo = Video::query()->where('uuid', $slow)->firstOrFail();
        Storage::disk('prive')->put($slowVideo->folder().'/segment-1-00001.m4s', 'en cours');

        $this->travel(2)->days();
        // L'envoi lent redemande une adresse chaque jour : il n'est pas encore abandonné
        $this->postJson($this->base($slow).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertOk();
        $this->artisan('videos:purge-abandoned-uploads')->assertSuccessful();
        $this->assertSame([], Storage::disk('prive')->allFiles($rejectedVideo->folder()));
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($slowVideo->folder()));

        $this->travel(2)->days();
        $this->postJson($this->base($slow).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertOk();
        $this->artisan('videos:purge-abandoned-uploads')->assertSuccessful();
        $this->assertSame([], Storage::disk('prive')->allFiles($slowVideo->folder()));
    }

    public function test_une_duree_annoncee_qui_ne_correspond_pas_aux_morceaux_est_refusee(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage(30);
        // Les listes annoncent 10 fois plus long que ce que portent les morceaux
        foreach (['video-and-audio-1.m3u8', 'video-and-audio-2.m3u8'] as $playlist) {
            $package[$playlist] = preg_replace_callback('/#EXTINF:([\d.]+),/', fn ($m) => '#EXTINF:'.((float) $m[1] * 10).',', $package[$playlist]);
        }
        $this->upload($vet['jeton'], $id, $package);

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonPath('errors.files.0', 'La durée annoncée ne correspond pas à la vidéo : renvoyez-la depuis Veterinary.');
    }

    public function test_l_apercu_ne_s_allonge_pas_avec_de_longs_morceaux(): void
    {
        $sale = $this->published(seconds: 180);
        $video = Video::query()->sole();
        // Des morceaux de 59 puis 60 secondes
        foreach (['video-and-audio-1.m3u8', 'video-and-audio-2.m3u8'] as $playlist) {
            $n = $playlist === 'video-and-audio-1.m3u8' ? 1 : 2;
            Storage::disk('prive')->put($video->folder().'/'.$playlist, "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:60\n#EXT-X-MAP:URI=\"init-{$n}.mp4\"\n#EXTINF:59.000,\nsegment-{$n}-00001.m4s\n#EXTINF:60.000,\nsegment-{$n}-00002.m4s\n#EXTINF:61.000,\nsegment-{$n}-00003.m4s\n#EXT-X-ENDLIST\n");
        }
        $owner = $this->proprietaireAvecJeton();
        $master = $this->play($owner['jeton'], $sale['id'])->assertJsonPath('data.scope', 'preview')->json('data.url');
        $media = collect(explode("\n", $this->get($master)->getContent()))->first(fn ($l) => str_starts_with($l, 'http'));
        $lines = $this->get($media)->getContent();

        $this->assertSame(1, preg_match_all('/^#EXTINF:/m', $lines));
    }

    public function test_le_certificat_reste_acquis_meme_si_la_video_quitte_plus(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($plus['jeton'], $sale['id'], [[$at, $at + 15]]);
        }
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();

        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertOk();
    }

    public function test_une_clinique_qui_met_une_video_dans_plus_peut_recevoir_les_virements_de_la_cagnotte(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($jeton));
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertOk();

        $this->assertSame(['acct_societe_'.$clinique->id], $this->gateway->virementsDemandes);
    }

    // ————— Troisième vérification —————

    public function test_un_morceau_vide_est_un_mauvais_fichier_pas_une_panne(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage();
        $package['segment-1-00001.m4s'] = '';
        $this->upload($vet['jeton'], $id, $package);
        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonPath('errors.files.0', 'Un morceau de la vidéo n’est pas lisible : renvoyez la vidéo.');
        $this->assertSame('rejected', Video::query()->sole()->files_status->value);

        // Après le contrôle, un morceau vidé : la relecture refuse, elle ne croit pas à une panne
        $other = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $other, $this->hlsPackage());
        $this->finish($vet['jeton'], $other)->assertOk();
        $video = Video::query()->where('uuid', $other)->firstOrFail();
        Storage::disk('prive')->put($video->folder().'/segment-1-00001.m4s', '');
        RecheckVideoFiles::dispatchSync($video->id);
        $this->assertSame('rejected', $video->refresh()->files_status->value);
    }

    public function test_une_relecture_impossible_jusqu_au_bout_retire_la_video_de_la_vente(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));

        // Une panne n'est pas un mauvais fichier : hors de la vente, rien d'effacé, à relire avant de revendre
        $video->refresh();
        $this->assertSame('draft', $video->status->value);
        $this->assertSame('ready', $video->files_status->value);
        $this->assertNull($video->files_checked_at);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
        $this->assertNotNull($sale['id']);
    }

    public function test_plus_d_adresse_quand_le_depose_depasse_la_place_annoncee(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/segment-1-00001.m4s', str_repeat('x', 60_000_000));

        $this->postJson($this->base($id).'/upload/urls', ['files' => ['segment-1-00002.m4s']], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('files');

        // Et pas plus de 50 adresses à la fois
        $fresh = $this->draft($vet['jeton']);
        $this->postJson($this->base($fresh).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $names = array_map(fn ($i) => sprintf('segment-1-%05d.m4s', $i), range(1, 51));
        $this->postJson($this->base($fresh).'/upload/urls', ['files' => $names], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonPath('errors.files.0', 'Pas plus de 50 fichiers à la fois.');
        $this->postJson($this->base($fresh).'/upload/urls', ['files' => array_slice($names, 0, 50)], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_avant_de_verser_la_cagnotte_le_compte_est_mis_en_etat_de_recevoir(): void
    {
        $this->travelTo('2026-10-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_plus', 'stripe_account_ready_at' => now()]);
        $video = Video::factory()->create(['seller_user_id' => $seller->id, 'status' => 'published', 'audience' => 'owners', 'in_plus' => true, 'price_cents' => 500]);
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 5000, 'pool_cents' => 2500, 'views' => 1, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 1, 'earned_cents' => 2500]);

        $this->artisan('videos:plus-pool', ['month' => '2026-09'])->assertSuccessful();

        $this->assertSame(['acct_plus'], $this->gateway->virementsDemandes);
        $calls = array_map(fn ($c) => $c['methode'], $this->gateway->appelsDe());
        $this->assertLessThan(array_search('transferer', $calls, true), array_search('demanderVirementsRecus', $calls, true));
        $this->assertNotNull($video->id);
    }

    public function test_pas_de_certificat_de_sa_propre_video_ni_d_un_brouillon(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->play($vet['jeton'], $id)->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($vet['jeton'], $id, [[$at, $at + 15]]);
        }
        $this->assertNotNull(VideoView::query()->sole()->completed_at);

        $this->postJson("/api/v1/espace/videos/{$id}/certificate", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->assertSame(0, ViewingCertificate::query()->count());
    }

    public function test_un_morceau_du_milieu_place_ailleurs_que_la_liste_le_dit_est_refuse(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage(60);
        // Dix morceaux de 6 s : le 4e (relu) se dit à 3 heures
        foreach ([1, 2] as $n) {
            $package[sprintf('segment-%d-%05d.m4s', $n, 4)] = $this->fragment(10_800, str_repeat('x', 900));
        }
        $this->upload($vet['jeton'], $id, $package);

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonPath('errors.files.0', 'La durée annoncée ne correspond pas à la vidéo : renvoyez-la depuis Veterinary.');
    }

    public function test_seul_le_h264_avc1_passe(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage(30, videoCodec: 'avc3'));

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_une_organisation_fermee_retire_sa_video_et_sort_du_catalogue(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->fillSheet("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton));
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($jeton))->assertOk();
        $owner = $this->proprietaireAvecJeton();
        $this->getJson('/api/v1/espace/videos', $this->enTetes($owner['jeton']))->assertJsonCount(1, 'data');

        $clinique->societe->organisation->forceFill(['verification' => VerificationOrganisation::EnAttente])->save();
        $this->getJson('/api/v1/espace/videos', $this->enTetes($owner['jeton']))->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/videos/{$id}/withdraw", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    public function test_un_contrat_termine_n_est_plus_du_cote_du_vendeur(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        ['user' => $admin] = $this->administrateurAvecJeton($clinique);
        $video = Video::query()->where('uuid', $id)->firstOrFail();
        $this->assertTrue($video->isOnSellerSide($admin));

        Employe::withoutGlobalScopes()->where('user_id', $admin->id)->update(['fin_contrat_le' => now()->subDay()->toDateString()]);
        $this->assertFalse($video->isOnSellerSide($admin->refresh()));
    }

    public function test_apres_un_retrait_qui_a_tout_vu_retrouve_la_video_et_son_certificat(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($plus['jeton'], $sale['id'], [[$at, $at + 15]]);
        }
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();

        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($plus['jeton']))->assertOk()
            ->assertJsonPath('data.access', null)->assertJsonPath('data.viewing.completed', true);
        $this->play($plus['jeton'], $sale['id'])->assertNotFound();
        $other = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($other['jeton']))->assertNotFound();
    }

    public function test_des_frais_illisibles_sont_estimes_et_ne_gonflent_pas_la_cagnotte(): void
    {
        $this->travelTo('2026-10-02 08:00');
        $user = User::factory()->create(['formule' => 'plus']);
        $invoice = new FactureProprietaire;
        $invoice->forceFill([
            'user_id' => $user->id, 'numero' => 'P-1', 'emise_le' => '2026-09-01', 'libelle' => 'Abonnement Veterinary Plus',
            'montant' => 12, 'montant_ht' => 12, 'statut' => 'payee', 'paye_le' => '2026-09-01 08:00:00', 'stripe_paiement_id' => 'pi_inconnu',
            'periode_debut' => '2026-09-01', 'periode_fin' => '2027-09-01', 'metadonnees' => ['type' => 'abonnement', 'formule' => 'plus'],
        ])->save();

        $this->artisan('videos:plus-pool', ['month' => '2026-09'])->assertSuccessful();

        // 12 CHF moins 65 centimes estimés, 30 jours sur 365
        $this->assertSame((int) floor((1200 - 65) * 30 / 365), PlusPoolMonth::query()->sole()->net_revenue_cents);
    }

    public function test_une_panne_du_stockage_a_la_mise_en_vente_ne_refuse_ni_n_efface_rien(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('read')->andReturn(null));

        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(503);
        $video = Video::query()->sole();
        $this->assertSame('ready', $video->files_status->value);
        $this->assertSame('draft', $video->status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    // ————— Quatrième vérification —————

    public function test_chaque_morceau_est_relu_avant_la_vente_meme_ceux_que_le_controle_ne_lit_pas(): void
    {
        $vet = $this->verifiedVet();
        // Cinq morceaux : le contrôle de fin d'envoi relit le 1er, le 2e, le 4e et le 5e
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage(30);
        $package['segment-1-00003.m4s'] = 'PK'.str_repeat('z', 900);
        $this->upload($vet['jeton'], $id, $package);
        $this->finish($vet['jeton'], $id)->assertOk();
        // La relecture complète, lancée aussitôt, le trouve
        $this->assertSame('rejected', Video::query()->where('uuid', $id)->firstOrFail()->files_status->value);

        $other = $this->draft($vet['jeton']);
        $package = $this->hlsPackage(30);
        $package['segment-2-00003.m4s'] = $this->fragment(10_800, str_repeat('x', 900));
        $this->upload($vet['jeton'], $other, $package);
        $this->finish($vet['jeton'], $other)->assertOk();
        $this->assertSame('rejected', Video::query()->where('uuid', $other)->firstOrFail()->files_status->value);
    }

    public function test_la_mise_en_vente_attend_la_relecture_complete(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        Video::query()->sole()->forceFill(['files_checked_at' => null])->save();
        Queue::fake();

        // Demandée, pas refusée : elle se met en vente d'elle-même à la fin de la relecture
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202)->assertJsonPath('data.publish_requested', true);
        Queue::assertPushed(RecheckVideoFiles::class);
        $this->assertSame('draft', Video::query()->sole()->status->value);
    }

    public function test_un_envoi_qui_depasse_sa_place_est_arrete_dans_les_10_minutes(): void
    {
        $vet = $this->verifiedVet();
        $big = $this->draft($vet['jeton']);
        $this->postJson($this->base($big).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $bigVideo = Video::query()->where('uuid', $big)->firstOrFail();
        Storage::disk('prive')->put($bigVideo->folder().'/segment-1-00001.m4s', str_repeat('x', 60_000_000));
        $fine = $this->draft($vet['jeton']);
        $this->postJson($this->base($fine).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 100_000_000], $this->enTetes($vet['jeton']))->assertOk();
        $fineVideo = Video::query()->where('uuid', $fine)->firstOrFail();
        Storage::disk('prive')->put($fineVideo->folder().'/segment-1-00001.m4s', str_repeat('x', 60_000_000));

        $this->artisan('videos:watch-uploads')->assertSuccessful();

        $this->assertSame('rejected', $bigVideo->refresh()->files_status->value);
        $this->assertSame([], Storage::disk('prive')->allFiles($bigVideo->folder()));
        $this->assertSame('pending', $fineVideo->refresh()->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($fineVideo->folder()));
        $watch = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'videos:watch-uploads'));
        $this->assertSame('*/10 * * * *', $watch?->expression);
    }

    public function test_dans_l_app_iphone_une_video_en_vente_ne_se_modifie_pas(): void
    {
        $sale = $this->published();
        $this->patchJson($this->base($sale['id']), ['price_cents' => 2500], [...$this->enTetes($sale['vet']['jeton']), 'X-Veterinary-Platform' => 'ios'])
            ->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->assertSame(1500, Video::query()->sole()->price_cents);
        $this->patchJson($this->base($sale['id']), ['price_cents' => 2500], $this->enTetes($sale['vet']['jeton']))->assertOk();
    }

    public function test_sans_acces_on_ne_retrouve_que_l_essentiel_et_jamais_un_brouillon(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($plus['jeton'], $sale['id'], [[$at, $at + 15]]);
        }
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertOk();
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();
        Video::query()->sole()->forceFill(['title' => 'Nouveau titre secret', 'audience' => 'vets'])->save();

        $data = $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($plus['jeton']))->assertOk()->json('data');
        $this->assertSame('Nourrir un chat senior', $data['title']);
        // Le genre (vidéo ou série) en plus : la fenêtre dit « Cette série ne se regarde plus » (deuxième passage des séries)
        $this->assertSame(['id', 'kind', 'title', 'seller', 'access', 'viewing'], array_keys($data));
        $this->assertSame('video', $data['kind']);

        // Un ancien administrateur qui avait vu un brouillon en entier : rien
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $draft = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $draft, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $draft, '/api/v1/videos')->assertOk();
        ['user' => $admin, 'jeton' => $adminJeton] = $this->administrateurAvecJeton($clinique);
        $this->play($adminJeton, $draft)->assertJsonPath('data.access', 'seller');
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($adminJeton, $draft, [[$at, $at + 15]]);
        }
        $this->postJson("/api/v1/espace/videos/{$draft}/certificate", [], $this->enTetes($adminJeton))->assertUnprocessable()->assertJsonValidationErrors('certificate');
        Employe::withoutGlobalScopes()->where('user_id', $admin->id)->update(['fin_contrat_le' => now()->subDay()->toDateString()]);
        $this->getJson("/api/v1/espace/videos/{$draft}", $this->enTetes($adminJeton))->assertNotFound();
        $this->postJson("/api/v1/espace/videos/{$draft}/certificate", [], $this->enTetes($adminJeton))->assertUnprocessable()->assertJsonValidationErrors('certificate');
    }

    public function test_le_vendeur_d_une_video_en_vente_ne_se_delivre_pas_de_certificat(): void
    {
        $sale = $this->published();
        $this->play($sale['vet']['jeton'], $sale['id'])->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($sale['vet']['jeton'], $sale['id'], [[$at, $at + 15]]);
        }
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($sale['vet']['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('certificate');
    }

    public function test_une_video_qui_a_ete_en_vente_ne_s_efface_jamais_meme_redevenue_brouillon(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));
        $this->assertSame('draft', $video->refresh()->status->value);

        $this->deleteJson($this->base($sale['id']), [], $this->enTetes($sale['vet']['jeton']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, Video::query()->count());
    }

    public function test_une_part_d_une_annee_passee_se_verse_meme_sous_10_chf(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_retard', 'stripe_account_ready_at' => now()]);
        PlusPoolMonth::query()->create(['month' => '2026-12', 'net_revenue_cents' => 1000, 'pool_cents' => 500, 'views' => 1, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 1, 'earned_cents' => 500]);

        $this->artisan('videos:plus-pool', ['month' => '2027-01'])->assertSuccessful();

        $this->assertSame([['compte' => 'acct_retard', 'centimes' => 500]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
    }

    public function test_un_achat_en_cours_protege_la_video_comme_un_achat_paye(): void
    {
        $sale = $this->published();
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));

        RecheckVideoFiles::dispatchSync($video->id);

        $this->assertSame('withdrawn', $video->refresh()->status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_une_liste_de_fichiers_qui_ne_repond_pas_est_une_panne(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('files')->andThrow(new \RuntimeException('liste')));

        $this->finish($vet['jeton'], $id)->assertStatus(503);
        $this->assertSame('pending', Video::query()->sole()->files_status->value);
    }

    public function test_le_catalogue_ne_fait_pas_une_requete_par_video(): void
    {
        $owner = $this->proprietaireAvecJeton();
        $count = function () use ($owner): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/espace/videos', $this->enTetes($owner['jeton']))->assertOk();
            DB::disableQueryLog();

            // Sans les écritures du jeton (sa date d'usage, notée de temps en temps)
            return count(array_filter(DB::getQueryLog(), fn ($q) => ! str_contains($q['query'], 'personal_access_tokens')));
        };
        $vet = $this->verifiedVet();
        Video::factory()->create(['seller_user_id' => $vet['user']->id, 'status' => 'published', 'audience' => 'owners', 'price_cents' => 500, 'published_at' => now()]);
        $one = $count();
        Video::factory()->count(5)->create(['seller_user_id' => $vet['user']->id, 'status' => 'published', 'audience' => 'owners', 'price_cents' => 500, 'published_at' => now()]);

        $this->assertSame($one, $count());
    }

    /* ————— Cinquième vérification (31.10.2026) ————— */

    /** Regarde en entier la vidéo de 30 secondes. */
    private function watchAll(string $jeton, string $id): void
    {
        $this->play($jeton, $id)->assertOk();
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($jeton, $id, [[$at, $at + 15]]);
        }
    }

    /** Un acheteur qui a payé la vidéo. */
    private function paidBuyer(Video $video): array
    {
        $buyer = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => $video->id, 'user_id' => $buyer['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);

        return $buyer;
    }

    private function recheck(Video $video): void
    {
        (new RecheckVideoFiles($video->id))->handle(app(HlsPackage::class), app(VideoStorage::class), app(VideoPublisher::class));
    }

    public function test_une_video_achetee_que_la_mise_en_vente_trouve_abimee_garde_ses_fichiers(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $buyer = $this->paidBuyer($video);
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));

        // Remise en vente : le contrôle refuse, mais ceux qui l'ont payée la gardent
        $this->postJson($this->base($sale['id']).'/publish', [], $this->enTetes($sale['vet']['jeton']))->assertUnprocessable()->assertJsonValidationErrors('files');
        $video->refresh();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertSame('ready', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
        $this->play($buyer['jeton'], $sale['id'])->assertOk();
    }

    public function test_une_video_achetee_ne_change_plus_de_fichiers_meme_redevenue_brouillon(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));
        // Une vieille intention payée après le retour au brouillon
        $this->paidBuyer($video);

        $this->postJson($this->base($sale['id']).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($sale['vet']['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('ready', $video->refresh()->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_retiree_par_la_relecture_elle_est_relue_en_entier_avant_d_etre_revendue(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $this->paidBuyer($video);
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));
        RecheckVideoFiles::dispatchSync($video->id);
        $this->assertSame('withdrawn', $video->refresh()->status->value);
        $this->assertNull($video->files_checked_at);

        // Plus lourde qu'au contrôle, achetée : même chose
        $grown = $this->published();
        $grownVideo = Video::query()->where('uuid', $grown['id'])->firstOrFail();
        $this->paidBuyer($grownVideo);
        Storage::disk('prive')->put($grownVideo->folder().'/segment-1-00002.m4s', $this->fragment(6, str_repeat('x', 3_000_000)));
        RecheckVideoFiles::dispatchSync($grownVideo->id);
        $this->assertSame('withdrawn', $grownVideo->refresh()->status->value);
        $this->assertNull($grownVideo->files_checked_at);

        Queue::fake();
        $this->postJson($this->base($sale['id']).'/publish', [], $this->enTetes($sale['vet']['jeton']))->assertStatus(202);
        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->videoId === $video->id);
        $this->assertSame('withdrawn', $video->refresh()->status->value);
    }

    public function test_la_mise_en_vente_demandee_se_fait_d_elle_meme_a_la_fin_de_la_relecture(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $video = Video::query()->sole();
        $video->forceFill(['files_checked_at' => null])->save();
        Queue::fake();
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);

        $this->recheck($video);

        $video->refresh();
        $this->assertSame('published', $video->status->value);
        $this->assertNotNull($video->published_at);
        $this->assertNull($video->publish_requested_at);
        $this->assertNotNull($video->files_checked_at);
    }

    public function test_une_mise_en_vente_demandee_tombe_si_le_vendeur_ne_vend_plus_a_la_fin_de_la_relecture(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $video = Video::query()->sole();
        $video->forceFill(['files_checked_at' => null])->save();
        Queue::fake();
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        $vet['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();

        $this->recheck($video);

        $video->refresh();
        $this->assertSame('draft', $video->status->value);
        $this->assertNull($video->published_at);
        $this->assertNull($video->publish_requested_at);
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($vet['jeton']))->assertJsonPath('data.0.publish_requested', false);
    }

    public function test_une_seule_relecture_a_la_fois_par_video_et_par_passage(): void
    {
        Queue::fake();
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        Queue::assertPushed(RecheckVideoFiles::class, 3);

        // La relecture de tout de suite n'a pas encore tourné : la mise en vente ne la double pas
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        Queue::assertPushed(RecheckVideoFiles::class, 3);
        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->pass === 'late' && $job->delay !== null);
        $this->assertSame(900, (new RecheckVideoFiles(1))->timeout);
    }

    public function test_la_relecture_complete_lit_les_debuts_des_morceaux_en_parallele_dans_le_bucket(): void
    {
        $vet = $this->verifiedVet();
        $video = Video::query()->where('uuid', $this->draft($vet['jeton']))->firstOrFail();
        $requests = [];
        $mock = new MockHandler;
        foreach (['a.m4s', 'b.m4s', 'c.m4s'] as $name) {
            $mock->append(function (CommandInterface $command) use (&$requests, $name) {
                $requests[] = [$command->getName(), $command['Key'], $command['Range']];

                return $name === 'b.m4s' ? new S3Exception('absent', $command) : new Result(['Body' => Utils::streamFor('debut de '.basename($command['Key']))]);
            });
        }
        $client = new S3Client(['region' => 'auto', 'version' => 'latest', 'credentials' => ['key' => 'cle', 'secret' => 'secret'], 'handler' => $mock]);
        $adapter = new S3Adapter($client, 'videos');
        Storage::set('prive', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, ['driver' => 's3', 'bucket' => 'videos'], $client));

        $heads = app(VideoStorage::class)->readHeads($video, ['a.m4s', 'b.m4s', 'c.m4s'], 16384);

        $this->assertSame(['a.m4s' => 'debut de a.m4s', 'b.m4s' => '', 'c.m4s' => 'debut de c.m4s'], $heads);
        $this->assertCount(3, $requests);
        foreach ($requests as [$command, $key, $range]) {
            $this->assertSame('GetObject', $command);
            $this->assertStringStartsWith($video->folder().'/', $key);
            $this->assertSame('bytes=0-16383', $range);
        }
    }

    public function test_la_relecture_complete_demande_tous_les_morceaux_de_chaque_version_d_un_coup(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage(60);
        $this->upload($vet['jeton'], $id, $package);
        $this->finish($vet['jeton'], $id)->assertOk();
        $video = Video::query()->sole();
        // Une demande par version (1080p, 720p), avec chacun de ses morceaux
        $perRendition = array_count_values(array_map(fn (string $name) => explode('-', $name)[1], preg_grep('/^segment-/', array_keys($package))));
        $storage = $this->partialMock(VideoStorage::class);
        $storage->shouldReceive('readHeads')->times(count($perRendition))
            ->withArgs(fn ($video, array $names) => count($names) === $perRendition[explode('-', $names[0])[1]] && count($names) > 4)->passthru();

        $result = app(HlsPackage::class)->inspect($video, everySegment: true);
        $this->assertTrue($result['ok'], (string) ($result['reason'] ?? ''));
    }

    public function test_un_nouvel_envoi_remet_a_zero_ce_qui_avait_ete_regarde(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($plus['jeton'], $sale['id']);
        VideoView::query()->update(['counted_at' => now(), 'counted_via_plus' => true]);
        $video = Video::query()->sole();
        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));

        // Le vendeur renvoie d'autres fichiers sous la même fiche
        $this->upload($sale['vet']['jeton'], $sale['id'], $this->hlsPackage());
        $this->finish($sale['vet']['jeton'], $sale['id'])->assertOk();

        $view = VideoView::query()->sole();
        $this->assertNull($view->completed_at);
        $this->assertSame(0, $view->watched_seconds);
        // La vue comptée pour la cagnotte d'un mois passé reste comptée
        $this->assertNotNull($view->counted_at);
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertUnprocessable()->assertJsonValidationErrors('certificate');
    }

    public function test_pas_de_certificat_pendant_un_renvoi_et_pas_d_erreur_sans_duree(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($plus['jeton'], $sale['id']);
        $video = Video::query()->sole();
        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));
        $video->forceFill(['files_status' => 'pending', 'duration_seconds' => null])->save();

        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertUnprocessable()->assertJsonValidationErrors('certificate');
        $this->assertSame(0, ViewingCertificate::query()->count());
    }

    public function test_repartie_en_brouillon_elle_ne_se_rouvre_qu_avec_un_certificat_deja_delivre(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $certified = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $watched = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($certified['jeton'], $sale['id']);
        $this->watchAll($watched['jeton'], $sale['id']);
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($certified['jeton']))->assertOk();
        (new RecheckVideoFiles(Video::query()->sole()->id))->failed(new \RuntimeException('stockage'));

        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($certified['jeton']))->assertOk()->assertJsonPath('data.access', null);
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($watched['jeton']))->assertNotFound();
    }

    public function test_une_relecture_impossible_retire_une_video_achetee_avec_sa_date_de_retrait(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $this->paidBuyer($video);

        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));

        $video->refresh();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertNotNull($video->withdrawn_at);
    }

    public function test_une_liste_de_fichiers_qui_ne_repond_pas_est_une_panne_pour_la_reprise_et_les_adresses(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))->assertOk();
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('files')->andThrow(new \RuntimeException('liste')));

        $this->getJson($this->base($id).'/upload/files', $this->enTetes($vet['jeton']))->assertStatus(503);
        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertStatus(503);
    }

    public function test_un_envoi_qui_depasse_le_stockage_est_arrete_meme_dans_sa_place_annoncee(): void
    {
        $vet = $this->verifiedVet();
        $full = Video::query()->where('uuid', $this->draft($vet['jeton']))->firstOrFail();
        $full->forceFill(['size_bytes' => Video::PERSONAL_STORAGE_BYTES - 10_000_000])->save();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $video = Video::query()->where('uuid', $id)->firstOrFail();
        // 40 Mo : dans la marge de la place annoncée, mais au-delà des 10 Mo qui restent
        Storage::disk('prive')->put($video->folder().'/segment-1-00001.m4s', str_repeat('x', 40_000_000));

        $this->artisan('videos:watch-uploads')->assertSuccessful();

        $this->assertSame('rejected', $video->refresh()->files_status->value);
        $this->assertSame([], Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_le_guet_laisse_a_la_purge_les_envois_commences_il_y_a_plus_de_3_jours(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/segment-1-00001.m4s', str_repeat('x', 60_000_000));
        $video->forceFill(['upload_started_at' => now()->subDays(4)])->save();
        $files = $this->spy(VideoStorage::class);
        $files->shouldReceive('files')->andReturn(['segment-1-00001.m4s' => 60_000_000]);

        $this->artisan('videos:watch-uploads')->assertSuccessful();

        // Le guet ne relit que les envois récents : les anciens sont à la purge, chaque nuit
        $files->shouldNotHaveReceived('files');
        $this->assertSame('pending', $video->refresh()->files_status->value);
    }

    public function test_relue_pendant_la_demande_sans_l_avoir_vue_elle_se_met_en_vente_tout_de_suite(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        Video::query()->sole()->forceFill(['files_checked_at' => null])->save();
        Queue::fake();
        // La relecture finit juste au moment où la demande s'écrit, sans la voir
        $done = false;
        DB::listen(function ($query) use (&$done): void {
            if (! $done && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'publish_requested_at')) {
                $done = true;
                Video::query()->update(['files_checked_at' => now()]);
            }
        });

        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');
        Queue::assertNothingPushed();
    }

    public function test_la_page_vendre_apprend_par_reverb_ce_que_la_relecture_a_fait(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->fillSheet($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $video = Video::query()->sole();
        $video->forceFill(['files_checked_at' => null])->save();
        Queue::fake();
        $this->postJson($this->base($id).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        Events::fake([VideoUpdated::class]);

        $this->recheck($video);

        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$vet['user']->id
            && $e->broadcastAs() === 'video.updated'
            && $e->broadcastWith() === ['id' => $id, 'status' => 'published', 'files_status' => 'ready', 'publish_requested' => false, 'rejection_reason' => null, 'notice' => null]);

        // Une vidéo de clinique : le canal des vidéos de l'organisation, aussi quand la relecture échoue
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $clinicId = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $clinicId, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $clinicId, '/api/v1/videos')->assertOk();
        (new RecheckVideoFiles(Video::query()->where('uuid', $clinicId)->firstOrFail()->id))->failed(new \RuntimeException('stockage'));
        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => $e->broadcastOn()[0]->name === 'private-organisation.'.$clinique->societe->organisation_id.'.videos'
            && $e->broadcastWith()['id'] === $clinicId);
    }

    public function test_le_canal_des_videos_de_l_organisation_demande_le_droit_organisation(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
        ['clinique' => $clinique, 'jeton' => $admin] = $this->clinicSeller();
        ['jeton' => $employe] = $this->employeAvecJeton($clinique);
        $organisation = $clinique->societe->organisation_id;
        $auth = fn (string $jeton, string $canal) => $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $canal], $this->enTetes($jeton));

        $auth($admin, 'private-organisation.'.$organisation.'.videos')->assertOk();
        $auth($employe, 'private-organisation.'.$organisation.'.videos')->assertForbidden();
        $auth($admin, 'private-organisation.'.($organisation + 1).'.videos')->assertForbidden();
    }

    /* ————— Sixième vérification (31.10.2026) ————— */

    /** Un brouillon envoyé, contrôlé et renseigné, pas encore relu en entier ; la file retenue. */
    private function readyDraft(array $vet, string $base = '/api/v1/espace/videos/seller/videos'): Video
    {
        $id = $this->draft($vet['jeton'], $base);
        $this->upload($vet['jeton'], $id, $this->hlsPackage(), $base);
        $this->finish($vet['jeton'], $id, $base)->assertOk();
        $this->fillSheet("{$base}/{$id}", ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $video = Video::query()->where('uuid', $id)->firstOrFail();
        $video->forceFill(['files_checked_at' => null])->save();

        return $video;
    }

    public function test_un_second_envoi_dans_les_16_minutes_a_ses_propres_relectures(): void
    {
        Queue::fake();
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $first = Video::query()->sole()->uploadStamp();

        $this->travel(10)->minutes();
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $second = Video::query()->sole()->uploadStamp();

        $this->assertNotSame($first, $second);
        Queue::assertPushed(RecheckVideoFiles::class, 6);
        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->pass === 'late' && $job->upload === $second && $job->delay !== null);

        // Une relecture du premier envoi ne juge pas le second
        (new RecheckVideoFiles(Video::query()->sole()->id, 'now', $first))->handle(app(HlsPackage::class), app(VideoStorage::class), app(VideoPublisher::class));
        $this->assertNull(Video::query()->sole()->files_checked_at);
    }

    public function test_une_relecture_pendant_laquelle_un_nouvel_envoi_commence_ne_le_tient_pas_pour_relu(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        $this->partialMock(HlsPackage::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturnUsing(function (Video $read) {
            // Le vendeur recommence l'envoi pendant la lecture
            Video::query()->whereKey($read->id)->update(['upload_started_at' => now()->addMinute(), 'files_status' => 'pending']);

            return ['ok' => true, 'size' => 100, 'renditions' => [], 'duration' => 30.0, 'width' => 1920, 'height' => 1080, 'download_bytes' => 0];
        }));

        $this->recheck($video);

        $this->assertNull($video->refresh()->files_checked_at);
        $this->assertSame('pending', $video->files_status->value);
    }

    public function test_deux_envois_lances_ensemble_se_comptent_l_un_l_autre(): void
    {
        $vet = $this->verifiedVet();
        $kept = Video::query()->where('uuid', $this->draft($vet['jeton']))->firstOrFail();
        $kept->forceFill(['size_bytes' => 40_000_000_000])->save();
        $a = $this->draft($vet['jeton']);
        $b = $this->draft($vet['jeton']);

        $this->postJson($this->base($a).'/upload/start', ['duration_seconds' => 3600, 'estimated_bytes' => 50_000_000_000], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson($this->base($b).'/upload/start', ['duration_seconds' => 3600, 'estimated_bytes' => 50_000_000_000], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_bytes');
        // Recommencer le même envoi ne se compte pas deux fois
        $this->postJson($this->base($a).'/upload/start', ['duration_seconds' => 3600, 'estimated_bytes' => 55_000_000_000], $this->enTetes($vet['jeton']))->assertOk();

        // Une clinique : pareil dans le stockage de l'organisation
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $clinicVideo = $this->draft($jeton, '/api/v1/videos');
        $this->postJson("/api/v1/videos/{$clinicVideo}/upload/start", ['duration_seconds' => 3600, 'estimated_bytes' => 5_000_000_000], $this->enTetes($jeton))->assertOk();
        $this->assertGreaterThanOrEqual(5_000_000_000, $clinique->societe->organisation->stockageOctets());
    }

    public function test_un_brouillon_supprime_pendant_son_envoi_ne_laisse_aucun_fichier(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertOk();
        $folder = Video::query()->sole()->folder();
        Queue::fake();

        $this->deleteJson($this->base($id), [], $this->enTetes($vet['jeton']))->assertNoContent();
        Queue::assertPushed(PurgeVideoFolder::class, fn (PurgeVideoFolder $job) => $job->folder === $folder && now()->diffInSeconds($job->delay, true) >= 16 * 60 - 5);

        // L'adresse encore valable dépose un fichier après la suppression : la purge différée l'efface
        Storage::disk('prive')->put($folder.'/init-1.mp4', 'déposé après coup');
        (new PurgeVideoFolder($folder))->handle(app(VideoStorage::class));
        $this->assertSame([], Storage::disk('prive')->allFiles($folder));
    }

    public function test_une_liste_ou_un_morceau_illisible_ne_fait_jamais_un_telechargement_vide(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $light = $video->renditions[count($video->renditions) - 1];
        Storage::disk('prive')->delete($video->folder().'/'.AssembleVideoDownload::FILE);
        $video->forceFill(['download_path' => null])->save();
        $playlist = Storage::disk('prive')->get($video->folder().'/'.$light['playlist']);

        Storage::disk('prive')->delete($video->folder().'/'.$light['playlist']);
        try {
            (new AssembleVideoDownload($video->id))->handle(app(VideoStorage::class));
            $this->fail('Assemblée sans sa liste');
        } catch (\RuntimeException) {
        }
        $this->assertNull($video->refresh()->download_path);

        Storage::disk('prive')->put($video->folder().'/'.$light['playlist'], $playlist);
        $segment = collect(Storage::disk('prive')->files($video->folder()))->first(fn (string $path) => str_contains($path, 'segment-'.(count($video->renditions)).'-'));
        Storage::disk('prive')->delete($segment);
        try {
            (new AssembleVideoDownload($video->id))->handle(app(VideoStorage::class));
            $this->fail('Assemblée sans un morceau');
        } catch (\RuntimeException) {
        }
        $this->assertNull($video->refresh()->download_path);
        $this->assertFalse(Storage::disk('prive')->exists($video->folder().'/'.AssembleVideoDownload::FILE));
    }

    public function test_la_relecture_relance_l_assemblage_d_une_version_a_telecharger_qui_manque(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $video->forceFill(['download_path' => null])->save();
        Queue::fake();

        $this->recheck($video);

        Queue::assertPushed(AssembleVideoDownload::class, fn (AssembleVideoDownload $job) => $job->videoId === $video->id);
    }

    public function test_un_versement_de_la_cagnotte_dont_la_reponse_se_perd_ne_se_refait_pas(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_cagnotte', 'stripe_account_ready_at' => now()]);
        $january = PlusPoolMonth::query()->create(['month' => '2027-01', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 3, 'computed_at' => now()]);
        $first = $january->shares()->create(['seller_user_id' => $seller->id, 'views' => 3, 'earned_cents' => 3000]);
        $this->gateway->perdreLaReponseDuVersement = true;

        $this->artisan('videos:plus-pool', ['month' => '2027-01'])->assertSuccessful();
        $this->assertNull($first->refresh()->paid_at);

        $this->travelTo('2027-03-02 08:00');
        $february = PlusPoolMonth::query()->create(['month' => '2027-02', 'net_revenue_cents' => 7000, 'pool_cents' => 3500, 'views' => 4, 'computed_at' => now()]);
        $second = $february->shares()->create(['seller_user_id' => $seller->id, 'views' => 4, 'earned_cents' => 3500]);
        $this->artisan('videos:plus-pool', ['month' => '2027-02'])->assertSuccessful();

        $this->assertSame([3000, 3500], array_column($this->gateway->versements, 'centimes'));
        $this->assertSame($this->gateway->versements[0]['id'], $first->refresh()->transfer_id);
        $this->assertNotNull($first->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
    }

    public function test_une_part_attend_un_compte_ouvert_mais_pas_encore_pret(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_pas_pret', 'stripe_account_ready_at' => null]);
        $share = PlusPoolMonth::query()->create(['month' => '2027-01', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 3, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 3, 'earned_cents' => 3000]);

        $this->artisan('videos:plus-pool', ['month' => '2027-01'])->assertSuccessful();
        $this->assertSame([], $this->gateway->versements);
        $this->assertNull($share->refresh()->paid_at);

        $seller->forceFill(['stripe_account_ready_at' => now()])->save();
        $this->artisan('videos:plus-pool', ['month' => '2027-01'])->assertSuccessful();
        $this->assertSame([['compte' => 'acct_pas_pret', 'centimes' => 3000]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
    }

    public function test_un_mois_de_cagnotte_manque_se_rattrape_au_passage_suivant(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        VideoView::query()->create([
            'video_id' => Video::query()->sole()->id, 'user_id' => $plus['user']->id, 'watched_seconds' => 90,
            'counted_at' => '2027-01-15 10:00:00', 'counted_via_plus' => true,
        ]);
        $this->assertNotNull($sale['id']);
        $this->travelTo('2027-03-02 08:00');

        $this->artisan('videos:plus-pool')->assertSuccessful();

        $this->assertSame(['2027-01', '2027-02'], PlusPoolMonth::query()->orderBy('month')->pluck('month')->all());
    }

    public function test_une_demande_de_mise_en_vente_ne_survit_ni_a_un_nouvel_envoi_ni_a_un_refus(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        Queue::fake();
        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        $this->assertNotNull($video->refresh()->publish_requested_at);

        $this->postJson($this->base($video->uuid).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))->assertOk();
        $this->assertNull($video->refresh()->publish_requested_at);

        // Une demande, puis un envoi refusé au contrôle
        $video->forceFill(['publish_requested_at' => now(), 'publish_requested_by' => $vet['user']->id])->save();
        $package = $this->hlsPackage();
        $package['init-1.mp4'] = $this->initSegment(3840, 2160, 'hvc1');
        $this->upload($vet['jeton'], $video->uuid, $package);
        Video::query()->whereKey($video->id)->update(['publish_requested_at' => now()]);
        $this->finish($vet['jeton'], $video->uuid)->assertUnprocessable();
        $this->assertNull($video->refresh()->publish_requested_at);
    }

    public function test_une_video_dont_les_fichiers_sont_refuses_ne_se_met_pas_en_vente_ni_ne_s_achete(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        $stale = Video::query()->findOrFail($video->id);
        // La relecture refuse les fichiers au moment même de la mise en vente
        Video::query()->whereKey($video->id)->update(['files_status' => 'rejected']);
        try {
            app(VideoPublisher::class)->publish($stale, 'Dr Essai');
            $this->fail('Mise en vente sans fichiers');
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $video->refresh()->status->value);

        $sale = $this->published();
        Video::query()->where('uuid', $sale['id'])->update(['files_status' => 'rejected']);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertNotFound();
    }

    public function test_pour_une_clinique_la_mise_en_vente_differee_reverifie_qui_l_a_demandee(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $video = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        Queue::fake();
        $this->postJson("/api/v1/videos/{$video->uuid}/publish", [], $this->enTetes($jeton))->assertStatus(202);
        $this->assertNotNull($video->refresh()->publish_requested_by);
        // Son contrat a pris fin pendant la relecture
        Employe::withoutGlobalScopes()->where('user_id', $video->publish_requested_by)->update(['fin_contrat_le' => now()->subDay()->toDateString()]);
        Events::fake([VideoUpdated::class]);

        $this->recheck($video);

        $this->assertSame('draft', $video->refresh()->status->value);
        $this->assertNull($video->publish_requested_at);
        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => str_contains((string) $e->broadcastWith()['notice'], 'ne peut plus publier pour la clinique')
            && str_contains((string) $e->broadcastWith()['notice'], '« Nourrir un chat senior »'));
        $this->assertNotNull($clinique->id);
    }

    public function test_retiree_sans_acheteur_puis_refusee_a_la_relecture_elle_se_renvoie(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $this->postJson($this->base($sale['id']).'/withdraw', [], $this->enTetes($sale['vet']['jeton']))->assertOk();
        Storage::disk('prive')->put($video->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));
        Events::fake([VideoUpdated::class]);

        $this->recheck($video);

        $this->assertSame('draft', $video->refresh()->status->value);
        $this->assertSame('rejected', $video->files_status->value);
        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => $e->broadcastWith()['rejection_reason'] === $video->rejection_reason && $video->rejection_reason !== null);
        $this->postJson($this->base($sale['id']).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($sale['vet']['jeton']))->assertOk();
    }

    public function test_une_liste_de_qualite_illisible_est_une_panne_pas_une_video_vide(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $playlist = $video->renditions[0]['playlist'];
        Storage::disk('prive')->delete($video->folder().'/'.$playlist);

        try {
            app(VideoPlayback::class)->media($video, $playlist, 'full', now()->addHour()->getTimestamp());
            $this->fail('Liste vide servie');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_le_verrou_de_la_relecture_couvre_tous_ses_essais(): void
    {
        $job = new RecheckVideoFiles(1);
        $this->assertGreaterThanOrEqual($job->tries * $job->timeout + array_sum($job->backoff), $job->uniqueFor);
    }

    public function test_un_morceau_illisible_au_controle_est_une_panne_pas_un_refus(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('readHeads')->andReturnUsing(fn ($video, array $names) => array_fill_keys($names, '')));

        $this->finish($vet['jeton'], $id)->assertStatus(503);
        $video = Video::query()->sole();
        $this->assertSame('pending', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    /* ————— Septième vérification (31.10.2026) ————— */

    public function test_mes_formations_donne_a_un_abonne_plus_toutes_les_videos_incluses_au_dela_des_24_dernieres(): void
    {
        $vet = $this->verifiedVet();
        $old = $this->published(['in_plus' => true, 'title' => 'La plus ancienne'], $vet);
        $this->travel(1)->minutes();
        // 25 vidéos plus récentes, hors de Plus, qui la repoussent en page 2 du catalogue
        Video::factory()->count(25)->create(['seller_user_id' => $vet['user']->id, 'status' => 'published', 'audience' => 'owners', 'price_cents' => 500, 'published_at' => now()]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $free = $this->proprietaireAvecJeton();

        $this->getJson('/api/v1/espace/videos', $this->enTetes($plus['jeton']))->assertOk()->assertJsonMissing(['id' => $old['id']]);
        $library = $this->getJson('/api/v1/espace/videos/library', $this->enTetes($plus['jeton']))->assertOk();
        $library->assertJsonPath('data.0.id', $old['id'])->assertJsonPath('data.0.access', 'plus')->assertJsonPath('meta.total', 1);

        // Sans Plus, rien que ses achats
        $this->getJson('/api/v1/espace/videos/library', $this->enTetes($free['jeton']))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_une_mise_en_vente_demandee_que_la_panne_empeche_se_dit_au_vendeur(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        Queue::fake();
        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        Events::fake([VideoUpdated::class]);

        (new RecheckVideoFiles($video->id))->failed(new \RuntimeException('stockage'));

        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => str_contains((string) $e->broadcastWith()['notice'], '« Nourrir un chat senior » n’a pas pu être relue')
            && $e->broadcastWith()['publish_requested'] === false);
    }

    /* ————— Septième vérification, côté serveur (31.10.2026) ————— */

    public function test_la_relecture_et_l_assemblage_ne_touchent_jamais_les_fichiers_en_meme_temps(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $video->forceFill(['download_path' => null])->save();
        $size = $video->size_bytes;
        $lock = Video::filesLock($video->id);
        $this->assertTrue($lock->get());

        foreach ([new RecheckVideoFiles($video->id), new AssembleVideoDownload($video->id)] as $job) {
            $job->lockWait = 0;
            // La relecture seule : sa version à télécharger notée, elle ne lance pas d'assemblage qui buterait lui-même sur le verrou
            Video::query()->whereKey($video->id)->update(['download_path' => $job instanceof RecheckVideoFiles ? AssembleVideoDownload::FILE : null]);
            try {
                $job instanceof RecheckVideoFiles
                    ? $job->handle(app(HlsPackage::class), app(VideoStorage::class), app(VideoPublisher::class))
                    : $job->handle(app(VideoStorage::class));
                $this->fail($job::class.' a travaillé pendant que les fichiers étaient pris');
            } catch (LockTimeoutException) {
            }
        }
        $lock->release();

        $this->assertNull($video->refresh()->download_path);
        $this->assertSame($size, $video->size_bytes);
        $this->assertNotNull($video->files_checked_at);
    }

    public function test_un_assemblage_que_rattrape_un_nouvel_envoi_n_ecrit_rien_sur_lui(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $original = $video->only(['upload_started_at', 'files_status', 'size_bytes']);
        // Le nouvel envoi vient de commencer (fichiers en attente), ou il est déjà contrôlé (prêt, autre envoi)
        foreach (['pending', 'ready'] as $newStatus) {
            Video::query()->whereKey($video->id)->update(['download_path' => null, 'upload_started_at' => $original['upload_started_at'], 'files_status' => 'ready']);
            $video->refresh();
            $size = $video->size_bytes;
            Storage::disk('prive')->delete($video->folder().'/'.AssembleVideoDownload::FILE);
            $storage = $this->partialMock(VideoStorage::class);
            $storage->shouldReceive('concatenate')->andReturnUsing(function (Video $read) use ($newStatus) {
                Video::query()->whereKey($read->id)->update(['upload_started_at' => now()->addMinute(), 'files_status' => $newStatus]);
                Storage::disk('prive')->put($read->folder().'/'.AssembleVideoDownload::FILE, 'ancien');

                return 6;
            });

            (new AssembleVideoDownload($video->id, $video->uploadStamp()))->handle(app(VideoStorage::class));

            $this->assertNull($video->refresh()->download_path, $newStatus);
            $this->assertSame($size, $video->size_bytes, $newStatus);
            $this->assertFalse(Storage::disk('prive')->exists($video->folder().'/'.AssembleVideoDownload::FILE), $newStatus);
        }
    }

    public function test_la_page_de_l_acheteur_relance_une_version_a_telecharger_qui_manque(): void
    {
        $sale = $this->published();
        $video = Video::query()->sole();
        $buyer = $this->paidBuyer($video);
        $video->forceFill(['download_path' => null])->save();
        Queue::fake();

        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($buyer['jeton']))->assertOk()->assertJsonPath('data.can_download', false);

        Queue::assertPushed(AssembleVideoDownload::class, fn (AssembleVideoDownload $job) => $job->videoId === $video->id && $job->upload === $video->uploadStamp());
    }

    public function test_un_brouillon_dont_le_guet_a_refuse_l_envoi_programme_aussi_la_purge_de_son_dossier(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        $video = Video::query()->sole();
        Storage::disk('prive')->put($video->folder().'/segment-1-00001.m4s', str_repeat('x', 60_000_000));
        $this->artisan('videos:watch-uploads')->assertSuccessful();
        $this->assertNull($video->refresh()->upload_started_at);
        Queue::fake();

        $this->deleteJson($this->base($id), [], $this->enTetes($vet['jeton']))->assertNoContent();

        Queue::assertPushed(PurgeVideoFolder::class, fn (PurgeVideoFolder $job) => $job->folder === $video->folder());
    }

    public function test_un_nouvel_envoi_au_moment_d_une_mise_en_vente_ne_l_efface_pas(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        $video->forceFill(['files_checked_at' => now()])->save();
        // L'autre onglet met en vente pendant que celui-ci lit la vidéo pour la renvoyer
        $once = false;
        Video::retrieved(function (Video $read) use (&$once): void {
            if (! $once) {
                $once = true;
                Video::query()->whereKey($read->id)->update(['status' => 'published', 'published_at' => now()]);
            }
        });

        $this->postJson($this->base($video->uuid).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $video->refresh();
        $this->assertSame('published', $video->status->value);
        $this->assertSame('ready', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_deux_passages_de_la_cagnotte_ne_versent_jamais_en_meme_temps(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_double', 'stripe_account_ready_at' => now()]);
        PlusPoolMonth::query()->create(['month' => '2027-01', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 3, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 3, 'earned_cents' => 3000]);
        $other = Cache::lock('plus-pool-pay', 3600);
        $this->assertTrue($other->get());
        $pool = app(PlusPool::class);
        $pool->lockWait = 0;

        try {
            $pool->pay(CarbonImmutable::parse('2027-01-01'));
            $this->fail('Versé pendant un autre passage');
        } catch (LockTimeoutException) {
        }
        $this->assertSame([], $this->gateway->versements);
        $other->release();
        $pool->pay(CarbonImmutable::parse('2027-01-01'));
        $this->assertCount(1, $this->gateway->versements);
    }

    public function test_un_versement_tout_juste_parti_ne_se_refait_pas_dans_le_meme_passage(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_rattrapage', 'stripe_account_ready_at' => now()]);
        PlusPoolMonth::query()->create(['month' => '2027-01', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 3, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 3, 'earned_cents' => 3000]);
        $this->gateway->perdreLaReponseDuVersement = true;
        $this->gateway->versementsPasEncoreVisibles = true;
        $pool = app(PlusPool::class);

        // Deux mois rattrapés à la suite, dans le même processus
        $pool->pay(CarbonImmutable::parse('2026-12-01'));
        $pool->pay(CarbonImmutable::parse('2027-01-01'));

        $this->assertCount(1, $this->gateway->versements);
    }

    public function test_un_prix_change_puis_remis_le_meme_jour_se_paie_encore(): void
    {
        $sale = $this->published();
        $buyer = $this->proprietaireAvecJeton();
        $purchase = fn () => $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk()->json('data.client_secret');
        $first = $purchase();
        $this->patchJson($this->base($sale['id']), ['price_cents' => 2000], $this->enTetes($sale['vet']['jeton']))->assertOk();
        $purchase();
        $this->patchJson($this->base($sale['id']), ['price_cents' => 1500], $this->enTetes($sale['vet']['jeton']))->assertOk();

        // Pas l'intention du matin, annulée entre-temps
        $this->assertNotSame($first, $purchase());
    }

    public function test_une_video_vendue_en_son_nom_porte_le_nom_du_veterinaire(): void
    {
        $sale = $this->published();
        $this->assertSame($sale['vet']['user']->name, Video::query()->sole()->seller_name);
        $this->getJson('/api/v1/espace/videos/'.$sale['id'], $this->enTetes($this->proprietaireAvecJeton()['jeton']))->assertOk()->assertJsonPath('data.seller.name', $sale['vet']['user']->name);
    }

    public function test_pour_une_clinique_la_mise_en_vente_differee_aboutit_et_reverifie_l_identite_de_qui_l_a_demandee(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $video = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        Queue::fake();
        $this->postJson("/api/v1/videos/{$video->uuid}/publish", [], $this->enTetes($jeton))->assertStatus(202);
        $this->recheck($video);
        $this->assertSame('published', $video->refresh()->status->value);
        $this->assertSame($clinique->nom, $video->seller_name);

        // Une autre, dont la personne perd sa vérification d'identité pendant la relecture
        $other = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        $this->postJson("/api/v1/videos/{$other->uuid}/publish", [], $this->enTetes($jeton))->assertStatus(202);
        User::query()->whereKey($other->refresh()->publish_requested_by)->update(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse->value]);
        $this->recheck($other);
        $this->assertSame('draft', $other->refresh()->status->value);
    }

    public function test_un_debut_de_qualite_qui_n_est_pas_un_mp4_est_refuse_et_efface(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage();
        $package['init-1.mp4'] = 'ceci n’est pas un fichier MP4';
        $this->upload($vet['jeton'], $id, $package);

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertSame([], Storage::disk('prive')->allFiles(Video::query()->sole()->folder()));
    }

    public function test_plus_d_adresse_quand_le_depose_depasse_le_stockage_qui_reste(): void
    {
        $vet = $this->verifiedVet();
        $full = Video::query()->where('uuid', $this->draft($vet['jeton']))->firstOrFail();
        $full->forceFill(['size_bytes' => Video::PERSONAL_STORAGE_BYTES - 10_000_000])->save();
        $id = $this->draft($vet['jeton']);
        $this->postJson($this->base($id).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 1000], $this->enTetes($vet['jeton']))->assertOk();
        Storage::disk('prive')->put(Video::query()->where('uuid', $id)->firstOrFail()->folder().'/segment-1-00001.m4s', str_repeat('x', 40_000_000));

        $this->postJson($this->base($id).'/upload/urls', ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_le_certificat_porte_la_date_ou_la_video_a_ete_vue_en_entier(): void
    {
        $sale = $this->published(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($plus['jeton'], $sale['id']);
        $completed = VideoView::query()->sole()->completed_at;
        $this->travel(3)->days();

        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertOk();
        $this->assertEquals($completed, ViewingCertificate::query()->sole()->watched_at);
    }

    public function test_l_apercu_d_une_video_courte_ne_donne_jamais_de_certificat(): void
    {
        $sale = $this->published();
        $free = $this->proprietaireAvecJeton();
        $this->play($free['jeton'], $sale['id'])->assertJsonPath('data.scope', 'preview');
        for ($at = 0; $at < 30; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($free['jeton'], $sale['id'], [[$at, $at + 15]]);
        }

        $this->assertNull(VideoView::query()->sole()->completed_at);
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($free['jeton']))->assertUnprocessable();
    }

    /* ————— Huitième vérification (31.10.2026) ————— */

    public function test_au_retour_d_un_paiement_qui_n_a_pas_abouti_l_ecran_le_sait(): void
    {
        $sale = $this->published();
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $intent = VideoPurchase::query()->sole()->payment_intent_id;
        $confirm = fn () => $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase/confirm", [], $this->enTetes($buyer['jeton']))->assertOk();

        // Encore en cours (TWINT pas encore confirmé)
        $this->gateway->conclure($intent, 'processing');
        $confirm()->assertJsonPath('data.paid', false)->assertJsonPath('data.failed', false);
        // Annulé dans l'app de la banque : rien n'a été débité
        $this->gateway->conclure($intent, 'requires_payment_method');
        $confirm()->assertJsonPath('data.paid', false)->assertJsonPath('data.failed', true);
    }

    public function test_le_seuil_du_certificat_vient_du_serveur(): void
    {
        $sale = $this->published(['in_plus' => true], null, 30);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($plus['jeton']))->assertOk()
            ->assertJsonPath('data.viewing.required_seconds', (int) ceil(Video::query()->sole()->duration_seconds * VideoView::COMPLETION_RATIO));
    }

    /* ————— Huitième vérification, côté serveur (31.10.2026) ————— */

    public function test_un_controle_final_rejoue_ne_relit_ni_n_efface_rien(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $video = Video::query()->sole();
        $before = $video->only(['size_bytes', 'download_path', 'files_status']);
        $this->assertTrue(Storage::disk('prive')->exists($video->folder().'/'.AssembleVideoDownload::FILE));

        // La réponse s'est perdue : l'écran redemande le contrôle, qui ne relit rien
        Queue::fake();
        $package = $this->spy(HlsPackage::class);
        $this->finish($vet['jeton'], $id)->assertOk()->assertJsonPath('data.files_status', 'ready');
        $package->shouldNotHaveReceived('inspect');
        // La version à télécharger déjà là : pas d'assemblage de plus, seulement les relectures
        Queue::assertNotPushed(AssembleVideoDownload::class);

        $this->assertSame($before, $video->refresh()->only(['size_bytes', 'download_path', 'files_status']));
        $this->assertTrue(Storage::disk('prive')->exists($video->folder().'/'.AssembleVideoDownload::FILE));
        $this->app->forgetInstance(HlsPackage::class);
        RecheckVideoFiles::dispatchSync($video->id);
        $this->assertSame('ready', $video->refresh()->files_status->value);
    }

    public function test_une_version_a_telecharger_posee_sans_etre_notee_n_est_pas_un_ajout(): void
    {
        $this->published();
        $video = Video::query()->sole();
        // L'assemblage a écrit au bucket, puis s'est arrêté avant de le noter
        $video->forceFill(['download_path' => null])->save();
        $size = $video->size_bytes;
        Storage::disk('prive')->put($video->folder().'/'.AssembleVideoDownload::FILE, str_repeat('x', 3_000_000));
        Queue::fake();

        $this->recheck($video);

        $video->refresh();
        $this->assertSame('ready', $video->files_status->value);
        $this->assertSame('published', $video->status->value);
        $this->assertLessThanOrEqual($size, $video->size_bytes);
        Queue::assertPushed(AssembleVideoDownload::class);
    }

    public function test_un_assemblage_rejoue_ne_compte_pas_deux_fois_le_telechargement(): void
    {
        $this->published();
        $video = Video::query()->sole();
        $size = $video->size_bytes;

        (new AssembleVideoDownload($video->id, $video->uploadStamp()))->handle(app(VideoStorage::class));

        $this->assertSame($size, $video->refresh()->size_bytes);
        $this->assertSame(AssembleVideoDownload::FILE, $video->download_path);
    }

    public function test_une_mise_en_vente_ne_se_pose_pas_sur_un_nouvel_envoi_lance_ailleurs(): void
    {
        $vet = $this->verifiedVet();
        Queue::fake();
        // L'autre onglet recommence l'envoi juste après que celui-ci a lu la vidéo : en cours, ou déjà contrôlé
        foreach (['pending', 'ready'] as $state) {
            $video = $this->readyDraft($vet);
            $once = false;
            Video::retrieved(function (Video $read) use (&$once, $video, $state): void {
                if (! $once && $read->id === $video->id) {
                    $once = true;
                    Video::query()->whereKey($read->id)->update(['files_status' => $state, 'upload_started_at' => now()->addMinute()]);
                }
            });

            $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('files');
            $this->assertNull($video->refresh()->publish_requested_at, $state);
        }
    }

    public function test_une_purge_de_dossier_qui_echoue_se_redit_et_se_refait(): void
    {
        $this->partialMock(VideoStorage::class, fn ($mock) => $mock->shouldReceive('deleteFolder')->andReturn(false));
        $this->expectException(\RuntimeException::class);

        (new PurgeVideoFolder('videos/essai'))->handle(app(VideoStorage::class));
    }

    public function test_une_video_tres_courte_se_certifie_quand_on_l_a_vue_en_entier(): void
    {
        $this->assertSame(10, VideoView::requiredSeconds(10.9));
        $this->assertSame(75, VideoView::requiredSeconds(78.0));
        $this->assertSame(29, VideoView::requiredSeconds(30.0));
        $this->assertSame(0, VideoView::requiredSeconds(0.4));
    }

    public function test_mes_formations_ne_montre_ni_les_achats_des_autres_ni_les_achats_pas_payes_ni_les_videos_plus_qu_on_ne_peut_pas_voir(): void
    {
        $vet = $this->verifiedVet();
        // Incluse dans Plus, mais son vendeur ne vend plus : elle n'est plus regardable
        $stopped = $this->verifiedVet();
        $vetsOnly = $this->published(['in_plus' => true, 'title' => 'Vendeur qui ne vend plus'], $stopped);
        $stopped['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();
        $unpaid = $this->published(['title' => 'Pas payée'], $vet);
        $others = $this->published(['title' => 'Achetée par un autre'], $vet);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $someone = $this->proprietaireAvecJeton();
        VideoPurchase::query()->create([
            'video_id' => Video::query()->where('uuid', $unpaid['id'])->value('id'), 'user_id' => $plus['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'payment_intent_id' => 'pi_en_cours',
        ]);
        VideoPurchase::query()->create([
            'video_id' => Video::query()->where('uuid', $others['id'])->value('id'), 'user_id' => $someone['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now(), 'paid_at' => now(),
        ]);

        $library = $this->getJson('/api/v1/espace/videos/library', $this->enTetes($plus['jeton']))->assertOk();
        $ids = collect($library->json('data'))->pluck('id')->all();
        // Ni dans la page, ni dans le compte des pages
        $this->assertSame(0, $library->json('meta.total'));

        $this->assertNotContains($vetsOnly['id'], $ids);
        $this->assertNotContains($unpaid['id'], $ids);
        $this->assertNotContains($others['id'], $ids);
    }

    public function test_un_groupe_de_virement_tout_juste_parti_ne_se_cherche_pas_avant_une_heure_meme_dans_un_autre_passage(): void
    {
        $this->travelTo('2027-02-02 08:00');
        $seller = User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'stripe_account_id' => 'acct_heure', 'stripe_account_ready_at' => now()]);
        PlusPoolMonth::query()->create(['month' => '2027-01', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 3, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $seller->id, 'views' => 3, 'earned_cents' => 3000]);
        $this->gateway->perdreLaReponseDuVersement = true;
        $this->gateway->versementsPasEncoreVisibles = true;

        (new PlusPool($this->gateway))->pay(CarbonImmutable::parse('2027-01-01'));
        // Un passage lancé à la main dix minutes plus tard, dans un autre processus
        $this->travel(10)->minutes();
        (new PlusPool($this->gateway))->pay(CarbonImmutable::parse('2027-01-01'));
        $this->assertCount(1, $this->gateway->versements);

        // Une heure plus tard, le prestataire le montre : il est noté, jamais refait
        $this->travel(2)->hours();
        $this->gateway->versementsPasEncoreVisibles = false;
        (new PlusPool($this->gateway))->pay(CarbonImmutable::parse('2027-01-01'));
        $this->assertCount(1, $this->gateway->versements);
        $this->assertNotNull(PlusPoolShare::query()->sole()->paid_at);
    }

    /* ————— Neuvième vérification (31.10.2026) ————— */

    public function test_a_l_effacement_du_compte_les_videos_sans_acheteur_s_effacent_et_les_achetees_restent(): void
    {
        $vet = $this->verifiedVet();
        $sold = $this->published(['title' => 'Achetée'], $vet);
        $soldVideo = Video::query()->where('uuid', $sold['id'])->firstOrFail();
        $buyer = $this->paidBuyer($soldVideo);
        $certified = $this->published(['in_plus' => true, 'title' => 'Vue par Plus'], $vet);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($plus['jeton'], $certified['id']);
        $this->postJson("/api/v1/espace/videos/{$certified['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertOk();
        $code = ViewingCertificate::query()->sole()->code;
        $draft = Video::query()->where('uuid', $this->draft($vet['jeton']))->firstOrFail();
        $this->upload($vet['jeton'], $draft->uuid, $this->hlsPackage());
        $this->finish($vet['jeton'], $draft->uuid)->assertOk();
        $certifiedVideo = Video::query()->where('uuid', $certified['id'])->firstOrFail();
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();

        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));
        // Les envois de l'essai ont usé la limite de requêtes par minute de l'adresse
        $this->travel(2)->minutes();

        // Le brouillon : parti, fichiers compris
        $this->assertNull(Video::query()->find($draft->id));
        $this->assertSame([], Storage::disk('prive')->allFiles($draft->folder()));
        // Vue par un abonné Plus, jamais achetée : fichiers effacés, fiche vide gardée pour le certificat
        $certifiedVideo->refresh();
        $this->assertSame([], Storage::disk('prive')->allFiles($certifiedVideo->folder()));
        $this->assertNull($certifiedVideo->title);
        $this->assertSame('', $certifiedVideo->seller_name);
        $this->getJson('/api/v1/public/certificates/'.$code)->assertOk();
        // Achetée : elle reste à son acheteur, hors de la vente
        $soldVideo->refresh();
        $this->assertSame('withdrawn', $soldVideo->status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($soldVideo->folder()));
        $this->play($buyer['jeton'], $sold['id'])->assertOk();
    }

    public function test_un_controle_rejoue_relance_les_relectures_et_la_derniere_passe_deux_heures_apres(): void
    {
        Queue::fake();
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->pass === 'final' && now()->diffInMinutes($job->delay, true) >= 119);

        // Prête (les tâches de la première requête perdues) : le contrôle rejoué les relance
        $video = Video::query()->sole();
        $video->forceFill(['files_status' => 'ready'])->save();
        Queue::fake();
        $this->finish($vet['jeton'], $id)->assertOk();
        Queue::assertPushed(RecheckVideoFiles::class, 3);
        Queue::assertPushed(AssembleVideoDownload::class, 1);
    }

    public function test_une_video_de_10_9_secondes_vue_en_entier_donne_son_certificat(): void
    {
        $sale = $this->published(['in_plus' => true], null, 10.9);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->assertEqualsWithDelta(10.9, (float) Video::query()->sole()->duration_seconds, 0.01);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->travel(11)->seconds();
        $this->progress($plus['jeton'], $sale['id'], [[0, 10.9]])->assertJsonPath('data.completed', true);
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($plus['jeton']))->assertJsonPath('data.viewing.required_seconds', 10);
    }

    public function test_une_vue_compte_au_dela_d_une_minute_pas_a_60_secondes_pile(): void
    {
        $sale = $this->published(['in_plus' => true], null, 120);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        for ($at = 0; $at < 60; $at += 15) {
            $this->travel(15)->seconds();
            $this->progress($plus['jeton'], $sale['id'], [[$at, $at + 15]]);
        }
        $this->assertSame(60, VideoView::query()->sole()->watched_seconds);
        $this->assertNull(VideoView::query()->sole()->counted_at);
        $this->travel(15)->seconds();
        $this->progress($plus['jeton'], $sale['id'], [[60, 75]])->assertJsonPath('data.counted', true);
    }

    public function test_une_societe_sans_paiements_actifs_ne_vend_pas(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $video = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        $video->forceFill(['files_checked_at' => now()])->save();
        $clinique->societe->forceFill(['paiements_actifs' => false])->save();

        $this->postJson("/api/v1/videos/{$video->uuid}/publish", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertSame('draft', $video->refresh()->status->value);
    }

    public function test_plus_de_1080p_est_refuse_meme_sous_la_4k(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $package = $this->hlsPackage();
        $package['init-1.mp4'] = $this->initSegment(2560, 1440, 'avc1');
        $this->upload($vet['jeton'], $id, $package);

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_une_fiche_videe_pendant_la_relecture_ne_se_met_pas_en_vente(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        Queue::fake();
        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        Video::query()->whereKey($video->id)->update(['description' => null]);

        $this->recheck($video);

        $this->assertSame('draft', $video->refresh()->status->value);
        $this->assertNull($video->publish_requested_at);
    }

    public function test_un_brouillon_ne_se_retire_pas_de_la_vente(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);

        $this->postJson($this->base($video->uuid).'/withdraw', [], $this->enTetes($vet['jeton']))->assertUnprocessable();
        $this->assertSame('draft', $video->refresh()->status->value);
        $this->assertNull($video->withdrawn_at);
    }

    /* ————— Dixième vérification (31.10.2026) ————— */

    private function eraseSeller(array $vet): void
    {
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));
        $this->travel(2)->minutes();
    }

    public function test_un_paiement_ouvert_la_veille_n_empeche_pas_d_effacer_la_video_et_s_annule(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $video = Video::query()->sole();
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $purchase = VideoPurchase::query()->sole();

        $this->eraseSeller($vet);

        $this->assertNull(Video::query()->find($video->id));
        $this->assertSame([], Storage::disk('prive')->allFiles($video->folder()));
        $this->assertSame('canceled', $this->gateway->pourCompte($purchase->stripe_account_id)->lireIntention($purchase->payment_intent_id)['statut']);
    }

    public function test_un_paiement_ouvert_mais_deja_paye_garde_la_video_a_son_acheteur(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        // Payé chez Stripe, le webhook pas encore arrivé
        $this->gateway->conclure(VideoPurchase::query()->sole()->payment_intent_id, 'succeeded');

        $this->eraseSeller($vet);

        $video = Video::query()->sole();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertNotNull(VideoPurchase::query()->sole()->paid_at);
        $this->play($buyer['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($buyer['jeton']))->assertJsonPath('data.seller.kind', 'vet');
    }

    public function test_le_compte_de_paiement_du_vendeur_efface_se_ferme_et_une_trace_ne_le_garde_pas(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $this->eraseSeller($vet);
        // Noté à fermer ; la tâche de nuit le ferme
        $this->assertSame([], $this->gateway->comptesFermes);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([$account], $this->gateway->comptesFermes);
        $this->assertNotNull(SellerAccountClosure::query()->where('stripe_account_id', $account)->value('closed_at'));

        // Un ancien membre d'équipe : sa trace reste, sans son compte de paiement
        $member = $this->verifiedVet();
        Employe::factory()->create(['user_id' => $member['user']->id, 'acces_suspendu' => true]);
        $memberAccount = $member['user']->stripe_account_id;
        $this->eraseSeller($member);
        $trace = User::query()->findOrFail($member['user']->id);
        $this->assertNull($trace->stripe_account_id);
        $this->assertNull($trace->stripe_account_ready_at);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertContains($memberAccount, $this->gateway->comptesFermes);
    }

    public function test_qui_a_tout_vu_sans_demander_son_certificat_le_recoit_avant_que_la_fiche_se_vide(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published(['in_plus' => true], $vet);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $other = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->watchAll($plus['jeton'], $sale['id']);
        $this->play($other['jeton'], $sale['id'])->assertOk();
        $video = Video::query()->sole();
        VideoPurchase::query()->create([
            'video_id' => $video->id, 'user_id' => $other['user']->id, 'amount_cents' => 1500, 'commission_cents' => 450,
            'stripe_account_id' => 'acct_vet', 'terms_accepted_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

        $this->eraseSeller($vet);

        $this->assertSame([$plus['user']->id], ViewingCertificate::query()->pluck('user_id')->all());
        $video->refresh();
        $this->assertNull($video->title);
        $this->assertNull($video->description);
        $this->assertFalse($video->in_plus);
        $this->assertSame('rejected', $video->files_status->value);
        $this->assertSame(0, VideoView::query()->count());
        $this->assertSame(0, VideoPurchase::query()->count());
        $this->getJson('/api/v1/public/certificates/'.ViewingCertificate::query()->sole()->code)->assertOk();
    }

    public function test_une_video_achetee_d_un_vendeur_efface_ne_se_remet_pas_en_vente_toute_seule(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        $this->paidBuyer($video);
        $video->forceFill(['publish_requested_at' => now(), 'publish_requested_by' => $vet['user']->id])->save();

        $this->eraseSeller($vet);

        $this->assertNull($video->refresh()->publish_requested_at);
    }

    public function test_un_controle_rejoue_sur_une_video_deja_relue_ne_relit_rien(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->assertNotNull(Video::query()->sole()->files_checked_at);
        Queue::fake();

        $this->finish($vet['jeton'], $id)->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_tout_proprietaire_regarde_l_apercu_et_achete_une_video_de_clinique_et_seul_l_acheteur_telecharge(): void
    {
        ['jeton' => $jeton] = $this->clinicSeller();
        $video = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        $video->forceFill(['files_checked_at' => now(), 'in_plus' => true])->save();
        $this->postJson("/api/v1/videos/{$video->uuid}/publish", [], $this->enTetes($jeton))->assertOk();
        $stranger = $this->proprietaireAvecJeton();
        $this->play($stranger['jeton'], $video->uuid)->assertOk()->assertJsonPath('data.scope', 'preview');
        $this->postJson("/api/v1/espace/videos/{$video->uuid}/purchase", ['accept_terms' => true], $this->enTetes($stranger['jeton']))->assertOk();

        // Un abonné Plus regarde en entier, mais ne télécharge pas : le téléchargement est aux acheteurs
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $this->play($plus['jeton'], $video->uuid)->assertJsonPath('data.scope', 'full');
        $this->getJson("/api/v1/espace/videos/{$video->uuid}/download", $this->enTetes($plus['jeton']))->assertNotFound();
    }

    public function test_le_verrou_de_la_relecture_couvre_aussi_la_derniere_passe(): void
    {
        $job = new RecheckVideoFiles(1, 'final');
        $this->assertGreaterThanOrEqual(2 * 3600 + $job->tries * $job->timeout + array_sum($job->backoff), $job->uniqueFor);
    }

    /* ————— Onzième vérification (31.10.2026) ————— */

    public function test_une_annulation_dont_la_reponse_s_est_perdue_n_empeche_pas_d_effacer_la_video_la_nuit_suivante(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $video = Video::query()->sole();
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        // Première nuit : l'annulation passe chez Stripe, mais sa réponse se perd
        $this->gateway->avantAnnulation = function (string $intent): void {
            $this->gateway->conclure($intent, 'canceled');
            throw new ErreurPaiement('Stripe ne répond pas.', 'api_error', 503);
        };
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        try {
            app(SuppressionCompte::class)->effacer($vet['user']);
            $this->fail('Effacé malgré la panne');
        } catch (ErreurPaiement) {
        }
        $this->assertNotNull(User::query()->find($vet['user']->id));

        // Nuit suivante : Stripe refuse d'annuler une intention déjà annulée ; relue, elle est tombée
        $this->gateway->avantAnnulation = null;
        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']->refresh()));
        $this->assertNull(Video::query()->find($video->id));
        $this->assertSame(0, VideoPurchase::query()->count());
    }

    public function test_un_compte_qui_refuse_de_se_fermer_est_retente_chaque_nuit_puis_signale(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $this->eraseSeller($vet);
        $closure = SellerAccountClosure::query()->where('stripe_account_id', $account)->firstOrFail();

        // Une panne : notée, la nuit suivante réessaie
        $this->gateway->erreurs['fermerCompteVendeur'] = new ErreurPaiement('Stripe ne répond pas.', 'api_error', 503);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $closure->refresh();
        $this->assertSame(1, $closure->attempts);
        $this->assertSame('api_error', $closure->last_error);
        $this->assertNull($closure->closed_at);
        // Un refus (solde pas encore versé) : compté, retenté
        $this->gateway->erreurs['fermerCompteVendeur'] = new ErreurPaiement('Solde à verser', 'cannot_delete_account_with_balance', 400);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $closure->refresh();
        $this->assertSame(2, $closure->attempts);
        $this->assertSame('cannot_delete_account_with_balance', $closure->last_error);
        $this->assertNull($closure->closed_at);
        // Toujours ouvert après 60 jours : signalé une fois, à fermer à la main
        $this->travel(61)->days();
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertNotNull($closure->refresh()->reported_at);
        // Le solde versé : fermé
        unset($this->gateway->erreurs['fermerCompteVendeur']);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertNotNull($closure->refresh()->closed_at);
        $this->assertSame([$account], $this->gateway->comptesFermes);
    }

    public function test_un_compte_deja_ferme_une_nuit_d_avant_est_note_sans_etre_referme(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $this->eraseSeller($vet);
        // La fermeture a eu lieu chez Stripe, mais sa réponse s'est perdue
        $this->gateway->comptesFermes[] = $account;
        $this->gateway->erreurs['fermerCompteVendeur'] = new ErreurPaiement('Compte fermé', 'account_closed', 403);

        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertNotNull(SellerAccountClosure::query()->where('stripe_account_id', $account)->value('closed_at'));
    }

    public function test_un_paiement_en_cours_a_la_banque_garde_la_video_hors_de_la_vente(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        // TWINT en cours : ni payé, et Stripe refuse de l'annuler
        $this->gateway->conclure(VideoPurchase::query()->sole()->payment_intent_id, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();

        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));

        $video = Video::query()->sole();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
        $this->assertSame(1, VideoPurchase::query()->count());
    }

    public function test_un_vendeur_qui_se_reconnecte_pendant_l_effacement_garde_son_compte_de_paiement(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $abonnements = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $abonnements);
        $vet['user']->forceFill(['stripe_client_id' => 'cus_vet'])->save();
        // Il se reconnecte au moment où le client Stripe est supprimé
        $abonnements->pendantSuppressionClient = fn () => User::query()->whereKey($vet['user']->id)->update(['suppression_demandee_le' => null]);

        $this->assertFalse(app(SuppressionCompte::class)->effacer($vet['user']));

        // Rien n'est fermé : il vend comme avant
        $this->assertSame($account, User::query()->findOrFail($vet['user']->id)->stripe_account_id);
        $this->assertSame(0, SellerAccountClosure::query()->count());
        $this->assertSame([], $this->gateway->comptesFermes);
    }

    public function test_un_paiement_conclu_au_moment_de_l_annulation_garde_la_video_a_son_acheteur(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $this->gateway->avantAnnulation = fn (string $intent) => $this->gateway->conclure($intent, 'succeeded');
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();

        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));
        $this->travel(2)->minutes();

        $this->assertNotNull(VideoPurchase::query()->sole()->paid_at);
        $this->play($buyer['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
    }

    public function test_un_paiement_en_cours_a_la_banque_est_relu_chaque_nuit_et_s_il_tombe_la_video_s_efface(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $sale = $this->published([], $vet);
        $video = Video::query()->sole();
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $intent = VideoPurchase::query()->sole()->payment_intent_id;
        $this->gateway->conclure($intent, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $this->eraseSeller($vet);
        $this->assertSame('withdrawn', $video->refresh()->status->value);

        // Encore en cours à la banque : la vidéo reste, le compte n'est pas fermé
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertNotNull(Video::query()->find($video->id));
        $this->assertSame([], $this->gateway->comptesFermes);

        // Le paiement tombe : la vidéo, que plus rien ne retient, s'efface, puis le compte se ferme
        $this->gateway->conclure($intent, 'requires_payment_method');
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertNull(Video::query()->find($video->id));
        $this->assertSame([], Storage::disk('prive')->allFiles($video->folder()));
        $this->assertSame([$account], $this->gateway->comptesFermes);
    }

    public function test_un_paiement_en_cours_a_la_banque_qui_aboutit_garde_la_video_a_son_acheteur(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $intent = VideoPurchase::query()->sole()->payment_intent_id;
        $this->gateway->conclure($intent, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $this->eraseSeller($vet);

        $this->gateway->conclure($intent, 'succeeded');
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertNotNull(VideoPurchase::query()->sole()->paid_at);
        $this->play($buyer['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
    }

    public function test_a_l_effacement_la_cagnotte_deja_calculee_est_versee_meme_sous_10_chf_avant_la_fermeture(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 1200, 'pool_cents' => 600, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 600]);

        $this->eraseSeller($vet);
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertSame([['compte' => $account, 'centimes' => 600]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
        $this->assertNotNull(PlusPoolShare::query()->sole()->paid_at);
        $this->assertSame([$account], $this->gateway->comptesFermes);
        // Versé avant la fermeture du compte de paiement
        $order = array_column($this->gateway->appels, 'methode');
        $this->assertLessThan(array_search('fermerCompteVendeur', $order, true), array_search('transferer', $order, true));
    }

    public function test_un_versement_de_cagnotte_encore_en_route_fait_attendre_l_effacement(): void
    {
        $vet = $this->verifiedVet();
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 3000, 'payout_group' => 'plus-pool-user-en-route']);
        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();

        try {
            app(SuppressionCompte::class)->effacer($vet['user']);
            $this->fail('Effacé avec un versement en route');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull(User::query()->find($vet['user']->id)?->stripe_account_id);
        $this->assertSame([], $this->gateway->comptesFermes);
    }

    public function test_les_vues_d_une_video_sans_vendeur_reviennent_aux_autres_vendeurs(): void
    {
        $this->travelTo('2026-11-15 10:00');
        $gone = $this->published(['in_plus' => true, 'title' => 'Vendeur parti']);
        $kept = $this->published(['in_plus' => true, 'title' => 'Vendeur présent']);
        $goneVideo = Video::query()->where('uuid', $gone['id'])->firstOrFail();
        $keptVideo = Video::query()->where('uuid', $kept['id'])->firstOrFail();
        foreach ([$goneVideo, $keptVideo] as $video) {
            VideoView::query()->create(['video_id' => $video->id, 'user_id' => $this->proprietaireAvecJeton()['user']->id, 'watched_seconds' => 90, 'counted_at' => now(), 'counted_via_plus' => true]);
        }
        // Le vendeur de la première a effacé son compte : la vidéo achetée reste, sans vendeur
        $goneVideo->forceFill(['seller_user_id' => null])->save();

        $month = app(PlusPool::class)->compute(CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(1, $month->views);
        $this->assertSame([$keptVideo->seller_user_id], $month->shares()->pluck('seller_user_id')->all());
    }

    /* ————— Conditions de vente des vidéos (31.10.2026, demande de Jim) ————— */

    public function test_la_premiere_mise_en_vente_demande_d_accepter_les_conditions_de_vente_une_seule_fois(): void
    {
        $vet = $this->verifiedVet();
        $vet['user']->forceFill(['video_terms_version' => null, 'video_terms_accepted_at' => null])->save();
        $video = $this->readyDraft($vet);
        $video->forceFill(['files_checked_at' => now()])->save();
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($vet['jeton']))
            ->assertJsonPath('meta.terms', ['version' => SellerTerms::VERSION, 'accepted' => false]);

        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('terms');
        $this->assertSame('draft', $video->refresh()->status->value);

        $this->postJson($this->base($video->uuid).'/publish', ['accept_terms' => true], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');
        $user = $vet['user']->refresh();
        $this->assertSame(SellerTerms::VERSION, $user->video_terms_version);
        $this->assertNotNull($user->video_terms_accepted_at);
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($vet['jeton']))->assertJsonPath('meta.terms.accepted', true);

        // Une deuxième vidéo : plus rien à cocher
        $second = $this->readyDraft($vet);
        $second->forceFill(['files_checked_at' => now()])->save();
        $this->postJson($this->base($second->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_une_nouvelle_version_des_conditions_se_reaccepte(): void
    {
        $vet = $this->verifiedVet();
        $vet['user']->forceFill(['video_terms_version' => '2026-01-01'])->save();
        $video = $this->readyDraft($vet);
        $video->forceFill(['files_checked_at' => now()])->save();

        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('terms');
    }

    public function test_pour_une_clinique_les_conditions_s_acceptent_pour_l_organisation_par_qui_publie(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $organisation = $clinique->societe->organisation;
        $organisation->forceFill(['video_terms_version' => null, 'video_terms_accepted_at' => null])->save();
        $video = $this->readyDraft(['jeton' => $jeton], '/api/v1/videos');
        $video->forceFill(['files_checked_at' => now()])->save();

        $this->postJson("/api/v1/videos/{$video->uuid}/publish", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('terms');
        $this->postJson("/api/v1/videos/{$video->uuid}/publish", ['accept_terms' => true], $this->enTetes($jeton))->assertOk();

        $organisation->refresh();
        $this->assertSame(SellerTerms::VERSION, $organisation->video_terms_version);
        $this->assertSame(Employe::withoutGlobalScopes()->where('organisation_id', $organisation->id)->value('user_id'), $organisation->video_terms_accepted_by);
        $this->getJson('/api/v1/videos', $this->enTetes($jeton))->assertJsonPath('meta.terms.accepted', true);
    }

    /* ————— Douzième vérification (31.10.2026) ————— */

    public function test_un_versement_de_cagnotte_refuse_net_n_empeche_pas_l_effacement(): void
    {
        $vet = $this->verifiedVet();
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 3000]);
        $this->gateway->erreurs['transferer'] = new ErreurPaiement('Le compte ne reçoit pas de virements', 'transfers_not_allowed', 400);

        $this->eraseSeller($vet);

        $this->assertNull(User::query()->find($vet['user']->id));
        $this->assertSame([], $this->gateway->versements);
    }

    public function test_les_videos_gardees_d_une_trace_ne_prennent_plus_de_part_de_la_cagnotte(): void
    {
        $this->travelTo('2026-11-10 10:00');
        $member = $this->verifiedVet();
        Employe::factory()->create(['user_id' => $member['user']->id, 'acces_suspendu' => true]);
        $sale = $this->published(['in_plus' => true], $member);
        $video = Video::query()->sole();
        $this->paidBuyer($video);
        VideoView::query()->create(['video_id' => $video->id, 'user_id' => $this->proprietaireAvecJeton()['user']->id, 'watched_seconds' => 90, 'counted_at' => now(), 'counted_via_plus' => true]);

        $this->eraseSeller($member);

        $this->assertNull($video->refresh()->seller_user_id);
        $this->assertNotNull($sale['id']);
        $month = app(PlusPool::class)->compute(CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(0, $month->views);
        $this->assertSame(0, $month->shares()->count());
    }

    /* ————— Treizième vérification (31.10.2026) ————— */

    public function test_un_vendeur_efface_le_1er_avant_le_calcul_recoit_sa_part_du_mois_passe(): void
    {
        $this->travelTo('2026-11-20 10:00');
        $vet = $this->verifiedVet();
        $other = $this->verifiedVet();
        $mine = $this->published(['in_plus' => true], $vet);
        $theirs = $this->published(['in_plus' => true], $other);
        foreach ([$mine['id'], $theirs['id']] as $id) {
            VideoView::query()->create(['video_id' => Video::query()->where('uuid', $id)->value('id'), 'user_id' => $this->proprietaireAvecJeton()['user']->id, 'watched_seconds' => 90, 'counted_at' => now(), 'counted_via_plus' => true]);
        }
        $plusUser = $this->proprietaireAvecJeton(['formule' => 'plus']);
        FactureProprietaire::query()->forceCreate([
            'user_id' => $plusUser['user']->id, 'numero' => 'P-2026-777', 'emise_le' => now(), 'libelle' => 'Plus', 'montant' => 24, 'montant_ht' => 24, 'statut' => 'payee', 'paye_le' => now(),
            'stripe_paiement_id' => null, 'periode_debut' => '2026-11-01', 'periode_fin' => '2026-12-01', 'metadonnees' => ['type' => 'abonnement', 'formule' => 'plus'],
        ]);

        // Effacé le 1er décembre à 03:45, avant le calcul de 05:00
        $this->travelTo('2026-12-01 03:45');
        $this->eraseSeller($vet);

        $this->assertNotNull(PlusPoolMonth::query()->where('month', '2026-11')->first());
        $this->assertSame([$vet['user']->stripe_account_id], array_values(array_unique(array_column($this->gateway->versements, 'compte'))));
    }

    public function test_une_part_refusee_a_l_effacement_est_versee_avant_la_fermeture_du_compte(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 3000]);
        // Le compte ne reçoit pas encore de virements : refusé le soir de l'effacement
        $this->gateway->erreurs['transferer'] = new ErreurPaiement('Le compte ne reçoit pas de virements', 'transfers_not_allowed', 400);
        $this->eraseSeller($vet);

        // La nuit (plus d'une heure après) : refusé de nouveau, le compte reste ouvert
        $this->travel(2)->hours();
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([], $this->gateway->comptesFermes);
        // Les virements activés : la part part, puis le compte se ferme
        unset($this->gateway->erreurs['transferer']);
        $this->travel(2)->hours();
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([['compte' => $account, 'centimes' => 3000]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
        $this->assertNotNull(PlusPoolShare::query()->sole()->paid_at);
        $this->assertSame([$account], $this->gateway->comptesFermes);
    }

    public function test_un_achat_tombe_apres_l_effacement_a_son_intention_annulee(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $purchase = VideoPurchase::query()->sole();
        $this->gateway->conclure($purchase->payment_intent_id, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $this->eraseSeller($vet);

        // La banque refuse : l'intention revient « à payer » ; la tâche l'annule avant de supprimer l'achat
        unset($this->gateway->erreurs['annulerIntention']);
        $this->gateway->conclure($purchase->payment_intent_id, 'requires_payment_method');
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertSame('canceled', $this->gateway->pourCompte($purchase->stripe_account_id)->lireIntention($purchase->payment_intent_id)['statut']);
        $this->assertSame(0, VideoPurchase::query()->count());
    }

    public function test_une_video_retenue_par_un_autre_acheteur_ne_s_efface_pas_quand_un_paiement_tombe(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $video = Video::query()->sole();
        $paid = $this->paidBuyer($video);
        $pending = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($pending['jeton']))->assertOk();
        $intent = VideoPurchase::query()->whereNull('paid_at')->sole()->payment_intent_id;
        $this->gateway->conclure($intent, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $this->eraseSeller($vet);
        unset($this->gateway->erreurs['annulerIntention']);
        $this->gateway->conclure($intent, 'requires_payment_method');

        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertNotNull(Video::query()->find($video->id));
        // La tâche va au bout sans erreur : le compte se ferme
        $this->assertSame([$vet['user']->stripe_account_id], $this->gateway->comptesFermes);
        $this->play($paid['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
        // Son nom reste affiché à son acheteur
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($paid['jeton']))->assertJsonPath('data.seller.name', $vet['user']->name);
    }

    public function test_la_mise_en_vente_differee_attend_les_nouvelles_conditions(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        Queue::fake();
        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertStatus(202);
        // Une nouvelle version des conditions paraît pendant la relecture
        $vet['user']->forceFill(['video_terms_version' => '2026-01-01'])->save();
        Events::fake([VideoUpdated::class]);

        $this->recheck($video);

        $this->assertSame('draft', $video->refresh()->status->value);
        Events::assertDispatched(VideoUpdated::class, fn (VideoUpdated $e): bool => str_contains((string) $e->broadcastWith()['notice'], 'conditions de vente des vidéos ont changé'));
    }

    public function test_une_part_d_un_compte_pas_encore_pret_a_l_effacement_est_versee_avant_la_fermeture(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $vet['user']->forceFill(['stripe_account_ready_at' => null])->save();
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 1200, 'pool_cents' => 600, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 600]);

        $this->eraseSeller($vet);
        $this->assertSame([], $this->gateway->versements);

        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([['compte' => $account, 'centimes' => 600]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
        $this->assertSame([$account], $this->gateway->comptesFermes);
    }

    /* ————— Quatorzième vérification (31.10.2026) ————— */

    public function test_la_part_refusee_d_un_ancien_membre_est_versee_avant_la_fermeture_de_son_compte(): void
    {
        $member = $this->verifiedVet();
        $account = $member['user']->stripe_account_id;
        Employe::factory()->create(['user_id' => $member['user']->id, 'acces_suspendu' => true]);
        PlusPoolMonth::query()->create(['month' => '2026-09', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $member['user']->id, 'views' => 2, 'earned_cents' => 3000]);
        $this->gateway->erreurs['transferer'] = new ErreurPaiement('Le compte ne reçoit pas de virements', 'transfers_not_allowed', 400);
        $this->eraseSeller($member);
        $this->assertNotNull(User::query()->find($member['user']->id));
        $this->assertNull(PlusPoolShare::query()->sole()->seller_user_id);

        $this->travel(2)->hours();
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([], $this->gateway->comptesFermes);
        $this->assertSame('part de cagnotte encore due', SellerAccountClosure::query()->sole()->last_error);

        unset($this->gateway->erreurs['transferer']);
        $this->travel(2)->hours();
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([['compte' => $account, 'centimes' => 3000]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));
        $this->assertSame([$account], $this->gateway->comptesFermes);
    }

    public function test_une_part_dont_le_versement_est_en_route_ne_laisse_pas_fermer_le_compte(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        $this->eraseSeller($vet);
        PlusPoolMonth::query()->create(['month' => '2026-08', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['views' => 2, 'earned_cents' => 3000, 'stripe_account_id' => $account, 'payout_group' => 'plus-pool-departed-en-route']);

        $this->assertFalse(app(PlusPool::class)->payDeparted($account));
        $this->artisan('videos:close-seller-accounts')->assertSuccessful();
        $this->assertSame([], $this->gateway->comptesFermes);
    }

    public function test_un_achat_repaye_a_l_instant_dans_un_vieil_onglet_n_est_pas_supprime_la_nuit(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $purchase = VideoPurchase::query()->sole();
        $this->gateway->conclure($purchase->payment_intent_id, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        $this->eraseSeller($vet);
        // La banque refuse, puis l'acheteur repaye aussitôt : l'annulation est refusée, l'intention est de nouveau en cours
        unset($this->gateway->erreurs['annulerIntention']);
        $this->gateway->conclure($purchase->payment_intent_id, 'requires_payment_method');
        $this->gateway->avantAnnulation = function (string $intent): void {
            $this->gateway->conclure($intent, 'processing');
            throw new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);
        };

        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertSame(1, VideoPurchase::query()->count());
        $this->assertNotNull(Video::query()->find($purchase->video_id));
        $this->assertSame([], $this->gateway->comptesFermes);
    }

    public function test_une_video_payee_au_moment_de_l_effacement_reste_a_son_acheteur(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $video = Video::query()->sole();
        $purchase = VideoPurchase::query()->sole();
        // Payée chez Stripe pendant l'effacement : l'annulation est refusée
        $this->gateway->conclure($purchase->payment_intent_id, 'succeeded');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà payée', 'payment_intent_unexpected_state', 400);

        $this->assertFalse(app(SellerlessVideos::class)->erase($video));

        $this->assertNotNull(Video::query()->find($video->id));
        $this->assertNotNull($purchase->refresh()->paid_at);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_une_video_effacee_annule_les_paiements_encore_ouverts(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $purchase = VideoPurchase::query()->sole();

        $this->assertTrue(app(SellerlessVideos::class)->erase(Video::query()->sole()));

        $this->assertSame('canceled', $this->gateway->pourCompte($purchase->stripe_account_id)->lireIntention($purchase->payment_intent_id)['statut']);
    }

    public function test_le_passage_mensuel_et_l_effacement_ne_calculent_jamais_en_meme_temps(): void
    {
        $lock = Cache::lock('plus-pool-pay', 3600);
        $this->assertTrue($lock->get());
        $pool = app(PlusPool::class);
        $pool->lockWait = 0;

        try {
            $pool->run(CarbonImmutable::parse('2026-09-01'));
            $this->fail('Calculé pendant un effacement');
        } catch (LockTimeoutException) {
        }
        $this->assertSame(0, PlusPoolMonth::query()->count());
        $lock->release();
    }

    public function test_une_largeur_ou_une_hauteur_au_dela_du_1080p_est_refusee(): void
    {
        $vet = $this->verifiedVet();
        foreach ([[1920, 1200], [2048, 1080]] as [$width, $height]) {
            $id = $this->draft($vet['jeton']);
            $package = $this->hlsPackage();
            $package['init-1.mp4'] = $this->initSegment($width, $height, 'avc1');
            $this->upload($vet['jeton'], $id, $package);
            $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors('files');
        }
    }

    /* ————— Quinzième vérification (31.10.2026) ————— */

    public function test_une_video_dont_le_paiement_est_en_cours_ou_illisible_ne_s_efface_pas(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $buyer = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($buyer['jeton']))->assertOk();
        $video = Video::query()->sole();
        $purchase = VideoPurchase::query()->sole();
        $this->gateway->conclure($purchase->payment_intent_id, 'processing');
        $this->gateway->erreurs['annulerIntention'] = new ErreurPaiement('Déjà en cours', 'payment_intent_unexpected_state', 400);

        // En cours à la banque
        $this->assertFalse(app(SellerlessVideos::class)->erase($video));
        // Illisible (Stripe ne répond pas à la relecture)
        $this->gateway->erreurs['lireIntention'] = new ErreurPaiement('Stripe ne répond pas.', 'api_error', 503);
        $this->assertFalse(app(SellerlessVideos::class)->erase($video));

        $this->assertNotNull(Video::query()->find($video->id));
        $this->assertSame(1, VideoPurchase::query()->count());
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));
    }

    public function test_une_video_que_l_effacement_ne_peut_pas_effacer_est_retiree_comme_une_video_achetee(): void
    {
        $vet = $this->verifiedVet();
        $video = $this->readyDraft($vet);
        $video->forceFill(['files_checked_at' => now()])->save();
        $this->postJson($this->base($video->uuid).'/publish', [], $this->enTetes($vet['jeton']))->assertOk();
        $video->forceFill(['publish_requested_at' => now(), 'publish_requested_by' => $vet['user']->id])->save();
        $this->partialMock(SellerlessVideos::class, fn ($mock) => $mock->shouldReceive('erase')->andReturn(false));

        $this->eraseSeller($vet);

        $video->refresh();
        $this->assertSame('withdrawn', $video->status->value);
        $this->assertNotNull($video->withdrawn_at);
        $this->assertNull($video->publish_requested_at);
        $this->assertNull($video->seller_user_id);
    }

    public function test_a_l_effacement_tous_les_mois_manques_sont_calcules_pas_seulement_le_dernier(): void
    {
        $this->travelTo('2026-10-15 10:00');
        $vet = $this->verifiedVet();
        $sale = $this->published(['in_plus' => true], $vet);
        VideoView::query()->create(['video_id' => Video::query()->where('uuid', $sale['id'])->value('id'), 'user_id' => $this->proprietaireAvecJeton()['user']->id, 'watched_seconds' => 90, 'counted_at' => now(), 'counted_via_plus' => true]);

        // Le passage du 1er novembre manqué ; effacé le 1er décembre à 03:45
        $this->travelTo('2026-12-01 03:45');
        $this->eraseSeller($vet);

        $this->assertSame(['2026-10', '2026-11'], PlusPoolMonth::query()->orderBy('month')->pluck('month')->all());
        $this->assertSame(1, PlusPoolShare::query()->whereHas('month', fn ($m) => $m->where('month', '2026-10'))->count());
    }

    public function test_un_compte_ferme_a_la_main_est_note_sans_retenter_chaque_nuit(): void
    {
        $vet = $this->verifiedVet();
        $account = $vet['user']->stripe_account_id;
        PlusPoolMonth::query()->create(['month' => '2026-08', 'net_revenue_cents' => 6000, 'pool_cents' => 3000, 'views' => 2, 'computed_at' => now()])
            ->shares()->create(['seller_user_id' => $vet['user']->id, 'views' => 2, 'earned_cents' => 3000]);
        // Une part que le compte refuse toujours : la tâche ne pourrait jamais fermer d'elle-même
        $this->gateway->erreurs['transferer'] = new ErreurPaiement('Le compte ne reçoit pas de virements', 'transfers_not_allowed', 400);
        $this->eraseSeller($vet);
        $this->travel(2)->hours();
        // Fermé à la main chez Stripe après le signalement
        $this->gateway->comptesFermes[] = $account;

        $this->artisan('videos:close-seller-accounts')->assertSuccessful();

        $this->assertNotNull(SellerAccountClosure::query()->sole()->closed_at);
        $this->assertNotContains('fermerCompteVendeur', array_column($this->gateway->appels, 'methode'));
    }
}
