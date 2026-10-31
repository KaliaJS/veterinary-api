<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Models\Clinique;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Paiements\PasserellePaiement;
use App\Services\Comptes\SuppressionCompte;
use App\Services\Videos\VideoPosters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * L'écran « Vidéos » à la Netflix (31.10.2026, demande de Jim) : une
 * catégorie de liste fixe et une affiche par vidéo, les rangées de
 * l'accueil, le catalogue filtré par catégorie ou par recherche.
 */
class VideoCatalogueRowsTest extends TestCase
{
    use BuildsHlsPackages;
    use EmetDesJetonsApi;
    use PublishesVideos;
    use RefreshDatabase;

    private const SELLER = '/api/v1/espace/videos/seller/videos';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
    }

    // ————— Affiche —————

    public function test_l_affiche_est_recadree_en_16_9_reecrite_en_webp_a_trois_largeurs_et_sans_les_donnees_de_l_appareil(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);

        $video = $this->post(self::SELLER."/{$id}/poster", ['poster' => $this->photoWithGps(1600, 1200)], $this->enTetes($vet['jeton']))
            ->assertOk()->json('data');

        $token = Video::query()->where('uuid', $id)->value('poster_token');
        $this->assertMatchesRegularExpression('/^'.VideoPosters::TOKEN.'$/', (string) $token);
        // 1600 × 1200 recadrée au centre en 1600 × 900, jamais agrandie
        foreach ([640 => [640, 360], 1280 => [1280, 720], 1920 => [1600, 900]] as $width => $size) {
            $bytes = Storage::disk('prive')->get("video-posters/{$token}/{$width}.webp");
            $this->assertSame('WEBP', substr((string) $bytes, 8, 4));
            $this->assertSame($size, array_slice(getimagesizefromstring((string) $bytes), 0, 2));
            $this->assertStringNotContainsString('GPSMARK', (string) $bytes);
            $this->assertStringNotContainsString('Exif', (string) $bytes);
        }
        $this->assertSame([], array_values(array_filter(Storage::disk('prive')->allFiles('video-posters/'.$token), fn (string $file) => ! str_ends_with($file, '.webp'))));
        $this->assertStringStartsWith('data:image/webp;base64,', $video['poster']['preview']);
        $this->assertSame(route('videos.poster', ['token' => $token, 'width' => 1280]), $video['poster']['url']);
        $this->assertStringContainsString('/640.webp 640w', $video['poster']['srcset']);
        $this->assertStringContainsString('/1920.webp 1920w', $video['poster']['srcset']);
    }

    public function test_une_affiche_trop_petite_ou_qui_n_est_pas_une_image_est_refusee(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);

        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('petite.jpg', 639, 400)], $this->enTetes($vet['jeton']) + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['poster' => 'L’affiche doit mesurer au moins 640 × 360 pixels.']);
        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('basse.jpg', 800, 359)], $this->enTetes($vet['jeton']) + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['poster' => 'L’affiche doit mesurer au moins 640 × 360 pixels.']);
        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->create('dessin.svg', 10, 'image/svg+xml')], $this->enTetes($vet['jeton']) + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['poster']);
        $this->assertNull(Video::query()->where('uuid', $id)->value('poster_token'));
        $this->assertSame([], Storage::disk('prive')->allFiles('video-posters'));
    }

    public function test_l_affiche_se_sert_publiquement_en_cache_et_seulement_tant_qu_une_video_la_porte(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($vet['jeton']))->assertOk();
        $first = (string) Video::query()->where('uuid', $id)->value('poster_token');

        $response = $this->get("/api/v1/public/videos/posters/{$first}/640.webp")->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->get('/api/v1/public/videos/posters/'.Str::random(40).'/640.webp')->assertNotFound();
        $this->get("/api/v1/public/videos/posters/{$first}/800.webp")->assertNotFound();

        // Remplacée : l'ancienne quitte le disque et ne se sert plus
        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('b.jpg', 1280, 720)], $this->enTetes($vet['jeton']))->assertOk();
        $second = (string) Video::query()->where('uuid', $id)->value('poster_token');
        $this->assertNotSame($first, $second);
        $this->assertSame([], Storage::disk('prive')->allFiles("video-posters/{$first}"));
        $this->get("/api/v1/public/videos/posters/{$first}/640.webp")->assertNotFound();
        $this->get("/api/v1/public/videos/posters/{$second}/1920.webp")->assertOk();

        // Retirée d'un brouillon : son dossier part aussi
        $this->deleteJson(self::SELLER."/{$id}/poster", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.poster', null);
        $this->assertSame([], Storage::disk('prive')->allFiles('video-posters'));
    }

    public function test_seul_le_vendeur_pose_l_affiche_de_sa_video(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $other = $this->verifiedVet();

        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($other['jeton']) + ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson(self::SELLER."/{$id}/poster", [], $this->enTetes($other['jeton']))->assertNotFound();
        $this->assertSame([], Storage::disk('prive')->allFiles('video-posters'));
    }

    public function test_une_clinique_pose_l_affiche_de_ses_videos(): void
    {
        $clinique = Clinique::factory()->create();
        ['user' => $admin, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $admin->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $id = $this->postJson('/api/v1/videos', ['title' => 'Au nom de la clinique'], $this->enTetes($jeton))->assertCreated()->json('data.id');

        $this->post("/api/v1/videos/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNotNull(Video::query()->where('uuid', $id)->value('poster_token'));
        $this->deleteJson("/api/v1/videos/{$id}/poster", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.poster', null);
    }

    // ————— Catégorie et mise en vente —————

    public function test_la_mise_en_vente_demande_une_categorie_et_une_affiche(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->patchJson(self::SELLER."/{$id}", ['description' => 'Texte', 'price_cents' => 500, 'audience' => 'owners'], $this->enTetes($vet['jeton']))->assertOk();

        $this->postJson(self::SELLER."/{$id}/publish", ['accept_terms' => true], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors([
                'category' => 'Choisissez sa catégorie.',
                'poster' => 'Ajoutez une affiche : une image de la vidéo ou une photo.',
            ]);

        $this->patchJson(self::SELLER."/{$id}", ['category' => 'surgery'], $this->enTetes($vet['jeton']))
            ->assertOk()->assertJsonPath('data.category', 'surgery')->assertJsonPath('data.category_label', 'Chirurgie');
        $this->postJson(self::SELLER."/{$id}/publish", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['poster'])->assertJsonMissingValidationErrors(['category']);

        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson(self::SELLER."/{$id}/publish", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_une_categorie_hors_de_la_liste_est_refusee(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);

        $this->patchJson(self::SELLER."/{$id}", ['category' => 'astrologie'], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['category' => 'Choisissez une catégorie de la liste.']);
        $this->patchJson(self::SELLER."/{$id}", ['category' => 'clinic_life'], $this->enTetes($vet['jeton']))
            ->assertOk()->assertJsonPath('data.category_label', 'Vie de la clinique');
    }

    public function test_une_video_en_vente_garde_sa_categorie_et_son_affiche(): void
    {
        $sale = $this->published();
        $jeton = $sale['vet']['jeton'];

        $this->patchJson(self::SELLER."/{$sale['id']}", ['category' => null], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['category' => 'Une vidéo en vente garde son titre, sa description, son prix, son public et sa catégorie.']);
        $this->deleteJson(self::SELLER."/{$sale['id']}/poster", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['poster' => 'Une vidéo en vente garde son affiche : remplacez-la plutôt.']);
        $this->assertNotNull(Video::query()->where('uuid', $sale['id'])->value('poster_token'));
        // Remplacée, oui : sur le site
        $this->post(self::SELLER."/{$sale['id']}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($jeton))->assertOk();
        // Pas dans l'app iPhone, où une vidéo en vente ne se modifie pas
        $this->post(self::SELLER."/{$sale['id']}/poster", ['poster' => UploadedFile::fake()->image('b.jpg', 1280, 720)], $this->enTetes($jeton) + ['X-Veterinary-Platform' => 'ios', 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['platform']);
    }

    public function test_dans_l_app_iphone_le_brouillon_recoit_son_affiche(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);

        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($vet['jeton']) + ['X-Veterinary-Platform' => 'ios'])->assertOk();
        $this->assertNotNull(Video::query()->where('uuid', $id)->value('poster_token'));
    }

    public function test_l_ecran_du_vendeur_recoit_la_liste_des_categories(): void
    {
        $vet = $this->verifiedVet();

        $meta = $this->getJson(self::SELLER, $this->enTetes($vet['jeton']))->assertOk()->json('meta.categories');
        $this->assertCount(13, $meta);
        $this->assertSame(['key' => 'nutrition', 'label' => 'Alimentation'], $meta[0]);
        $this->assertSame(['key' => 'clinic_life', 'label' => 'Vie de la clinique'], $meta[12]);
    }

    // ————— Effacer —————

    public function test_un_brouillon_efface_emporte_son_affiche(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->post(self::SELLER."/{$id}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($vet['jeton']))->assertOk();
        $this->assertNotSame([], Storage::disk('prive')->allFiles('video-posters'));

        $this->deleteJson(self::SELLER."/{$id}", [], $this->enTetes($vet['jeton']))->assertNoContent();
        $this->assertSame([], Storage::disk('prive')->allFiles('video-posters'));
    }

    public function test_la_video_d_un_vendeur_efface_sans_acheteur_emporte_son_affiche_meme_gardee_pour_un_certificat(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->published([], $vet);
        $video = Video::query()->where('uuid', $sale['id'])->sole();
        $this->post(self::SELLER."/{$sale['id']}/poster", ['poster' => UploadedFile::fake()->image('a.jpg', 1280, 720)], $this->enTetes($vet['jeton']))->assertOk();
        $viewer = User::factory()->create();
        ViewingCertificate::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'code' => 'ABCD-EFGH-JKLM', 'holder_name' => 'Camille Essai', 'video_title' => 'Titre', 'seller_name' => 'Dr Essai', 'duration_seconds' => 30, 'watched_at' => now()]);

        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));

        $stub = Video::query()->sole();
        $this->assertNull($stub->poster_token);
        $this->assertNull($stub->poster_preview);
        $this->assertSame([], Storage::disk('prive')->allFiles('video-posters'));
    }

    // ————— Catalogue —————

    public function test_le_catalogue_se_filtre_par_categorie(): void
    {
        $vet = $this->verifiedVet();
        $cats = $this->onSale($vet, 'Le chat qui boude', 'cats');
        $this->onSale($vet, 'Le chien qui tire', 'dogs');
        ['jeton' => $owner] = $this->proprietaireAvecJeton();

        $this->assertSame([$cats], $this->getJson('/api/v1/espace/videos?category=cats', $this->enTetes($owner))->assertOk()->json('data.*.id'));
        $this->assertSame(1, $this->getJson('/api/v1/espace/videos?category=cats', $this->enTetes($owner))->json('meta.total'));
        $this->assertCount(2, $this->getJson('/api/v1/espace/videos', $this->enTetes($owner))->json('data'));
        $this->getJson('/api/v1/espace/videos?category=astrologie', $this->enTetes($owner))->assertUnprocessable()->assertJsonValidationErrors(['category']);
    }

    public function test_la_recherche_trouve_le_titre_la_description_le_vendeur_et_le_nom_d_une_categorie(): void
    {
        $vet = $this->verifiedVet();
        $title = $this->onSale($vet, 'Brosser les dents', 'dentistry');
        $described = $this->onSale($vet, 'Un repas équilibré', 'nutrition', 'Les croquettes du matin.');
        $horse = $this->onSale($vet, 'Le box', 'horses');
        $anaesthesia = $this->onSale($vet, 'Le réveil', 'anaesthesia');
        Video::query()->where('uuid', $horse)->update(['seller_name' => 'Clinique des Saules']);
        ['jeton' => $owner] = $this->proprietaireAvecJeton();
        $search = fn (string $q) => $this->getJson('/api/v1/espace/videos?q='.urlencode($q), $this->enTetes($owner))->assertOk()->json('data.*.id');

        $this->assertSame([$title], $search('dents'));
        $this->assertSame([$described], $search('croquettes'));
        $this->assertSame([$horse], $search('saules'));
        // Le nom d'une catégorie la trouve, sans tenir compte des majuscules ni des accents
        $this->assertSame([$horse], $search('chevaux'));
        $this->assertSame([], $search('cheval'));
        $this->assertSame([$title], $search('dentisterie'));
        $this->assertSame([$described], $search('ALIMENT'));
        $this->assertSame([$anaesthesia], $search('anesthesie'));
        // Un « % » ou un « _ » n'est jamais un joker
        $this->assertSame([], $search('den%s'));
        $this->assertSame([], $search('dent_s'));
        $this->getJson('/api/v1/espace/videos?q='.str_repeat('a', 101), $this->enTetes($owner))->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    // ————— Accueil —————

    public function test_l_accueil_range_continuer_ses_videos_les_nouveautes_puis_une_rangee_par_categorie(): void
    {
        $vet = $this->verifiedVet();
        $nutrition = $this->onSale($vet, 'Repas', 'nutrition');
        $cats = $this->onSale($vet, 'Chat', 'cats');
        $surgery = $this->onSale($vet, 'Suture', 'surgery');
        $this->travel(1)->minutes();
        $catsBis = $this->onSale($vet, 'Chat encore', 'cats');
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $cats);
        $this->own($owner, $surgery);
        // Commencée et achetée : à continuer ; finie, ou seulement l'aperçu : non
        $this->viewOf($owner, $cats, 40);
        $this->viewOf($owner, $surgery, 30, completed: true);
        $this->viewOf($owner, $nutrition, 20);

        $home = $this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->assertOk()->json('data');

        $this->assertSame(['continue', 'mine', 'new', 'category', 'category', 'category'], array_column($home['rows'], 'key'));
        $this->assertSame(['Continuer à regarder', 'Mes vidéos', 'Nouveautés', 'Alimentation', 'Chats', 'Chirurgie'], array_column($home['rows'], 'title'));
        $this->assertSame([$cats], array_column($home['rows'][0]['videos'], 'id'));
        $this->assertSame(40, $home['rows'][0]['videos'][0]['viewing']['watched_seconds']);
        $this->assertEqualsCanonicalizing([$cats, $surgery], array_column($home['rows'][1]['videos'], 'id'));
        $this->assertSame($catsBis, $home['rows'][2]['videos'][0]['id']);
        $this->assertCount(4, $home['rows'][2]['videos']);
        $this->assertSame([$catsBis, $cats], array_column($home['rows'][4]['videos'], 'id'));
        $this->assertSame(['cats', 2], [$home['rows'][4]['category'], $home['rows'][4]['total']]);
        $this->assertSame([
            ['key' => 'nutrition', 'label' => 'Alimentation', 'total' => 1],
            ['key' => 'cats', 'label' => 'Chats', 'total' => 2],
            ['key' => 'surgery', 'label' => 'Chirurgie', 'total' => 1],
        ], $home['categories']);
        $this->assertSame('owned', $home['rows'][4]['videos'][1]['access']);
        $this->assertSame('preview', $home['rows'][3]['videos'][0]['access']);
    }

    public function test_l_accueil_d_un_proprietaire_ne_montre_pas_les_videos_pour_veterinaires_et_cache_les_rangees_vides(): void
    {
        $vet = $this->verifiedVet();
        $this->onSale($vet, 'Pour les vétos', 'surgery', audience: 'vets');
        $both = $this->onSale($vet, 'Pour tous', 'imaging', audience: 'both');
        ['jeton' => $owner] = $this->proprietaireAvecJeton();

        $home = $this->getJson('/api/v1/espace/videos/home', $this->enTetes($owner))->assertOk()->json('data');

        $this->assertSame(['new', 'category'], array_column($home['rows'], 'key'));
        $this->assertSame([$both], array_column($home['rows'][0]['videos'], 'id'));
        $this->assertSame(['imaging'], array_column($home['categories'], 'key'));

        // Le vétérinaire vérifié, lui, voit les deux
        $other = $this->verifiedVet();
        $vetHome = $this->getJson('/api/v1/espace/videos/home', $this->enTetes($other['jeton']))->json('data');
        $this->assertSame(['surgery', 'imaging'], array_column($vetHome['categories'], 'key'));
    }

    public function test_dans_l_app_iphone_l_accueil_ne_montre_que_ce_qu_on_a_deja(): void
    {
        $vet = $this->verifiedVet();
        $mine = $this->onSale($vet, 'À moi', 'cats');
        $this->onSale($vet, 'Pas à moi', 'cats');
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $mine);
        $this->viewOf($owner, $mine, 10);

        $home = $this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton) + ['X-Veterinary-Platform' => 'ios'])->assertOk()->json('data');

        $this->assertSame(['continue', 'mine'], array_column($home['rows'], 'key'));
        $this->assertSame([$mine], array_column($home['rows'][1]['videos'], 'id'));
        $this->assertSame([], $home['categories']);
    }

    public function test_un_abonne_plus_retrouve_les_videos_incluses_dans_ses_videos_et_peut_les_continuer(): void
    {
        $vet = $this->verifiedVet();
        $included = $this->onSale($vet, 'Incluse', 'behaviour', inPlus: true);
        $this->onSale($vet, 'Pas incluse', 'behaviour');
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $owner->forceFill(['formule' => 'plus'])->save();
        $this->viewOf($owner, $included, 15);

        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key');

        $this->assertSame([$included], array_column($rows['continue']['videos'], 'id'));
        $this->assertSame('plus', $rows['continue']['videos'][0]['access']);
        $this->assertSame([$included], array_column($rows['mine']['videos'], 'id'));
    }

    public function test_une_rangee_garde_vingt_videos_au_plus(): void
    {
        $vet = $this->verifiedVet();
        $sale = $this->onSale($vet, 'Modèle', 'nutrition');
        $model = Video::query()->where('uuid', $sale)->sole();
        for ($i = 0; $i < 21; $i++) {
            $copy = $model->replicate(['uuid', 'poster_token']);
            $copy->published_at = now()->addSeconds($i + 1);
            $copy->save();
        }
        ['jeton' => $owner] = $this->proprietaireAvecJeton();

        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($owner))->json('data.rows'))->keyBy('key');
        $this->assertCount(20, $rows['new']['videos']);
        $this->assertCount(20, $rows['category']['videos']);
        $this->assertSame(22, $rows['category']['total']);
    }

    // ————— Outils —————

    /** Une vidéo en vente, dans sa catégorie ; rend son identifiant. */
    private function onSale(array $vet, string $title, string $category, string $description = 'Ce qu’il faut savoir.', string $audience = 'owners', bool $inPlus = false): string
    {
        return $this->published(['title' => $title, 'category' => $category, 'description' => $description, 'audience' => $audience, 'in_plus' => $inPlus], $vet)['id'];
    }

    private function own(User $user, string $id): void
    {
        $video = Video::query()->where('uuid', $id)->sole();
        VideoPurchase::query()->create(['video_id' => $video->id, 'user_id' => $user->id, 'amount_cents' => 500, 'commission_cents' => 150, 'terms_accepted_at' => now(), 'paid_at' => now(), 'stripe_account_id' => 'acct_test', 'payment_intent_id' => 'pi_'.Str::random(10)]);
    }

    private function viewOf(User $user, string $id, int $seconds, bool $completed = false): void
    {
        $video = Video::query()->where('uuid', $id)->sole();
        VideoView::query()->create([
            'video_id' => $video->id, 'user_id' => $user->id, 'watched_ranges' => [[0, $seconds]], 'watched_seconds' => $seconds,
            'last_progress_at' => now(), 'completed_at' => $completed ? now() : null,
        ]);
    }

    /** Une photo JPEG qui porte une position GPS dans ses données EXIF. */
    private function photoWithGps(int $width, int $height): UploadedFile
    {
        $source = UploadedFile::fake()->image('source.jpg', $width, $height);
        $jpeg = (string) file_get_contents($source->getRealPath());
        $payload = "Exif\0\0MM\0*\0\0\0\x08GPSMARK 46.5197N 6.6323E";
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
        $path = tempnam(sys_get_temp_dir(), 'poster').'.jpg';
        file_put_contents($path, $withExif);
        $this->assertStringContainsString('GPSMARK', (string) file_get_contents($path));

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }
}
