<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Jobs\RecheckVideoFiles;
use App\Models\Clinique;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Paiements\PasserellePaiement;
use App\Services\Comptes\SuppressionCompte;
use App\Services\Videos\SellerTerms;
use App\Services\Videos\VideoPosters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Les séries de vidéos (31.10.2026, décisions de Jim) : le vendeur choisit
 * une vidéo seule ou une série ; une série se vend entière, jamais épisode
 * par épisode ; les épisodes ajoutés ensuite sont à ceux qui l'ont
 * achetée ; un seul certificat, pour la série vue en entier.
 */
class VideoSeriesTest extends TestCase
{
    use BuildsHlsPackages;
    use EmetDesJetonsApi;
    use PublishesVideos;
    use RefreshDatabase;

    private const SELLER = '/api/v1/espace/videos/seller/videos';

    private PasserellePaiementFactice $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->gateway = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->gateway);
    }

    // ————— Vendre —————

    public function test_un_vendeur_cree_une_serie_en_brouillon_en_son_nom_ou_au_nom_de_sa_clinique(): void
    {
        $vet = $this->verifiedVet();
        $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Alimentation du chat'], $this->enTetes($vet['jeton']))
            ->assertCreated()->assertJsonPath('data.kind', 'series')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.episodes_count', 0);

        $clinique = Clinique::factory()->create();
        ['user' => $admin, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $admin->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $this->postJson('/api/v1/videos', ['kind' => 'series', 'title' => 'De la clinique'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.kind', 'series')->assertJsonPath('data.seller.kind', 'clinic');

        ['jeton' => $owner] = $this->proprietaireAvecJeton();
        $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Non'], $this->enTetes($owner))->assertUnprocessable()->assertJsonValidationErrors(['seller']);
    }

    public function test_un_episode_se_cree_dans_sa_serie_numerote_a_la_suite_et_n_a_ni_prix_ni_public_a_lui(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->newSeries($vet);
        $first = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Les besoins'], $this->enTetes($vet['jeton']))
            ->assertCreated()->assertJsonPath('data.kind', 'episode')->assertJsonPath('data.episode_number', 1)->assertJsonPath('data.series.id', $series)->json('data.id');
        $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Les croquettes'], $this->enTetes($vet['jeton']))->assertJsonPath('data.episode_number', 2);

        $this->patchJson(self::SELLER."/{$first}", ['price_cents' => 900, 'category' => 'cats'], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['price_cents' => 'Le prix, le public, la catégorie et Plus se règlent sur la série.', 'category']);
        $this->patchJson(self::SELLER."/{$first}", ['title' => 'Les besoins du chat', 'description' => 'Texte'], $this->enTetes($vet['jeton']))->assertOk();

        // Une série d'un autre vendeur ne reçoit pas d'épisode
        $other = $this->verifiedVet();
        $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Intrus'], $this->enTetes($other['jeton']))->assertNotFound();
        // Une vidéo seule n'est pas une série
        $video = $this->draft($vet['jeton']);
        $this->postJson(self::SELLER, ['series' => $video, 'title' => 'Non'], $this->enTetes($vet['jeton']))->assertNotFound();
    }

    public function test_une_serie_n_a_pas_de_fichiers_a_elle_ses_episodes_s_envoient_comme_une_video(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->newSeries($vet);
        $this->postJson(self::SELLER."/{$series}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['files' => 'Une série n’a pas de fichiers à elle : envoyez ses épisodes.']);

        $episode = $this->readyEpisode($vet, $series, 'Épisode 1');
        $this->assertSame('ready', Video::query()->where('uuid', $episode)->first()->files_status->value);
    }

    public function test_l_ordre_des_episodes_se_change(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->newSeries($vet);
        $a = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'A'], $this->enTetes($vet['jeton']))->json('data.id');
        $b = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'B'], $this->enTetes($vet['jeton']))->json('data.id');
        $c = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'C'], $this->enTetes($vet['jeton']))->json('data.id');

        $this->postJson(self::SELLER."/{$series}/episodes/order", ['episodes' => [$c, $a, $b]], $this->enTetes($vet['jeton']))->assertOk();
        $this->assertSame([$c, $a, $b], Video::query()->where('kind', 'episode')->orderBy('episode_number')->pluck('uuid')->all());

        $this->postJson(self::SELLER."/{$series}/episodes/order", ['episodes' => [$a, $b]], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['episodes']);
        $this->postJson(self::SELLER."/{$series}/episodes/order", ['episodes' => [$a, $a, $b]], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['episodes']);
    }

    public function test_une_serie_se_met_en_vente_fiche_complete_avec_au_moins_un_episode_pret_qui_part_avec_elle(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Vide'], $this->enTetes($vet['jeton']))->json('data.id');
        $this->postJson(self::SELLER."/{$series}/publish", ['accept_terms' => true], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['description' => 'Décrivez la série.', 'price_cents', 'audience', 'category', 'poster']);

        $this->fillSheet(self::SELLER."/{$series}", ['description' => 'Cinq épisodes.', 'price_cents' => 4500, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['episodes']);

        $ready = $this->readyEpisode($vet, $series, 'Prêt');
        $pending = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'En préparation'], $this->enTetes($vet['jeton']))->json('data.id');
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertSame('published', Video::query()->where('uuid', $ready)->first()->status->value);
        $this->assertSame('draft', Video::query()->where('uuid', $pending)->first()->status->value);
    }

    public function test_un_episode_pret_s_ajoute_a_une_serie_en_vente_sur_le_site_seulement(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $draftSeries = $this->newSeries($vet);
        $lonely = $this->readyEpisode($vet, $draftSeries, 'Seul');
        $this->postJson(self::SELLER."/{$lonely}/publish", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['series']);

        $next = $this->readyEpisode($vet, $series, 'Épisode 2');
        $this->postJson(self::SELLER."/{$next}/publish", [], $this->enTetes($vet['jeton']) + ['X-Veterinary-Platform' => 'ios'])
            ->assertUnprocessable()->assertJsonValidationErrors(['platform']);
        $this->postJson(self::SELLER."/{$next}/publish", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_un_episode_d_une_serie_jamais_vendue_s_efface_et_les_autres_se_renumerotent(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $first = Video::query()->where('kind', 'episode')->where('episode_number', 1)->value('uuid');

        $this->deleteJson(self::SELLER."/{$first}", [], $this->enTetes($vet['jeton']))->assertNoContent();

        $this->assertFalse(Video::query()->where('uuid', $first)->exists());
        $this->assertSame(1, Video::query()->where('uuid', $second)->value('episode_number'));
    }

    public function test_un_episode_d_une_serie_vendue_ne_s_efface_plus_mais_un_episode_en_preparation_si(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        ['user' => $buyer] = $this->proprietaireAvecJeton();
        $this->own($buyer, $series);
        $added = Video::query()->where('kind', 'episode')->value('uuid');
        $preparing = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Bientôt'], $this->enTetes($vet['jeton']))->json('data.id');

        $this->deleteJson(self::SELLER."/{$added}", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Cette série a été achetée : ses épisodes restent à ceux qui l’ont achetée.']);
        $this->deleteJson(self::SELLER."/{$preparing}", [], $this->enTetes($vet['jeton']))->assertNoContent();
    }

    public function test_une_serie_en_brouillon_s_efface_avec_ses_episodes_une_serie_mise_en_vente_jamais(): void
    {
        $vet = $this->verifiedVet();
        $draft = $this->newSeries($vet);
        $this->readyEpisode($vet, $draft, 'Un');
        $this->deleteJson(self::SELLER."/{$draft}", [], $this->enTetes($vet['jeton']))->assertNoContent();
        $this->assertSame(0, Video::query()->count());

        $series = $this->publishedSeries($vet);
        $this->deleteJson(self::SELLER."/{$series}", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Une série mise en vente ne s’efface pas : retirez-la de la vente, ceux qui l’ont achetée la gardent.']);
    }

    public function test_une_serie_se_retire_de_la_vente_ses_acheteurs_gardent_tous_ses_episodes_un_episode_ne_se_retire_pas_seul(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($buyer, $series);
        $episode = Video::query()->where('kind', 'episode')->value('uuid');

        $this->postJson(self::SELLER."/{$episode}/withdraw", [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postJson(self::SELLER."/{$series}/withdraw", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->postJson("/api/v1/espace/videos/{$episode}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
        $this->assertSame([$series], $this->getJson('/api/v1/espace/videos/library', $this->enTetes($jeton))->json('data.*.id'));
    }

    public function test_dans_l_app_iphone_on_monte_un_episode_mais_rien_ne_se_met_en_vente(): void
    {
        $vet = $this->verifiedVet();
        $iphone = $this->enTetes($vet['jeton']) + ['X-Veterinary-Platform' => 'ios'];
        $series = $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Depuis l’iPhone'], $iphone)->assertCreated()->json('data.id');
        $episode = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Un'], $iphone)->assertCreated()->json('data.id');
        $this->postJson(self::SELLER."/{$episode}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $iphone)->assertOk();

        $this->postJson(self::SELLER."/{$series}/publish", [], $iphone)->assertUnprocessable()->assertJsonValidationErrors(['platform']);
    }

    // ————— Acheter —————

    public function test_une_serie_s_achete_entiere_au_prix_de_la_serie_un_episode_jamais_seul(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['price_cents' => 4500]);
        $episode = Video::query()->where('kind', 'episode')->value('uuid');
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson("/api/v1/espace/videos/{$episode}/purchase", ['accept_terms' => true], $this->enTetes($jeton))->assertNotFound();
        // Même avec un prix à lui (donnée d'avant, écriture directe), un épisode ne se vend jamais seul
        Video::query()->where('uuid', $episode)->update(['price_cents' => 900, 'audience' => 'owners']);
        $this->postJson("/api/v1/espace/videos/{$episode}/purchase", ['accept_terms' => true], $this->enTetes($jeton))->assertNotFound();
        $this->postJson("/api/v1/espace/videos/{$series}/purchase", ['accept_terms' => true], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.amount_cents', 4500);

        $purchase = VideoPurchase::query()->sole();
        $this->assertSame(Video::query()->where('uuid', $series)->value('id'), $purchase->video_id);
        $this->assertSame(1350, $purchase->commission_cents);
    }

    public function test_qui_a_achete_la_serie_regarde_tous_ses_episodes_meme_ceux_ajoutes_apres_jamais_ceux_en_preparation(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->buy($jeton, $series);

        $later = $this->readyEpisode($vet, $series, 'Ajouté après');
        $this->postJson("/api/v1/espace/videos/{$later}/play", [], $this->enTetes($jeton))->assertNotFound();
        $this->postJson(self::SELLER."/{$later}/publish", [], $this->enTetes($vet['jeton']))->assertOk();

        $this->postJson("/api/v1/espace/videos/{$later}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
        $show = $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->assertOk();
        $show->assertJsonPath('data.access', 'owned')->assertJsonCount(2, 'data.episodes');
        $this->assertSame([1, 2], array_column($show->json('data.episodes'), 'number'));
    }

    public function test_un_abonne_plus_regarde_les_episodes_d_une_serie_incluse_et_ses_vues_comptent_pour_la_cagnotte(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->newSeries($vet, ['in_plus' => true]);
        $this->readyEpisode($vet, $series, 'Trois minutes', 180);
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $episode = Video::query()->where('kind', 'episode')->sole();
        ['user' => $subscriber, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $subscriber->forceFill(['formule' => 'plus'])->save();

        $this->postJson("/api/v1/espace/videos/{$episode->uuid}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.access', 'plus');
        $this->travel(2)->minutes();
        // Une vue compte au-delà d'une minute regardée
        $this->postJson("/api/v1/espace/videos/{$episode->uuid}/progress", ['ranges' => [[0, 70]]], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.counted', true);

        $view = VideoView::query()->where('video_id', $episode->id)->sole();
        $this->assertTrue((bool) $view->counted_via_plus);
    }

    public function test_la_premiere_minute_de_chaque_episode_se_regarde_gratuitement(): void
    {
        $vet = $this->verifiedVet();
        $this->publishedSeries($vet);
        $episode = Video::query()->where('kind', 'episode')->value('uuid');
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson("/api/v1/espace/videos/{$episode}/play", [], $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.scope', 'preview')->assertJsonPath('data.access', 'preview');
    }

    // ————— Regarder —————

    public function test_une_serie_parait_une_fois_dans_le_catalogue_avec_ses_episodes_comptes_jamais_ses_episodes_seuls(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['category' => 'cats']);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $this->newSeries($vet);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        // Même un épisode qui porterait un public à lui (donnée d'avant, écriture directe) ne paraît jamais seul
        Video::query()->where('kind', 'episode')->update(['audience' => 'owners']);
        $items = $this->getJson('/api/v1/espace/videos', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertSame([$series], array_column($items, 'id'));
        $this->assertSame('series', $items[0]['kind']);
        $this->assertSame(2, $items[0]['episodes_count']);
        $this->assertEqualsWithDelta(60.0, $items[0]['duration_seconds'], 0.01);

        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key');
        $this->assertSame([$series], array_column($rows['new']['videos'], 'id'));
        $this->assertSame([$series], array_column($rows['category']['videos'], 'id'));
    }

    public function test_une_serie_sans_episode_ajoute_ne_parait_pas(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        Video::query()->where('kind', 'episode')->update(['status' => 'draft']);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->assertSame([], $this->getJson('/api/v1/espace/videos', $this->enTetes($jeton))->json('data'));
        $this->postJson("/api/v1/espace/videos/{$series}/purchase", ['accept_terms' => true], $this->enTetes($jeton))->assertNotFound();
    }

    public function test_la_fenetre_d_une_serie_montre_ses_episodes_ajoutes_et_ou_en_est_la_personne(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $this->postJson(self::SELLER, ['series' => $series, 'title' => 'En préparation'], $this->enTetes($vet['jeton']));
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $episode = Video::query()->where('kind', 'episode')->where('episode_number', 1)->sole();
        VideoView::query()->create(['video_id' => $episode->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 20]], 'watched_seconds' => 20, 'last_progress_at' => now()]);

        $data = $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertSame('preview', $data['access']);
        $this->assertCount(1, $data['episodes']);
        $this->assertSame(['id' => $episode->uuid, 'number' => 1, 'title' => 'Épisode 1'], array_intersect_key($data['episodes'][0], array_flip(['id', 'number', 'title'])));
        $this->assertSame(20, $data['episodes'][0]['viewing']['watched_seconds']);
        $this->assertFalse($data['viewing']['completed']);

        // Son vendeur voit aussi l'épisode en préparation
        $this->assertCount(2, $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($vet['jeton']))->json('data.episodes'));
    }

    public function test_continuer_a_regarder_reprend_une_serie_a_l_episode_commence(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $series);
        foreach (Video::query()->where('kind', 'episode')->get() as $episode) {
            VideoView::query()->create(['video_id' => $episode->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 10]], 'watched_seconds' => 10, 'last_progress_at' => now()->addSeconds($episode->episode_number)]);
        }

        $continue = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key')['continue']['videos'];

        $this->assertCount(1, $continue);
        $this->assertSame($series, $continue[0]['id']);
        $this->assertSame(['episode_id' => $second, 'episode_number' => 2], array_intersect_key($continue[0]['resume'], array_flip(['episode_id', 'episode_number'])));
    }

    public function test_continuer_a_voir_propose_l_episode_suivant_d_un_episode_fini_et_laisse_une_serie_finie(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $series);
        $episodes = Video::query()->where('kind', 'episode')->orderBy('episode_number')->get();
        $this->completed($owner, $episodes[0]);

        $continue = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key')['continue']['videos'];
        $this->assertSame([$series], array_column($continue, 'id'));
        $this->assertSame(['episode_id' => $second, 'episode_number' => 2, 'watched_seconds' => 0], array_intersect_key($continue[0]['resume'], array_flip(['episode_id', 'episode_number', 'watched_seconds'])));

        // La série vue jusqu'au bout quitte la rangée, même revue en commençant par le premier épisode
        $this->completed($owner, $episodes[1]);
        $this->travel(1)->minutes();
        VideoView::query()->where('video_id', $episodes[0]->id)->update(['last_progress_at' => now()]);
        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key');
        $this->assertFalse($rows->has('continue'));
    }

    public function test_la_recherche_trouve_une_serie_par_le_titre_d_un_de_ses_episodes(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['title' => 'Le chat âgé'], 'Les croquettes rénales');
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->assertSame([$series], $this->getJson('/api/v1/espace/videos?q=r%C3%A9nales', $this->enTetes($jeton))->json('data.*.id'));
    }

    public function test_l_apercu_d_une_serie_est_celui_de_son_premier_episode(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $episode = Video::query()->where('kind', 'episode')->value('uuid');
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $url = (string) $this->getJson("/api/v1/espace/videos/{$series}/teaser", $this->enTetes($jeton))->assertOk()->json('data.url');
        $this->assertStringContainsString("/videos/{$episode}/hls/master.m3u8", $url);
        $this->assertSame(0, VideoView::query()->count());
    }

    // ————— Certificat —————

    public function test_une_serie_vue_en_entier_donne_un_certificat_de_la_serie_un_episode_n_en_donne_pas(): void
    {
        // Deux épisodes envoyés morceau par morceau : bien plus de requêtes qu'une personne n'en fait en une minute
        $this->withoutMiddleware(ThrottleRequests::class);
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['title' => 'Le chat âgé']);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $series);
        $episodes = Video::query()->where('kind', 'episode')->orderBy('episode_number')->get();

        $this->completed($owner, $episodes[0]);
        // Vu en entier, un épisode ne donne pas de certificat à lui
        $this->postJson("/api/v1/espace/videos/{$episodes[0]->uuid}/certificate", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['certificate' => 'Le certificat d’une série se délivre pour la série entière, une fois tous ses épisodes vus.']);
        $this->postJson("/api/v1/espace/videos/{$series}/certificate", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['certificate' => 'Le certificat de la série se délivre une fois tous ses épisodes regardés en entier.']);
        $this->completed($owner, $episodes[1]);

        $data = $this->postJson("/api/v1/espace/videos/{$series}/certificate", [], $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertSame('Le chat âgé', $data['video_title']);
        $certificate = ViewingCertificate::query()->sole();
        $this->assertEqualsWithDelta(60.0, (float) $certificate->duration_seconds, 0.01);
        $this->assertSame(Video::query()->where('uuid', $series)->value('id'), $certificate->video_id);
        $this->getJson('/api/v1/public/certificates/'.$certificate->code)->assertOk()->assertJsonPath('data.video_title', 'Le chat âgé');
    }

    // ————— Effacer son compte, conditions —————

    public function test_un_vendeur_efface_sa_serie_jamais_achetee_part_avec_ses_episodes_une_serie_achetee_reste_a_ses_acheteurs(): void
    {
        $vet = $this->verifiedVet();
        $unsold = $this->publishedSeries($vet, ['title' => 'Jamais achetée']);
        $sold = $this->publishedSeries($vet, ['title' => 'Achetée']);
        $preparing = $this->postJson(self::SELLER, ['series' => $sold, 'title' => 'En préparation'], $this->enTetes($vet['jeton']))->json('data.id');
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($buyer, $sold);
        $soldEpisode = Video::query()->where('series_id', Video::query()->where('uuid', $sold)->value('id'))->where('status', 'published')->value('uuid');

        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));

        $this->assertFalse(Video::query()->where('uuid', $unsold)->exists());
        $this->assertSame(0, Video::query()->where('series_id', '!=', null)->whereHas('series', fn ($series) => $series->where('uuid', $unsold))->count());
        $kept = Video::query()->where('uuid', $sold)->sole();
        $this->assertSame('withdrawn', $kept->status->value);
        $this->assertNull($kept->seller_user_id);
        $this->assertFalse(Video::query()->where('uuid', $preparing)->exists());
        $this->postJson("/api/v1/espace/videos/{$soldEpisode}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
    }

    public function test_les_conditions_de_vente_ont_une_nouvelle_version_pour_les_series_que_chaque_vendeur_reaccepte(): void
    {
        $this->assertSame('2026-10-31-series', SellerTerms::VERSION);
        $vet = $this->verifiedVet();
        $vet['user']->forceFill(['video_terms_version' => '2026-10-31'])->save();

        $this->getJson(self::SELLER, $this->enTetes($vet['jeton']))->assertJsonPath('meta.terms', ['version' => '2026-10-31-series', 'accepted' => false]);
    }

    // ————— Vérification des cases (31.10.2026 au soir) —————

    public function test_l_acheteur_garde_un_episode_sorti_de_la_vente_et_le_voit_dans_la_liste_les_autres_non(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($buyer, $series);
        // La relecture l'a sorti de la vente (ses fichiers restent lisibles)
        Video::query()->where('uuid', $second)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);

        $this->postJson("/api/v1/espace/videos/{$second}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
        $this->assertSame(['Épisode 1', 'Deux'], array_column($this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->json('data.episodes'), 'title'));
        ['jeton' => $other] = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$second}/play", [], $this->enTetes($other))->assertNotFound();
        $this->assertSame(['Épisode 1'], array_column($this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($other))->json('data.episodes'), 'title'));
        // Un épisode jamais ajouté (en préparation, ses fichiers pourtant prêts) ne lui est jamais donné
        $draft = $this->readyEpisode($vet, $series, 'En préparation');
        $this->postJson("/api/v1/espace/videos/{$draft}/play", [], $this->enTetes($jeton))->assertNotFound();
    }

    public function test_chaque_episode_achete_se_telecharge_et_dit_quand_il_a_ete_regarde(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $episode = Video::query()->where('kind', 'episode')->sole();
        $episode->forceFill(['download_path' => 'download.mp4'])->save();
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        VideoView::query()->create(['video_id' => $episode->id, 'user_id' => $buyer->id, 'watched_ranges' => [[0, 12]], 'watched_seconds' => 12, 'last_progress_at' => now()]);

        $listed = $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->json('data.episodes.0');
        $this->assertFalse($listed['can_download']);
        $this->assertNotNull($listed['viewing']['last_watched_at']);
        $this->getJson("/api/v1/espace/videos/{$episode->uuid}/download", $this->enTetes($jeton))->assertNotFound();

        $this->own($buyer, $series);
        $this->assertTrue($this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->json('data.episodes.0.can_download'));
        $this->getJson("/api/v1/espace/videos/{$episode->uuid}/download", $this->enTetes($jeton))->assertOk()->assertJsonStructure(['data' => ['url']]);
    }

    public function test_a_l_effacement_du_vendeur_qui_a_vu_toute_la_serie_recoit_son_certificat(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['title' => 'Gratuite', 'price_cents' => 0]);
        $episode = Video::query()->where('kind', 'episode')->sole();
        ['user' => $viewer] = $this->proprietaireAvecJeton();
        ['user' => $halfway] = $this->proprietaireAvecJeton();
        $this->completed($viewer, $episode);
        VideoView::query()->create(['video_id' => $episode->id, 'user_id' => $halfway->id, 'watched_ranges' => [[0, 5]], 'watched_seconds' => 5, 'last_progress_at' => now()]);

        $vet['user']->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        $this->assertTrue(app(SuppressionCompte::class)->effacer($vet['user']));

        $certificate = ViewingCertificate::query()->where('user_id', $viewer->id)->sole();
        $this->assertSame('Gratuite', $certificate->video_title);
        $this->assertFalse(ViewingCertificate::query()->where('user_id', $halfway->id)->exists());
        $this->getJson('/api/v1/public/certificates/'.$certificate->code)->assertOk()->assertJsonPath('data.video_title', 'Gratuite');
    }

    public function test_une_serie_retiree_vue_en_entier_rouvre_sa_fenetre_pour_le_certificat(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['title' => 'Retirée', 'in_plus' => true]);
        $episode = Video::query()->where('kind', 'episode')->sole();
        ['user' => $viewer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->completed($viewer, $episode);
        $this->postJson(self::SELLER."/{$series}/withdraw", [], $this->enTetes($vet['jeton']))->assertOk();

        $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->assertOk()->assertJsonPath('data.access', null)->assertJsonPath('data.viewing.completed', true);
        $this->postJson("/api/v1/espace/videos/{$series}/certificate", [], $this->enTetes($jeton))->assertOk();
        // Pas vue en entier : rien
        ['jeton' => $other] = $this->proprietaireAvecJeton();
        $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($other))->assertNotFound();
    }

    public function test_continuer_a_voir_ne_se_laisse_pas_cacher_par_un_lancement_par_erreur_et_laisse_la_minute_gratuite_finie(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $episodes = Video::query()->where('kind', 'episode')->orderBy('episode_number')->get();
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $series);
        // L'épisode 1 regardé un moment, puis l'épisode 2 lancé 3 secondes (le plus récent)
        VideoView::query()->create(['video_id' => $episodes[0]->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 20]], 'watched_seconds' => 20, 'last_progress_at' => now()->subMinute()]);
        VideoView::query()->create(['video_id' => $episodes[1]->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 3]], 'watched_seconds' => 3, 'last_progress_at' => now()]);
        $continue = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key')['continue']['videos'];
        $this->assertSame($episodes[0]->uuid, $continue[0]['resume']['episode_id']);

        // Sans la série : la minute gratuite d'un épisode, finie, quitte la rangée
        ['user' => $visitor, 'jeton' => $visitorToken] = $this->proprietaireAvecJeton();
        VideoView::query()->create(['video_id' => $episodes[0]->id, 'user_id' => $visitor->id, 'watched_ranges' => [[0, 60]], 'watched_seconds' => 60, 'last_progress_at' => now()]);
        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($visitorToken))->json('data.rows'))->keyBy('key');
        $this->assertFalse($rows->has('continue'));
        VideoView::query()->where('user_id', $visitor->id)->update(['watched_seconds' => 30]);
        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($visitorToken))->json('data.rows'))->keyBy('key');
        $this->assertSame([$series], array_column($rows['continue']['videos'], 'id'));
    }

    public function test_une_serie_en_vente_garde_au_moins_un_episode(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $only = Video::query()->where('kind', 'episode')->sole()->uuid;
        $this->deleteJson(self::SELLER."/{$only}", [], $this->enTetes($vet['jeton']))->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'La série est en vente : elle garde au moins un épisode. Retirez-la de la vente pour supprimer celui-ci.']);
        // Avec un autre épisode ajouté, il s'efface
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $this->deleteJson(self::SELLER."/{$only}", [], $this->enTetes($vet['jeton']))->assertNoContent();
    }

    public function test_l_ordre_d_une_serie_en_vente_ne_se_change_pas_dans_l_app_iphone(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $first = Video::query()->where('kind', 'episode')->where('episode_number', 1)->value('uuid');
        $iphone = $this->enTetes($vet['jeton']) + ['X-Veterinary-Platform' => 'ios'];
        $this->postJson(self::SELLER."/{$series}/episodes/order", ['episodes' => [$second, $first]], $iphone)->assertUnprocessable();
        $this->postJson(self::SELLER."/{$series}/episodes/order", ['episodes' => [$second, $first]], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_une_serie_ne_se_met_en_vente_qu_avec_ses_conditions_acceptees(): void
    {
        $vet = $this->verifiedVet();
        $vet['user']->forceFill(['video_terms_version' => null, 'video_terms_accepted_at' => null])->save();
        $series = $this->newSeries($vet);
        $this->readyEpisode($vet, $series, 'Un');
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors(['terms']);
        $this->postJson(self::SELLER."/{$series}/publish", ['accept_terms' => true], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');
    }

    // ————— Deuxième passage de vérification —————

    public function test_la_liste_des_episodes_dit_leur_numero_image_titre_duree_description_et_part_vue(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, [], 'Les besoins');
        $episode = Video::query()->where('kind', 'episode')->sole();
        $episode->forceFill(['description' => 'Ce qu’il mange.'])->save();
        ['user' => $viewer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        VideoView::query()->create(['video_id' => $episode->id, 'user_id' => $viewer->id, 'watched_ranges' => [[0, 12]], 'watched_seconds' => 12, 'last_progress_at' => now()]);

        $listed = $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->json('data.episodes.0');

        $this->assertSame(1, $listed['number']);
        $this->assertSame('Les besoins', $listed['title']);
        $this->assertSame('Ce qu’il mange.', $listed['description']);
        $this->assertEqualsWithDelta(30.0, $listed['duration_seconds'], 0.5);
        $this->assertStringContainsString('/videos/posters/', (string) $listed['poster']['url']);
        $this->assertSame(12, $listed['viewing']['watched_seconds']);
    }

    public function test_un_episode_sorti_de_la_vente_ne_compte_pas_pour_le_certificat_de_l_acheteur(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['user' => $buyer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($buyer, $series);
        $out = Video::query()->where('uuid', $second)->sole();
        $out->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->completed($buyer, $out);

        $viewing = $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->json('data.viewing');
        $this->assertFalse($viewing['completed']);
        $this->assertSame(0, $viewing['completed_episodes']);
    }

    public function test_la_relecture_qui_renvoie_le_seul_episode_en_brouillon_retire_la_serie_de_la_vente(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $episode = Video::query()->where('kind', 'episode')->sole();
        // Une adresse donnée avant le contrôle écrit encore : une 4K en HEVC remplace la 1080p
        Storage::disk('prive')->put($episode->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));
        RecheckVideoFiles::dispatchSync($episode->id);

        $this->assertSame('draft', $episode->refresh()->status->value);
        $this->assertSame('withdrawn', Video::query()->where('uuid', $series)->value('status')->value);
    }

    public function test_un_episode_en_vente_peut_perdre_sa_description_jamais_son_titre(): void
    {
        $vet = $this->verifiedVet();
        $this->publishedSeries($vet);
        $episode = Video::query()->where('kind', 'episode')->sole();
        $episode->forceFill(['description' => 'Texte'])->save();
        $this->patchJson(self::SELLER."/{$episode->uuid}", ['description' => null], $this->enTetes($vet['jeton']))->assertOk();
        $this->patchJson(self::SELLER."/{$episode->uuid}", ['title' => null], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors(['title']);
    }

    public function test_la_fenetre_d_une_serie_plus_regardable_se_dit_serie(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet, ['title' => 'Retirée']);
        ['user' => $viewer, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->completed($viewer, Video::query()->where('kind', 'episode')->sole());
        $this->postJson(self::SELLER."/{$series}/withdraw", [], $this->enTetes($vet['jeton']))->assertOk();
        $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->assertOk()->assertJsonPath('data.kind', 'series');
    }

    public function test_continuer_a_voir_reprend_un_episode_plus_ancien_laisse_en_route(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $episodes = Video::query()->where('kind', 'episode')->orderBy('episode_number')->get();
        ['user' => $owner, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->own($owner, $series);
        // Le premier commencé (40 s), puis le dernier vu en entier, plus tard
        VideoView::query()->create(['video_id' => $episodes[0]->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 40]], 'watched_seconds' => 40, 'last_progress_at' => now()->subHour()]);
        VideoView::query()->create(['video_id' => $episodes[1]->id, 'user_id' => $owner->id, 'watched_ranges' => [[0, 30]], 'watched_seconds' => 30, 'last_progress_at' => now(), 'completed_at' => now()]);

        $continue = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key')['continue']['videos'];
        $this->assertSame($episodes[0]->uuid, $continue[0]['resume']['episode_id']);
        $this->assertSame(40, $continue[0]['resume']['watched_seconds']);
    }

    // ————— Troisième passage —————

    public function test_la_relecture_qui_retire_le_seul_episode_d_une_serie_vendue_la_retire_aussi(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        ['user' => $buyer] = $this->proprietaireAvecJeton();
        $this->own($buyer, $series);
        $episode = Video::query()->where('kind', 'episode')->sole();
        Storage::disk('prive')->put($episode->folder().'/init-1.mp4', $this->initSegment(3840, 2160, 'hvc1'));
        RecheckVideoFiles::dispatchSync($episode->id);

        // Achetée : l'épisode reste à l'acheteur, hors de la vente ; la série, vide en vente, la quitte aussi
        $this->assertSame('withdrawn', $episode->refresh()->status->value);
        $this->assertSame('withdrawn', Video::query()->where('uuid', $series)->value('status')->value);
    }

    public function test_un_episode_que_l_effacement_ne_peut_pas_prendre_reprend_sa_place_dans_la_serie(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->publishedSeries($vet);
        $second = $this->readyEpisode($vet, $series, 'Deux');
        $this->postJson(self::SELLER."/{$second}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        $episode = Video::query()->where('uuid', $second)->sole();
        VideoPosters::$lockWait = 0;
        $held = VideoPosters::lock($episode->id);
        $this->assertTrue($held->get());
        try {
            $this->deleteJson(self::SELLER."/{$second}", [], $this->enTetes($vet['jeton']))->assertStatus(409);
        } finally {
            $held->release();
            VideoPosters::$lockWait = 10;
        }
        $this->assertSame('published', $episode->refresh()->status->value);
    }

    // ————— Outils —————

    /** Une série en brouillon, sa fiche remplie ; rend son identifiant. */
    private function newSeries(array $vet, array $fields = []): string
    {
        $id = $this->postJson(self::SELLER, ['kind' => 'series', 'title' => $fields['title'] ?? 'Alimentation du chat'], $this->enTetes($vet['jeton']))->assertCreated()->json('data.id');
        $this->fillSheet(self::SELLER."/{$id}", ['description' => 'Cinq épisodes.', 'price_cents' => 4500, 'audience' => 'owners', ...$fields], $this->enTetes($vet['jeton']));

        return $id;
    }

    /** Un épisode envoyé, contrôlé, relu, avec son image ; rend son identifiant. */
    private function readyEpisode(array $vet, string $series, string $title, float $seconds = 30): string
    {
        $id = $this->postJson(self::SELLER, ['series' => $series, 'title' => $title], $this->enTetes($vet['jeton']))->assertCreated()->json('data.id');
        $this->upload($vet['jeton'], $id, $this->hlsPackage($seconds));
        $this->finish($vet['jeton'], $id)->assertOk();
        Video::query()->where('uuid', $id)->update(['poster_token' => Str::random(40)]);

        return $id;
    }

    /** Une série en vente avec un premier épisode ; rend son identifiant. */
    private function publishedSeries(array $vet, array $fields = [], string $firstEpisode = 'Épisode 1'): string
    {
        $series = $this->newSeries($vet, $fields);
        $this->readyEpisode($vet, $series, $firstEpisode);
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.status', 'published');

        return $series;
    }

    private function own(User $user, string $id): void
    {
        $video = Video::query()->where('uuid', $id)->sole();
        VideoPurchase::query()->create(['video_id' => $video->id, 'user_id' => $user->id, 'amount_cents' => 4500, 'commission_cents' => 1350, 'terms_accepted_at' => now(), 'paid_at' => now(), 'stripe_account_id' => 'acct_test', 'payment_intent_id' => 'pi_'.Str::random(10)]);
    }

    /** Achète comme le navigateur : case cochée, paiement abouti, retour. */
    private function buy(string $jeton, string $id): void
    {
        $this->postJson("/api/v1/espace/videos/{$id}/purchase", ['accept_terms' => true], $this->enTetes($jeton))->assertOk();
        $intent = VideoPurchase::query()->latest('id')->firstOrFail()->payment_intent_id;
        $this->gateway->conclure($intent, 'succeeded');
        $this->postJson("/api/v1/espace/videos/{$id}/purchase/confirm", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.paid', true);
    }

    private function completed(User $user, Video $episode): void
    {
        VideoView::query()->updateOrCreate(['video_id' => $episode->id, 'user_id' => $user->id], [
            'watched_ranges' => [[0, 30]], 'watched_seconds' => 30, 'last_progress_at' => now(), 'completed_at' => now(),
        ]);
    }
}
