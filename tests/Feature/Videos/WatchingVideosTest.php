<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Models\FactureProprietaire;
use App\Models\PlusPoolMonth;
use App\Models\PlusPoolShare;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Paiements\PasserellePaiement;
use App\Services\Videos\Mp4InitReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Vidéos de formation, regarder et acheter (décisions de Jim, 30 et
 * 31.10.2026) : aperçu d'une minute, achat définitif avec 30 % de
 * commission, lien signé qui expire, téléchargement 720p, Plus, vues,
 * cagnotte mensuelle, certificat de visionnage.
 */
class WatchingVideosTest extends TestCase
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

    /** Une vidéo de 3 minutes en segments de 6 secondes, en vente. */
    private function onSale(array $fields = [], ?array $vet = null): array
    {
        $vet ??= $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage(180));
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", [
            'title' => 'Nourrir un chat senior', 'description' => 'Ce qu’il faut savoir.', 'price_cents' => 1500, 'audience' => 'owners', ...$fields,
        ], $this->enTetes($vet['jeton']))->assertOk();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/publish", [], $this->enTetes($vet['jeton']))->assertOk();

        return ['id' => $id, 'vet' => $vet];
    }

    private function play(string $jeton, string $id): TestResponse
    {
        return $this->postJson("/api/v1/espace/videos/{$id}/play", [], $this->enTetes($jeton));
    }

    /** @return list<string> les lignes d'adresse d'une liste HLS */
    private function uris(string $playlist): array
    {
        return array_values(array_filter(explode("\n", $playlist), fn ($line) => $line !== '' && ! str_starts_with($line, '#')));
    }

    /** Les segments que la liste de la première qualité laisse lire. */
    private function segmentsOf(string $masterUrl): array
    {
        $master = $this->get($masterUrl)->assertOk()->getContent();
        $media = $this->get($this->uris($master)[0])->assertOk()->getContent();

        return $this->uris($media);
    }

    private function progress(string $jeton, string $id, array $ranges): TestResponse
    {
        return $this->postJson("/api/v1/espace/videos/{$id}/progress", ['ranges' => $ranges], $this->enTetes($jeton));
    }

    /** Regarde de `from` à `to` comme le lecteur : un envoi toutes les 15 secondes de lecture */
    private function watch(string $jeton, string $id, float $from, float $to): TestResponse
    {
        $response = null;
        for ($at = $from; $at < $to; $at += 15) {
            $this->travel(15)->seconds();
            $response = $this->progress($jeton, $id, [[$at, min($to, $at + 15)]]);
        }

        return $response;
    }

    /** Achète comme le navigateur : case cochée, paiement abouti, retour. */
    private function buy(string $jeton, string $id): string
    {
        $this->postJson("/api/v1/espace/videos/{$id}/purchase", ['accept_terms' => true], $this->enTetes($jeton))->assertOk();
        $intent = VideoPurchase::query()->latest('id')->firstOrFail()->payment_intent_id;
        $this->gateway->conclure($intent, 'succeeded');
        $this->postJson("/api/v1/espace/videos/{$id}/purchase/confirm", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.paid', true);

        return $intent;
    }

    // ————— Catalogue et achat —————

    public function test_la_premiere_minute_se_regarde_gratuitement_et_rien_apres(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();

        $play = $this->play($owner['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'preview')->assertJsonPath('data.access', 'preview');
        // 10 segments de 6 secondes : la première minute, pas un de plus
        $this->assertCount(10, $this->segmentsOf($play->json('data.url')));
        // Changer l'aperçu en lecture complète casse la signature
        $this->get(str_replace('scope=preview', 'scope=full', $play->json('data.url')))->assertForbidden();

        // Le vendeur, lui, a les 30 segments
        $seller = $this->play($sale['vet']['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
        $this->assertCount(30, $this->segmentsOf($seller->json('data.url')));
    }

    public function test_l_achat_se_fait_sur_le_site_apres_avoir_coche_que_l_acces_commence_et_qu_il_est_definitif(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();
        $url = "/api/v1/espace/videos/{$sale['id']}/purchase";

        $this->postJson($url, [], $this->enTetes($owner['jeton']))->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
        $this->postJson($url, ['accept_terms' => true], [...$this->enTetes($owner['jeton']), 'X-Veterinary-Platform' => 'ios'])
            ->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->assertSame([], $this->gateway->intentions);

        $this->postJson($url, ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk()
            ->assertJsonPath('data.amount_cents', 1500)
            ->assertJsonStructure(['data' => ['client_secret', 'account']]);
        $this->assertNotNull(VideoPurchase::query()->sole()->terms_accepted_at);
        // Pas encore payé : toujours l'aperçu
        $this->play($owner['jeton'], $sale['id'])->assertJsonPath('data.scope', 'preview');
    }

    public function test_veterinary_preleve_30_pourcent_et_le_reste_va_au_vendeur_qui_porte_les_frais(): void
    {
        $sale = $this->onSale(['price_cents' => 2990]);
        $owner = $this->proprietaireAvecJeton();
        $intent = $this->buy($owner['jeton'], $sale['id']);

        // Paiement direct sur le compte du vendeur : le prestataire y prend ses frais ;
        // la commission de Veterinary part en frais d'application
        $charge = $this->gateway->intentions[$intent];
        $this->assertSame('acct_vet_'.$sale['vet']['user']->id, $charge['compte']);
        $this->assertSame(2990, $charge['centimes']);
        $this->assertSame(897, $charge['commission']);
        $this->assertSame('video', $charge['metadonnees']['objet']);
        $this->assertSame(897, VideoPurchase::query()->sole()->commission_cents);
    }

    public function test_le_paiement_confirme_par_le_prestataire_donne_la_video_meme_sans_retour_du_navigateur(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))->assertOk();
        $intent = VideoPurchase::query()->sole()->payment_intent_id;
        $this->gateway->conclure($intent, 'succeeded');

        // Venu d'un autre compte : ignoré
        $this->webhook(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'account' => 'acct_autre', 'data' => ['object' => ['id' => $intent, 'status' => 'succeeded', 'metadata' => ['objet' => 'video']]]])->assertNoContent();
        $this->assertNull(VideoPurchase::query()->sole()->paid_at);

        $this->webhook(['id' => 'evt_2', 'type' => 'payment_intent.succeeded', 'account' => 'acct_vet_'.$sale['vet']['user']->id, 'data' => ['object' => ['id' => $intent, 'status' => 'succeeded', 'metadata' => ['objet' => 'video']]]])->assertNoContent();
        $this->assertNotNull(VideoPurchase::query()->sole()->paid_at);
        $this->play($owner['jeton'], $sale['id'])->assertJsonPath('data.access', 'owned')->assertJsonPath('data.scope', 'full');
    }

    public function test_aucun_remboursement_n_est_propose_chaque_achat_est_definitif(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();
        $this->buy($owner['jeton'], $sale['id']);

        // Aucune route pour rendre l'argent d'une vidéo
        $refunds = collect(Route::getRoutes()->getRoutes())->map->uri()
            ->filter(fn (string $uri) => str_contains($uri, 'videos') && preg_match('/refund|rembours|annul|cancel/', $uri));
        $this->assertSame([], $refunds->values()->all());
        $this->assertSame([], $this->gateway->appelsDe('rembourser'));
        // Ni un second achat
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/purchase", ['accept_terms' => true], $this->enTetes($owner['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors('purchase');
    }

    public function test_une_video_achetee_se_regarde_par_un_lien_signe_qui_expire(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();
        $this->buy($owner['jeton'], $sale['id']);

        $url = $this->play($owner['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full')->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertCount(30, $this->segmentsOf($url));

        $this->travel(8)->hours();
        $this->travel(1)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_une_video_achetee_se_telecharge_en_720p_et_seulement_par_son_acheteur(): void
    {
        $sale = $this->onSale();
        $owner = $this->proprietaireAvecJeton();
        $other = $this->proprietaireAvecJeton();
        $this->buy($owner['jeton'], $sale['id']);

        $this->getJson("/api/v1/espace/videos/{$sale['id']}/download", $this->enTetes($other['jeton']))->assertNotFound();
        $url = $this->getJson("/api/v1/espace/videos/{$sale['id']}/download", $this->enTetes($owner['jeton']))->assertOk()->json('data.url');

        // La qualité 720p mise bout à bout : son initialisation puis ses segments
        $package = $this->hlsPackage(180);
        $expected = $package['init-2.mp4'];
        foreach ($this->uris($package['video-and-audio-2.m3u8']) as $segment) {
            $expected .= $package[$segment];
        }
        $file = $this->get($url)->assertOk();
        $this->assertSame($expected, $file->streamedContent() ?: $file->getContent());
        $picture = collect(Mp4InitReader::read($package['init-2.mp4']))->firstWhere('handler', 'vide');
        $this->assertSame(720, $picture['height']);
    }

    public function test_ceux_qui_ont_achete_gardent_la_video_retiree_les_autres_la_perdent(): void
    {
        $sale = $this->onSale(['in_plus' => true]);
        $buyer = $this->proprietaireAvecJeton();
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $visitor = $this->proprietaireAvecJeton();
        $this->buy($buyer['jeton'], $sale['id']);

        $this->postJson("/api/v1/espace/videos/seller/videos/{$sale['id']}/withdraw", [], $this->enTetes($sale['vet']['jeton']))->assertOk();

        $this->play($buyer['jeton'], $sale['id'])->assertOk()->assertJsonPath('data.scope', 'full');
        $this->getJson("/api/v1/espace/videos/{$sale['id']}/download", $this->enTetes($buyer['jeton']))->assertOk();
        $this->play($plus['jeton'], $sale['id'])->assertNotFound();
        $this->play($visitor['jeton'], $sale['id'])->assertNotFound();
    }

    // ————— Plus —————

    public function test_un_abonne_plus_regarde_sans_payer_les_videos_incluses_dans_plus(): void
    {
        $included = $this->onSale(['in_plus' => true]);
        $notIncluded = $this->onSale(['in_plus' => false]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $free = $this->proprietaireAvecJeton();

        $this->play($plus['jeton'], $included['id'])->assertJsonPath('data.access', 'plus')->assertJsonPath('data.scope', 'full');
        $this->play($plus['jeton'], $notIncluded['id'])->assertJsonPath('data.scope', 'preview');
        $this->play($free['jeton'], $included['id'])->assertJsonPath('data.scope', 'preview');
        // Plus ne donne pas le téléchargement : il est à l'acheteur
        $this->getJson("/api/v1/espace/videos/{$included['id']}/download", $this->enTetes($plus['jeton']))->assertNotFound();
    }

    public function test_une_vue_compte_apres_plus_d_une_minute_une_seule_fois_par_personne(): void
    {
        $sale = $this->onSale(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);

        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->travel(40)->seconds();
        $this->progress($plus['jeton'], $sale['id'], [[0, 40]])->assertJsonPath('data.counted', false);
        $this->travel(30)->seconds();
        $this->progress($plus['jeton'], $sale['id'], [[40, 70]])->assertJsonPath('data.counted', true);
        $countedAt = VideoView::query()->sole()->counted_at;

        // Revoir ne compte pas une deuxième vue
        $this->travel(1)->days();
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->travel(90)->seconds();
        $this->progress($plus['jeton'], $sale['id'], [[0, 90]])->assertJsonPath('data.counted', true);
        $this->assertSame(1, VideoView::query()->whereNotNull('counted_at')->count());
        $this->assertTrue(VideoView::query()->sole()->counted_at->equalTo($countedAt));
        $this->assertTrue(VideoView::query()->sole()->counted_via_plus);
    }

    public function test_on_ne_gagne_pas_une_minute_en_une_seconde_et_l_apercu_ne_compte_jamais(): void
    {
        $sale = $this->onSale(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus']);
        $free = $this->proprietaireAvecJeton();

        // Déclarer 3 minutes d'un coup : crédité de la marge du premier envoi seulement
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->progress($plus['jeton'], $sale['id'], [[0, 180]])->assertJsonPath('data.watched_seconds', 15)->assertJsonPath('data.counted', false);
        foreach (range(1, 5) as $i) {
            $this->progress($plus['jeton'], $sale['id'], [[0, 180]]);
        }
        $this->assertSame(15, VideoView::query()->where('user_id', $plus['user']->id)->sole()->watched_seconds);

        // L'aperçu : borné à la première minute, jamais une vue
        $this->play($free['jeton'], $sale['id'])->assertJsonPath('data.scope', 'preview');
        $this->travel(5)->minutes();
        $this->progress($free['jeton'], $sale['id'], [[0, 180]])->assertJsonPath('data.watched_seconds', 60)->assertJsonPath('data.counted', false);
    }

    /** Une facture Plus payée (12 CHF pour un an), avec son paiement chez le prestataire. */
    private function plusInvoice(string $from, string $paidAt): void
    {
        $user = User::factory()->create(['formule' => 'plus']);
        $intent = $this->gateway->pourCompte(null)->creerIntentionEnLigne(1200, [], 'plus-'.$user->id)['id'];
        $this->gateway->conclure($intent, 'succeeded');
        $invoice = new FactureProprietaire;
        $invoice->forceFill([
            'user_id' => $user->id, 'numero' => 'P-'.$user->id, 'emise_le' => $from, 'libelle' => 'Abonnement Veterinary Plus',
            'montant' => 12, 'montant_ht' => 12, 'statut' => 'payee', 'paye_le' => $paidAt, 'stripe_paiement_id' => $intent,
            'periode_debut' => $from, 'periode_fin' => date('Y-m-d', strtotime($from.' +1 year')),
            'metadonnees' => ['type' => 'abonnement', 'action' => 'souscription', 'formule' => 'plus'],
        ])->save();
    }

    /** Des vues comptées grâce à Plus, à une date donnée. */
    private function plusViews(Video $video, int $count, string $at): void
    {
        foreach (range(1, $count) as $i) {
            VideoView::query()->create([
                'video_id' => $video->id, 'user_id' => User::factory()->create()->id, 'watched_ranges' => [[0, 90]], 'watched_seconds' => 90,
                'last_progress_at' => $at, 'counted_at' => $at, 'counted_via_plus' => true,
            ]);
        }
    }

    private function sellerVideo(?string $account): Video
    {
        $seller = User::factory()->create([
            'verification_veterinaire' => StatutVerificationVeterinaire::Verifie,
            'stripe_account_id' => $account, 'stripe_account_ready_at' => $account ? now() : null,
        ]);

        return Video::factory()->create(['seller_user_id' => $seller->id, 'status' => 'published', 'audience' => 'owners', 'in_plus' => true, 'price_cents' => 500]);
    }

    public function test_chaque_mois_la_moitie_des_revenus_nets_de_plus_est_partagee_selon_les_vues(): void
    {
        $this->travelTo('2026-10-02 08:00');
        $a = $this->sellerVideo('acct_a');
        $b = $this->sellerVideo('acct_b');
        // 20 abonnements d'un an payés le 1er septembre, 12 CHF moins 65 centimes de frais
        foreach (range(1, 20) as $i) {
            $this->plusInvoice('2026-09-01', '2026-09-01 08:00:00');
        }
        // Ce qui n'est pas Plus ne compte pas : le badge vérifié payé le même mois
        $badge = new FactureProprietaire;
        $badge->forceFill([
            'user_id' => User::factory()->create()->id, 'numero' => 'B-1', 'emise_le' => '2026-09-05', 'libelle' => 'Badge vérifié',
            'montant' => 2, 'montant_ht' => 2, 'statut' => 'payee', 'paye_le' => '2026-09-05 08:00:00',
            'periode_debut' => '2026-09-05', 'periode_fin' => '2027-09-05', 'metadonnees' => ['type' => 'identite'],
        ])->save();
        $this->plusViews($a, 3, '2026-09-10 12:00:00');
        $this->plusViews($b, 1, '2026-09-20 12:00:00');
        // Vue hors du mois, ou pas grâce à Plus : rien
        $this->plusViews($b, 1, '2026-10-01 12:00:00');
        VideoView::query()->create(['video_id' => $a->id, 'user_id' => User::factory()->create()->id, 'watched_ranges' => [], 'watched_seconds' => 90, 'counted_at' => '2026-09-12 12:00:00', 'counted_via_plus' => false]);

        $this->artisan('videos:plus-pool', ['month' => '2026-09'])->assertSuccessful();

        // Un an payé d'avance : septembre en prend 30 jours sur 365
        $net = (int) floor((1200 - 65) * 20 * 30 / 365);
        $pool = PlusPoolMonth::query()->where('month', '2026-09')->sole();
        $this->assertSame($net, $pool->net_revenue_cents);
        $this->assertSame((int) floor($net / 2), $pool->pool_cents);
        $this->assertSame(4, $pool->views);
        $this->assertSame((int) floor($pool->pool_cents * 3 / 4), PlusPoolShare::query()->where('seller_user_id', $a->seller_user_id)->sole()->earned_cents);
        $this->assertSame((int) floor($pool->pool_cents / 4), PlusPoolShare::query()->where('seller_user_id', $b->seller_user_id)->sole()->earned_cents);

        // Relancer le même mois ne recalcule ni ne reverse rien
        $versements = count($this->gateway->versements);
        $this->artisan('videos:plus-pool', ['month' => '2026-09'])->assertSuccessful();
        $this->assertSame(1, PlusPoolMonth::query()->count());
        $this->assertCount($versements, $this->gateway->versements);
        // Un mois pas fini ne se calcule pas
        $this->artisan('videos:plus-pool', ['month' => '2026-10'])->assertFailed();
    }

    public function test_une_part_de_moins_de_10_chf_est_reportee_et_tout_est_verse_en_fin_d_annee(): void
    {
        $this->travelTo('2027-01-02 08:00');
        $a = $this->sellerVideo('acct_a');
        $b = $this->sellerVideo('acct_b');
        $c = $this->sellerVideo(null);
        foreach (range(1, 20) as $i) {
            $this->plusInvoice('2026-09-01', '2026-09-01 08:00:00');
        }
        $this->plusViews($a, 3, '2026-09-10 12:00:00');
        $this->plusViews($b, 1, '2026-09-20 12:00:00');
        $this->plusViews($a, 1, '2026-10-10 12:00:00');
        $this->plusViews($c, 1, '2026-12-10 12:00:00');
        $this->plusViews($a, 1, '2026-12-11 12:00:00');

        // Septembre : A a 6,99 CHF, B 2,33 CHF ; rien n'est versé
        $this->artisan('videos:plus-pool', ['month' => '2026-09'])->assertSuccessful();
        $this->assertSame([], $this->gateway->versements);

        // Octobre : A dépasse 10 CHF avec ce qui était reporté, versé en une fois ; B attend
        $this->artisan('videos:plus-pool', ['month' => '2026-10'])->assertSuccessful();
        $owedA = (int) PlusPoolShare::query()->where('seller_user_id', $a->seller_user_id)->sum('earned_cents');
        $this->assertGreaterThanOrEqual(1000, $owedA);
        $this->assertSame([['compte' => 'acct_a', 'centimes' => $owedA]], array_map(fn ($v) => ['compte' => $v['compte'], 'centimes' => $v['centimes']], $this->gateway->versements));

        // Décembre : tout ce qui reste est versé, même sous 10 CHF, à qui a un compte prêt
        $this->artisan('videos:plus-pool', ['month' => '2026-12'])->assertSuccessful();
        $paid = collect($this->gateway->versements)->groupBy('compte')->map->sum('centimes');
        $this->assertSame((int) PlusPoolShare::query()->where('seller_user_id', $a->seller_user_id)->sum('earned_cents'), $paid['acct_a']);
        $this->assertSame((int) PlusPoolShare::query()->where('seller_user_id', $b->seller_user_id)->sum('earned_cents'), $paid['acct_b']);
        $this->assertLessThan(1000, $paid['acct_b']);
        // Sans compte de paiement prêt, la part attend qu'il le soit
        $this->assertSame(0, PlusPoolShare::query()->whereNull('paid_at')->where('seller_user_id', '!=', $c->seller_user_id)->count());
        $this->assertSame(1, PlusPoolShare::query()->whereNull('paid_at')->where('seller_user_id', $c->seller_user_id)->count());
    }

    // ————— Certificat de visionnage —————

    public function test_qui_a_regarde_en_entier_obtient_son_certificat_avec_nom_gln_titre_vendeur_duree_et_date(): void
    {
        $sale = $this->onSale(['in_plus' => true, 'audience' => 'both']);
        $vet = $this->proprietaireAvecJeton(['formule' => 'plus', 'verification_veterinaire' => StatutVerificationVeterinaire::Verifie, 'gln' => '7601000000001', 'name' => 'Lina Rochat']);
        // Un GLN pas encore vérifié n'est pas attesté
        $owner = $this->proprietaireAvecJeton(['formule' => 'plus', 'gln' => '7601000000002']);
        $certificate = "/api/v1/espace/videos/{$sale['id']}/certificate";

        $this->play($vet['jeton'], $sale['id'])->assertOk();
        $this->watch($vet['jeton'], $sale['id'], 0, 100)->assertJsonPath('data.completed', false);
        $this->postJson($certificate, [], $this->enTetes($vet['jeton']))->assertUnprocessable()->assertJsonValidationErrors('certificate');
        // 169 secondes sur 180 : 94 %, pas encore en entier
        $this->watch($vet['jeton'], $sale['id'], 100, 169)->assertJsonPath('data.completed', false);
        // 171 secondes sur 180 : 95 %
        $this->watch($vet['jeton'], $sale['id'], 100, 171)->assertJsonPath('data.completed', true);

        $this->postJson($certificate, [], $this->enTetes($vet['jeton']))->assertOk()
            ->assertJsonPath('data.holder_name', 'Lina Rochat')
            ->assertJsonPath('data.holder_gln', '7601000000001')
            ->assertJsonPath('data.video_title', 'Nourrir un chat senior')
            ->assertJsonPath('data.seller_name', Video::query()->sole()->seller_name)
            ->assertJsonPath('data.duration_seconds', 180)
            ->assertJsonPath('data.watched_at', VideoView::query()->where('user_id', $vet['user']->id)->sole()->completed_at->toIso8601String())
            ->assertJsonStructure(['data' => ['code', 'watched_at']]);

        // La vidéo ouverte dit où il en est, et son certificat
        $this->getJson("/api/v1/espace/videos/{$sale['id']}", $this->enTetes($vet['jeton']))->assertOk()
            ->assertJsonPath('data.viewing.completed', true)
            ->assertJsonPath('data.viewing.counted', true)
            ->assertJsonPath('data.viewing.certificate_code', ViewingCertificate::query()->sole()->code);

        // Sans identité vérifiée : pas de GLN
        $this->play($owner['jeton'], $sale['id'])->assertOk();
        $this->watch($owner['jeton'], $sale['id'], 0, 180)->assertJsonPath('data.completed', true);
        $this->postJson($certificate, [], $this->enTetes($owner['jeton']))->assertOk()->assertJsonPath('data.holder_gln', null);
    }

    public function test_chaque_certificat_porte_un_code_que_tout_le_monde_verifie_sur_une_page_publique(): void
    {
        $sale = $this->onSale(['in_plus' => true]);
        $plus = $this->proprietaireAvecJeton(['formule' => 'plus', 'name' => 'Marc Dubois']);
        $this->play($plus['jeton'], $sale['id'])->assertOk();
        $this->watch($plus['jeton'], $sale['id'], 0, 180);
        $code = $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertOk()->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        // Le même certificat, pas un nouveau
        $this->postJson("/api/v1/espace/videos/{$sale['id']}/certificate", [], $this->enTetes($plus['jeton']))->assertJsonPath('data.code', $code);

        // Sans compte, en minuscules comme on le tape
        $this->getJson('/api/v1/public/certificates/'.strtolower($code))->assertOk()
            ->assertJsonPath('data.holder_name', 'Marc Dubois')
            ->assertJsonPath('data.video_title', 'Nourrir un chat senior');
        // Tapé sans tirets, avec des espaces
        $this->getJson('/api/v1/public/certificates/'.rawurlencode(strtolower(str_replace('-', ' ', $code))))->assertOk()->assertJsonPath('data.code', $code);
        $this->getJson('/api/v1/public/certificates/'.str_replace('-', '', $code))->assertOk();
        $this->getJson('/api/v1/public/certificates/AAAA-BBBB-CCCC')->assertNotFound();
    }

    /** @param  array<string, mixed>  $event */
    private function webhook(array $event): TestResponse
    {
        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'signature-valide',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($event));
    }
}
