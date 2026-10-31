<?php

namespace Tests\Feature\Videos;

use App\Models\Video;
use App\Models\VideoView;
use App\Paiements\PasserellePaiement;
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
 * Les vidéos gratuites (31.10.2026, demande de Jim : « des vidéos qu'on met
 * en Gratuit, libres pour les gens qui ne paient pas ») : le vendeur coche
 * « Gratuite » au lieu d'un prix ; tout le monde la regarde en entier, sans
 * achat ni Plus ; ses vues ne comptent pas pour la cagnotte ; le certificat
 * se délivre ; le téléchargement reste aux achats.
 */
class VideoFreeTest extends TestCase
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
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_une_video_se_met_en_vente_gratuite_ou_a_deux_francs_au_moins(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->patchJson(self::SELLER."/{$id}", ['price_cents' => 150], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['price_cents' => 'Une vidéo coûte au moins 2 CHF, ou elle est gratuite.']);
        $this->patchJson(self::SELLER."/{$id}", ['price_cents' => -1], $this->enTetes($vet['jeton']))->assertUnprocessable();

        $free = $this->published(['price_cents' => 0], $vet);

        $this->assertSame(0, Video::query()->where('uuid', $free['id'])->value('price_cents'));
        $this->assertSame('published', Video::query()->where('uuid', $free['id'])->first()->status->value);
    }

    public function test_une_video_gratuite_n_est_jamais_dans_plus(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->patchJson(self::SELLER."/{$id}", ['price_cents' => 0, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['in_plus' => 'Une vidéo gratuite se regarde déjà par tous : elle n’entre pas dans Plus.']);
        $this->patchJson(self::SELLER."/{$id}", ['price_cents' => 900, 'audience' => 'owners', 'in_plus' => true], $this->enTetes($vet['jeton']))->assertOk();
        // Devenue gratuite : elle sort de Plus
        $this->patchJson(self::SELLER."/{$id}", ['price_cents' => 0], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.in_plus', false);
    }

    public function test_tout_le_monde_regarde_une_video_gratuite_en_entier_sans_l_acheter(): void
    {
        $free = $this->published(['price_cents' => 0]);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->getJson("/api/v1/espace/videos/{$free['id']}", $this->enTetes($jeton))->assertOk()->assertJsonPath('data.access', 'free');
        $this->postJson("/api/v1/espace/videos/{$free['id']}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
        $this->postJson("/api/v1/espace/videos/{$free['id']}/purchase", ['accept_terms' => true], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['purchase' => 'Cette vidéo est gratuite : regardez-la en entier, sans l’acheter.']);
    }

    public function test_ses_vues_ne_comptent_pas_pour_la_cagnotte_le_certificat_se_delivre_le_telechargement_reste_aux_achats(): void
    {
        $free = $this->published(['price_cents' => 0], seconds: 90);
        ['user' => $subscriber, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $subscriber->forceFill(['formule' => 'plus'])->save();
        $this->postJson("/api/v1/espace/videos/{$free['id']}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.access', 'free');
        $this->travel(3)->minutes();
        $this->postJson("/api/v1/espace/videos/{$free['id']}/progress", ['ranges' => [[0, 90]]], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.counted', true)->assertJsonPath('data.completed', true);

        $this->assertFalse((bool) VideoView::query()->sole()->counted_via_plus);
        $this->postJson("/api/v1/espace/videos/{$free['id']}/certificate", [], $this->enTetes($jeton))->assertOk();
        $this->getJson("/api/v1/espace/videos/{$free['id']}/download", $this->enTetes($jeton))->assertNotFound();
    }

    public function test_l_accueil_a_une_rangee_des_videos_gratuites_selon_le_public(): void
    {
        $vet = $this->verifiedVet();
        $free = $this->published(['price_cents' => 0, 'title' => 'Gratuite'], $vet)['id'];
        $this->published(['price_cents' => 0, 'title' => 'Gratuite pour vétos', 'audience' => 'vets'], $vet);
        $this->published(['price_cents' => 900, 'title' => 'Payante'], $vet);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $rows = collect($this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.rows'))->keyBy('key');

        $this->assertSame('Gratuites', $rows['free']['title']);
        $this->assertSame([$free], array_column($rows['free']['videos'], 'id'));
        $this->assertSame(['new', 'free', 'category'], $rows->keys()->all());
    }

    public function test_les_gratuites_forment_une_categorie_a_part_avec_leur_nombre(): void
    {
        $vet = $this->verifiedVet();
        $free = $this->published(['price_cents' => 0, 'title' => 'Gratuite', 'category' => 'cats'], $vet)['id'];
        $this->published(['price_cents' => 0, 'title' => 'Gratuite pour vétos', 'audience' => 'vets'], $vet);
        $this->published(['price_cents' => 900, 'title' => 'Payante', 'category' => 'cats'], $vet);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->assertSame([$free], array_column($this->getJson('/api/v1/espace/videos?free=1', $this->enTetes($jeton))->assertOk()->json('data'), 'id'));
        $this->assertSame([$free], array_column($this->getJson('/api/v1/public/videos?free=1')->assertOk()->json('data'), 'id'));
        $this->assertCount(2, $this->getJson('/api/v1/espace/videos?free=0', $this->enTetes($jeton))->json('data'));
        $this->assertSame(1, $this->getJson('/api/v1/espace/videos/home', $this->enTetes($jeton))->json('data.free_total'));
        $this->assertSame(1, $this->getJson('/api/v1/public/videos/home')->json('data.free_total'));
        $this->getJson('/api/v1/espace/videos?free=peut-etre', $this->enTetes($jeton))->assertUnprocessable();
    }

    public function test_une_serie_gratuite_donne_ses_episodes_a_tous(): void
    {
        $vet = $this->verifiedVet();
        $series = $this->postJson(self::SELLER, ['kind' => 'series', 'title' => 'Série offerte'], $this->enTetes($vet['jeton']))->json('data.id');
        $this->fillSheet(self::SELLER."/{$series}", ['description' => 'Offerte.', 'price_cents' => 0, 'audience' => 'owners'], $this->enTetes($vet['jeton']));
        $episode = $this->postJson(self::SELLER, ['series' => $series, 'title' => 'Un'], $this->enTetes($vet['jeton']))->json('data.id');
        $this->upload($vet['jeton'], $episode, $this->hlsPackage());
        $this->finish($vet['jeton'], $episode)->assertOk();
        Video::query()->where('uuid', $episode)->update(['poster_token' => Str::random(40)]);
        $this->postJson(self::SELLER."/{$series}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson("/api/v1/espace/videos/{$episode}/play", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.scope', 'full');
        $this->getJson("/api/v1/espace/videos/{$series}", $this->enTetes($jeton))->assertJsonPath('data.access', 'free');
    }
}
