<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Enums\VerificationOrganisation;
use App\Jobs\RecheckVideoFiles;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Paiements\PasserellePaiement;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\VideoStorage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
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
        $this->patchJson($this->base($draft), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']))->assertOk();
        $owner = $this->proprietaireAvecJeton();

        $vet['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();

        $this->postJson($this->base($draft).'/upload/start', ['duration_seconds' => 30, 'estimated_bytes' => 100], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('vendeur');
        $this->postJson($this->base($draft).'/publish', [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('vendeur');
        // Ce qu'il avait déjà en vente ne s'achète plus
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('achat');
        $this->assertSame([], $this->gateway->intentions);
    }

    public function test_une_video_de_clinique_se_vend_sur_le_compte_de_la_societe_tant_que_l_organisation_est_ouverte(): void
    {
        ['clinique' => $clinique, 'jeton' => $jeton] = $this->clinicSeller();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->upload($jeton, $id, $this->hlsPackage(), '/api/v1/videos');
        $this->finish($jeton, $id, '/api/v1/videos')->assertOk();
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton))->assertOk();
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
            ->assertUnprocessable()->assertJsonValidationErrors('achat');
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
        $this->patchJson($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'both'], $this->enTetes($vet['jeton']))->assertOk();
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
        $this->patchJson($this->base($id), ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']))->assertOk();
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

        $this->getJson('/api/v1/espace/videos/purchased', $this->enTetes($owner['jeton']))->assertOk()
            ->assertJsonPath('data.0.id', $sale['id'])->assertJsonPath('data.0.status', 'withdrawn');
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
        $this->postJson($url, ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertUnprocessable()->assertJsonValidationErrors('achat');
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
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($jeton))->assertOk();
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
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton))->assertOk();

        ['user' => $other, 'jeton' => $otherJeton] = $this->administrateurAvecJeton($clinique);
        $this->postJson("/api/v1/videos/{$id}/publish", [], $this->enTetes($otherJeton))->assertUnprocessable()->assertJsonValidationErrors('vendeur');
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
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton))->assertOk();
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
            ->assertUnprocessable()->assertJsonValidationErrors('achat');
    }

    public function test_la_relecture_part_16_minutes_apres_le_controle(): void
    {
        Queue::fake();
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();

        Queue::assertPushed(RecheckVideoFiles::class, fn (RecheckVideoFiles $job) => $job->delay !== null && now()->diffInSeconds($job->delay, true) >= 16 * 60 - 5);
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

        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors('files');
        $video = Video::query()->sole();
        $this->assertSame('pending', $video->files_status->value);
        $this->assertNotEmpty(Storage::disk('prive')->allFiles($video->folder()));

        $video->forceFill(['files_status' => 'ready'])->save();
        $this->expectException(\RuntimeException::class);
        try {
            (new RecheckVideoFiles($video->id))->handle(app(HlsPackage::class), app(VideoStorage::class));
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
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($jeton))->assertOk();
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

        $video->refresh();
        $this->assertSame('draft', $video->status->value);
        $this->assertSame('rejected', $video->files_status->value);
        $this->assertSame('La vidéo n’a pas pu être relue après l’envoi : renvoyez-la.', $video->rejection_reason);
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
            ->assertUnprocessable()->assertJsonValidationErrors('certificat');
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
        $this->patchJson("/api/v1/videos/{$id}", ['description' => 'Texte', 'price_cents' => 2000, 'audience' => 'owners'], $this->enTetes($jeton))->assertOk();
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
}
