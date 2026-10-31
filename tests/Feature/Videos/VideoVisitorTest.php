<?php

namespace Tests\Feature\Videos;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * L'écran « Vidéos » sans compte, depuis l'accueil du site (31.10.2026,
 * demande de Jim : inviter les gens à s'inscrire). Un visiteur voit les
 * vidéos destinées aux clients et leur aperçu qui se lance tout seul ;
 * regarder, acheter et le reste demandent un compte. Les adresses
 * publiques ne montrent jamais ce qui est à quelqu'un, même appelées avec
 * un jeton.
 */
class VideoVisitorTest extends TestCase
{
    use BuildsHlsPackages;
    use EmetDesJetonsApi;
    use PublishesVideos;
    use RefreshDatabase;

    private const SELLER = '/api/v1/espace/videos/seller/videos';

    private const PUBLIC = '/api/v1/public/videos';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
    }

    public function test_sans_compte_l_accueil_montre_les_nouveautes_les_gratuites_et_les_categories_des_videos_des_clients(): void
    {
        $vet = $this->verifiedVet();
        $owners = $this->published(['title' => 'Le chat', 'category' => 'cats'], $vet)['id'];
        $both = $this->published(['title' => 'Pour tous', 'category' => 'dogs', 'audience' => 'both'], $vet)['id'];
        $free = $this->published(['title' => 'Offerte', 'category' => 'cats', 'price_cents' => 0], $vet)['id'];
        $this->published(['title' => 'Pour les vétos', 'category' => 'surgery', 'audience' => 'vets'], $vet);
        $this->draft($vet['jeton']);

        $data = $this->getJson(self::PUBLIC.'/home')->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy(fn (array $row) => $row['key'].($row['category'] ?? ''));

        // Ni « Continuer à voir » (rien à lui) ni vidéo pour vétérinaires
        $this->assertSame(['new', 'free', 'categorycats', 'categorydogs'], $rows->keys()->all());
        $this->assertEqualsCanonicalizing([$owners, $both, $free], array_column($rows['new']['videos'], 'id'));
        $this->assertSame([$free], array_column($rows['free']['videos'], 'id'));
        $this->assertSame(['cats', 'dogs'], array_column($data['categories'], 'key'));
        // Ce qu'un visiteur en a : la première minute, ou gratuite ; rien de regardé, rien à télécharger
        $access = collect($rows['new']['videos'])->pluck('access', 'id');
        $this->assertSame('free', $access[$free]);
        $this->assertSame('preview', $access[$owners]);
        foreach ($rows['new']['videos'] as $video) {
            $this->assertArrayNotHasKey('viewing', $video);
            $this->assertFalse($video['can_download']);
            $this->assertArrayNotHasKey('sales_count', $video);
        }
    }

    public function test_sans_compte_le_catalogue_se_filtre_par_categorie_et_se_cherche(): void
    {
        $vet = $this->verifiedVet();
        $cat = $this->published(['title' => 'Le chat qui griffe', 'category' => 'cats'], $vet)['id'];
        $dog = $this->published(['title' => 'Le chien en été', 'category' => 'dogs'], $vet)['id'];
        $this->published(['title' => 'Chat opéré', 'category' => 'surgery', 'audience' => 'vets'], $vet);

        $this->assertSame([$cat], array_column($this->getJson(self::PUBLIC.'?category=cats')->assertOk()->json('data'), 'id'));
        $this->assertSame([$dog], array_column($this->getJson(self::PUBLIC.'?q=chien')->assertOk()->json('data'), 'id'));
        // La vidéo des vétérinaires ne se trouve pas, même par son titre
        $this->assertSame([$cat], array_column($this->getJson(self::PUBLIC.'?q=chat')->assertOk()->json('data'), 'id'));
        $this->getJson(self::PUBLIC.'?category=inconnue')->assertUnprocessable();
    }

    public function test_sans_compte_une_video_s_ouvre_avec_son_apercu_qui_ne_compte_rien(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published(['title' => 'Le chat'], $vet)['id'];

        $this->getJson(self::PUBLIC."/{$sale}")->assertOk()
            ->assertJsonPath('data.id', $sale)->assertJsonPath('data.access', 'preview')->assertJsonPath('data.can_download', false)
            ->assertJsonMissingPath('data.viewing');
        $url = $this->getJson(self::PUBLIC."/{$sale}/teaser")->assertOk()->json('data.url');
        $this->assertStringContainsString('scope=preview', (string) $url);
        $this->assertStringContainsString("/videos/{$sale}/hls/master.m3u8", (string) $url);
        $this->assertSame(0, VideoView::query()->count());
    }

    public function test_sans_compte_ni_les_videos_des_veterinaires_ni_les_brouillons_ni_les_retirees(): void
    {
        $vet = $this->verifiedVet();
        $forVets = $this->published(['title' => 'Pour les vétos', 'audience' => 'vets'], $vet)['id'];
        $draft = $this->draft($vet['jeton']);
        $withdrawn = $this->published(['title' => 'Retirée'], $vet)['id'];
        $this->postJson(self::SELLER."/{$withdrawn}/withdraw", [], $this->enTetes($vet['jeton']))->assertOk();

        foreach ([$forVets, $draft, $withdrawn] as $id) {
            $this->getJson(self::PUBLIC."/{$id}")->assertNotFound();
            $this->getJson(self::PUBLIC."/{$id}/teaser")->assertNotFound();
        }
        $this->getJson(self::PUBLIC.'/'.Str::uuid())->assertNotFound();
    }

    public function test_sans_compte_regarder_acheter_telecharger_et_le_certificat_demandent_un_compte(): void
    {
        $sale = $this->published()['id'];

        foreach (['play', 'progress', 'certificate', 'purchase', 'purchase/confirm'] as $action) {
            $this->postJson(self::PUBLIC."/{$sale}/{$action}")->assertNotFound();
            $this->postJson("/api/v1/espace/videos/{$sale}/{$action}")->assertUnauthorized();
        }
        $this->getJson(self::PUBLIC."/{$sale}/download")->assertNotFound();
        $this->getJson(self::PUBLIC.'/library')->assertNotFound();
        $this->getJson('/api/v1/espace/videos/library')->assertUnauthorized();
    }

    public function test_les_adresses_publiques_ne_montrent_rien_de_ce_qui_est_a_la_personne_meme_avec_un_jeton(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published(['title' => 'Le chat'], $vet)['id'];
        $forVets = $this->published(['title' => 'Pour les vétos', 'audience' => 'vets'], $vet)['id'];
        $draft = $this->draft($vet['jeton']);
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $sale);
        VideoView::query()->create(['video_id' => Video::query()->where('uuid', $sale)->value('id'), 'user_id' => $owner->id, 'watched_ranges' => [[0, 20]], 'watched_seconds' => 20, 'last_progress_at' => now()]);

        // L'acheteur : achetée et commencée dans son espace, simple aperçu sur l'adresse publique
        $this->getJson("/api/v1/espace/videos/{$sale}", $this->enTetes($jeton))->assertJsonPath('data.access', 'owned');
        $this->getJson(self::PUBLIC."/{$sale}", $this->enTetes($jeton))->assertOk()->assertJsonPath('data.access', 'preview')->assertJsonMissingPath('data.viewing');
        $rows = collect($this->getJson(self::PUBLIC.'/home', $this->enTetes($jeton))->json('data.rows'));
        $this->assertNotContains('continue', $rows->pluck('key')->all());
        // Le vétérinaire vérifié, vendeur : ni ses vidéos pour vétérinaires, ni ses brouillons, ni rien du vendeur
        $this->getJson(self::PUBLIC."/{$forVets}", $this->enTetes($vet['jeton']))->assertNotFound();
        $this->getJson(self::PUBLIC."/{$forVets}/teaser", $this->enTetes($vet['jeton']))->assertNotFound();
        $this->getJson(self::PUBLIC."/{$draft}", $this->enTetes($vet['jeton']))->assertNotFound();
        $this->getJson(self::PUBLIC."/{$sale}", $this->enTetes($vet['jeton']))->assertJsonPath('data.access', 'preview')->assertJsonMissingPath('data.files_status');
        $this->assertSame([$sale], array_column($this->getJson(self::PUBLIC, $this->enTetes($vet['jeton']))->json('data'), 'id'));
    }

    public function test_sans_compte_une_serie_montre_ses_episodes_ajoutes_et_l_apercu_du_premier(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Alimentation du chat'], $this->enTetes($vet['jeton']))->json('data.id');
        $this->fillSheet(self::SELLER."/{$series}", ['description' => 'Deux épisodes.', 'price_cents' => 4500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $first = $this->readyEpisode($vet, $series, 'Les besoins');
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        // Un épisode encore en préparation : jamais montré
        $this->postJson(self::SELLER, ['series' => $series, 'title' => 'En préparation'], $this->enTetes($vet['jeton']))->assertCreated();

        $data = $this->getJson(self::PUBLIC."/{$series}")->assertOk()->assertJsonPath('data.kind', 'series')->assertJsonPath('data.access', 'preview')->json('data');
        $this->assertSame(['Les besoins'], array_column($data['episodes'], 'title'));
        $this->assertSame(0, $data['episodes'][0]['viewing']['watched_seconds']);
        $this->assertNull($data['viewing']['certificate_code']);
        $this->assertStringContainsString("/videos/{$first}/hls/master.m3u8", (string) $this->getJson(self::PUBLIC."/{$series}/teaser")->assertOk()->json('data.url'));
        // Un épisode s'ouvre avec sa série, comme dans l'espace, en aperçu
        $this->getJson(self::PUBLIC."/{$first}")->assertOk()->assertJsonPath('data.series.id', $series)->assertJsonPath('data.access', 'preview')->assertJsonMissingPath('data.viewing');
    }

    public function test_parcourir_les_videos_sans_compte_a_ses_propres_compteurs(): void
    {
        $sale = $this->published()['id'];

        // Plus que la limite des autres adresses publiques (60 par minute), sans rien bloquer
        for ($i = 0; $i < 70; $i++) {
            $this->getJson(self::PUBLIC.'?page=1')->assertOk();
        }
        $this->getJson('/api/v1/public/offres')->assertOk();
        // Les aperçus ont le leur : parcourir la liste ne les bloque pas
        $this->getJson(self::PUBLIC."/{$sale}/teaser")->assertOk();
        for ($i = 0; $i < 50; $i++) {
            $this->getJson(self::PUBLIC.'?page=1')->assertOk();
        }
        $this->getJson(self::PUBLIC.'?page=1')->assertTooManyRequests();
        $this->getJson(self::PUBLIC."/{$sale}/teaser")->assertOk();
    }

    // ————— Outils —————

    /** Un épisode envoyé et contrôlé, avec son image ; rend son identifiant. */
    private function readyEpisode(array $vet, string $series, string $title): string
    {
        $id = $this->postJson(self::SELLER, ['series' => $series, 'title' => $title], $this->enTetes($vet['jeton']))->assertCreated()->json('data.id');
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        Video::query()->where('uuid', $id)->update(['poster_token' => Str::random(40)]);

        return $id;
    }

    private function own(User $user, string $id): void
    {
        $video = Video::query()->where('uuid', $id)->sole();
        VideoPurchase::query()->create(['video_id' => $video->id, 'user_id' => $user->id, 'amount_cents' => 1500, 'commission_cents' => 450, 'terms_accepted_at' => now(), 'paid_at' => now(), 'stripe_account_id' => 'acct_test', 'payment_intent_id' => 'pi_'.Str::random(10)]);
    }
}
