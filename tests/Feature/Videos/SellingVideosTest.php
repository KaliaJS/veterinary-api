<?php

namespace Tests\Feature\Videos;

use App\Enums\StatutVerificationVeterinaire;
use App\Models\Clinique;
use App\Models\Video;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsHlsPackages;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PublishesVideos;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Vidéos de formation, la vente (décisions de Jim, 30 et 31.10.2026) :
 * vendeurs vérifiés, brouillons, envoi direct au bucket par adresses
 * signées, contrôle du paquet HLS, quotas, mise en vente, catalogue.
 */
class SellingVideosTest extends TestCase
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

    // ————— Vendeurs —————

    public function test_un_veterinaire_verifie_vend_en_son_nom_et_personne_d_autre(): void
    {
        ['jeton' => $owner] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/espace/videos/seller/videos', ['title' => 'Essai'], $this->enTetes($owner))
            ->assertUnprocessable()->assertJsonValidationErrors(['vendeur' => 'Vendre une vidéo demande un compte de vétérinaire vérifié : vérifiez votre identité dans Réglages, Profil.']);

        $vet = $this->verifiedVet();
        $this->postJson('/api/v1/espace/videos/seller/videos', ['title' => 'Essai'], $this->enTetes($vet['jeton']))
            ->assertCreated()->assertJsonPath('data.seller.kind', 'vet')->assertJsonPath('data.status', 'draft');

        // Plus vérifié : il ne publie plus
        $video = $this->published(vet: $vet);
        $vet['user']->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Refuse])->save();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$video['id']}/withdraw", [], $this->enTetes($vet['jeton']))->assertUnprocessable();
    }

    public function test_une_clinique_vend_quand_la_personne_qui_publie_est_verifiee_et_gere_l_organisation(): void
    {
        $clinique = Clinique::factory()->create();
        ['user' => $admin, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $this->postJson('/api/v1/videos', ['title' => 'Essai'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('vendeur');

        $admin->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $id = $this->postJson('/api/v1/videos', ['title' => 'Essai'], $this->enTetes($jeton))
            ->assertCreated()->assertJsonPath('data.seller.kind', 'clinic')->assertJsonPath('data.seller.name', $clinique->nom)->json('data.id');
        $this->assertSame($clinique->societe->organisation_id, Video::query()->where('uuid', $id)->value('organisation_id'));

        // Sans le droit organisation : rien
        ['user' => $employe, 'jeton' => $autre] = $this->employeAvecJeton($clinique);
        $employe->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $this->postJson('/api/v1/videos', ['title' => 'Essai'], $this->enTetes($autre))->assertForbidden();
    }

    public function test_le_veterinaire_ouvre_son_compte_de_paiement_dans_veterinary(): void
    {
        $vet = $this->verifiedVet(readyAccount: false);

        $this->getJson('/api/v1/espace/videos/seller/account', $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.exists', false);
        $this->postJson('/api/v1/espace/videos/seller/account', [], $this->enTetes($vet['jeton']))
            ->assertOk()->assertJsonPath('data.ready', true)->assertJsonStructure(['data' => ['client_secret', 'publishable_key']]);

        $this->assertNotNull($vet['user']->refresh()->stripe_account_id);
        $this->assertSame('individual', collect($this->gateway->appels)->isNotEmpty() ? $this->comptesCreesType() : null);
        // Une seconde ouverture ne crée pas un second compte
        $compte = $vet['user']->stripe_account_id;
        $this->postJson('/api/v1/espace/videos/seller/account', [], $this->enTetes($vet['jeton']))->assertOk();
        $this->assertSame($compte, $vet['user']->refresh()->stripe_account_id);

        ['jeton' => $owner] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/espace/videos/seller/account', [], $this->enTetes($owner))->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    private function comptesCreesType(): ?string
    {
        $comptes = (new \ReflectionProperty($this->gateway, 'comptesCrees'))->getValue($this->gateway);

        return $comptes[0]['type'] ?? null;
    }

    // ————— Mise en ligne —————

    public function test_un_brouillon_n_est_visible_que_de_son_vendeur(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        ['jeton' => $other] = $this->proprietaireAvecJeton();

        $this->getJson("/api/v1/espace/videos/{$id}", $this->enTetes($other))->assertNotFound();
        $this->getJson('/api/v1/espace/videos', $this->enTetes($other))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/espace/videos/{$id}", $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.access', 'seller');
        // Un autre vétérinaire ne le touche pas
        $autre = $this->verifiedVet();
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", ['title' => 'Volé'], $this->enTetes($autre['jeton']))->assertNotFound();
    }

    public function test_chaque_adresse_d_envoi_ne_permet_que_d_ecrire_son_fichier_pendant_15_minutes(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))->assertOk();
        $urls = collect($this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => ['master.m3u8', 'segment-1-00001.m4s']], $this->enTetes($vet['jeton']))->assertOk()->json('data'))->keyBy('name');

        // Son fichier seulement : changer le nom casse la signature
        $this->call('PUT', str_replace('segment-1-00001.m4s', 'segment-1-00002.m4s', $urls['segment-1-00001.m4s']['url']), [], [], [], [], 'x')->assertForbidden();
        // Une adresse de lecture ne permet pas d'écrire
        $this->call('PUT', str_replace('upload=1', 'upload=0', $urls['master.m3u8']['url']), [], [], [], [], 'x')->assertForbidden();
        $this->call('PUT', $urls['master.m3u8']['url'], [], [], [], [], '#EXTM3U')->assertNoContent();
        Storage::disk('prive')->assertExists("videos/{$id}/master.m3u8");

        // Ni chemin ni fichier étranger à une vidéo
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => ['../photos/a.mp4']], $this->enTetes($vet['jeton']))->assertUnprocessable();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => ['script.php']], $this->enTetes($vet['jeton']))->assertUnprocessable();

        // 15 minutes, pas plus
        $this->travel(16)->minutes();
        $this->call('PUT', $urls['segment-1-00001.m4s']['url'], [], [], [], [], 'x')->assertForbidden();
    }

    public function test_un_envoi_interrompu_reprend_la_ou_il_s_est_arrete(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $files = $this->hlsPackage();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $this->enTetes($vet['jeton']))->assertOk();
        $premiers = array_slice($files, 0, 4, true);
        foreach ($this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => array_keys($premiers)], $this->enTetes($vet['jeton']))->json('data') as $url) {
            $this->call('PUT', $url['url'], [], [], [], [], $files[$url['name']]);
        }

        $deja = $this->getJson("/api/v1/espace/videos/seller/videos/{$id}/upload/files", $this->enTetes($vet['jeton']))->assertOk()->json('data');
        $this->assertSame(array_keys($premiers), array_keys($deja));

        $reste = array_diff_key($files, $deja);
        foreach ($this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => array_keys($reste)], $this->enTetes($vet['jeton']))->json('data') as $url) {
            $this->call('PUT', $url['url'], [], [], [], [], $files[$url['name']]);
        }
        $this->finish($vet['jeton'], $id)->assertOk()->assertJsonPath('data.files_status', 'ready');
    }

    public function test_le_serveur_refuse_et_efface_un_paquet_qui_n_est_pas_du_h264_1080p_de_3_heures_au_plus(): void
    {
        // Des centaines de fichiers en quelques secondes : le débit de la route locale n'est pas l'objet ici
        $this->withoutMiddleware(ThrottleRequests::class);
        $vet = $this->verifiedVet();
        $cas = [
            'Une vidéo est en 1080p au plus.' => $this->hlsPackage(sizes: [[3840, 2160], [1280, 720]]),
            'La vidéo doit être en H.264 : renvoyez-la depuis Veterinary.' => $this->hlsPackage(videoCodec: 'hvc1'),
            'Une vidéo dure 3 heures au plus.' => $this->hlsPackage(seconds: 3 * 3600 + 30, segment: 60),
            'Il manque la version allégée en 720p.' => $this->hlsPackage(sizes: [[1920, 1080]]),
        ];
        foreach ($cas as $raison => $files) {
            $id = $this->draft($vet['jeton']);
            $this->upload($vet['jeton'], $id, $files);
            $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors(['files' => $raison]);
            $this->assertSame([], Storage::disk('prive')->allFiles("videos/{$id}"), $raison);
            $this->assertSame('rejected', Video::query()->where('uuid', $id)->firstOrFail()->files_status->value);
        }

        // Un morceau manquant, un son à part : refusés aussi
        $id = $this->draft($vet['jeton']);
        $files = $this->hlsPackage();
        unset($files['segment-2-00003.m4s']);
        $this->upload($vet['jeton'], $id, $files);
        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors(['files' => 'Des morceaux de la vidéo manquent : reprenez l’envoi.']);

        $id = $this->draft($vet['jeton']);
        $files = $this->hlsPackage();
        $files['master.m3u8'] = str_replace("#EXT-X-INDEPENDENT-SEGMENTS\n", "#EXT-X-INDEPENDENT-SEGMENTS\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"a\",NAME=\"fr\",URI=\"audio.m3u8\"\n", $files['master.m3u8']);
        $this->upload($vet['jeton'], $id, $files);
        $this->finish($vet['jeton'], $id)->assertUnprocessable()->assertJsonValidationErrors(['files' => 'Le son doit être mêlé à chaque qualité : renvoyez la vidéo depuis Veterinary.']);
    }

    public function test_un_paquet_conforme_est_pret_et_ce_qui_n_est_cite_nulle_part_est_efface(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage() + ['segment-9-00001.m4s' => 'intrus']);

        $this->finish($vet['jeton'], $id)->assertOk()
            ->assertJsonPath('data.files_status', 'ready')->assertJsonPath('data.duration_seconds', 30)
            ->assertJsonPath('data.width', 1920)->assertJsonPath('data.height', 1080);
        Storage::disk('prive')->assertMissing("videos/{$id}/segment-9-00001.m4s");
        $this->assertGreaterThan(0, Video::query()->where('uuid', $id)->value('size_bytes'));
    }

    public function test_le_serveur_refuse_d_avance_une_duree_de_plus_de_3_heures(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);

        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 3 * 3600 + 1, 'estimated_bytes' => 10], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['duration_seconds' => 'Une vidéo dure 3 heures au plus.']);
    }

    public function test_un_long_envoi_qui_demande_encore_des_adresses_n_est_pas_abandonne(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 100], $this->enTetes($vet['jeton']))->assertOk();
        $this->travel(23)->hours();
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => ['init-1.mp4']], $this->enTetes($vet['jeton']))->assertOk();
        $this->travel(2)->hours();

        $this->artisan('videos:purge-abandoned-uploads')->assertSuccessful();
        $this->assertSame(1, Video::query()->where('uuid', $id)->count());
        $this->assertTrue(Video::query()->where('uuid', $id)->firstOrFail()->updated_at->gt(now()->subDay()));
    }

    public function test_les_envois_abandonnes_sont_effaces_automatiquement(): void
    {
        $vet = $this->verifiedVet();
        $abandonne = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $abandonne, array_slice($this->hlsPackage(), 0, 3, true));
        $this->travel(25)->hours();
        $recent = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $recent, array_slice($this->hlsPackage(), 0, 3, true));

        $this->artisan('videos:purge-abandoned-uploads')->assertSuccessful();

        $this->assertSame([], Storage::disk('prive')->allFiles("videos/{$abandonne}"));
        $this->assertNotSame([], Storage::disk('prive')->allFiles("videos/{$recent}"));
        $this->assertSame(1, Video::query()->where('uuid', $abandonne)->count(), 'Le brouillon reste.');
    }

    public function test_l_ecran_du_vendeur_sait_s_il_peut_vendre_et_la_place_qui_lui_reste(): void
    {
        ['jeton' => $owner] = $this->proprietaireAvecJeton();
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($owner))->assertOk()
            ->assertJsonPath('meta.verified', false);

        $sale = $this->published();
        $size = (int) Video::query()->sole()->size_bytes;
        $this->getJson('/api/v1/espace/videos/seller/videos', $this->enTetes($sale['vet']['jeton']))->assertOk()
            ->assertJsonPath('meta.verified', true)
            ->assertJsonPath('meta.storage.used', $size)
            ->assertJsonPath('meta.storage.limit', 100 * 1000 ** 3);
    }

    public function test_un_envoi_qui_depasserait_le_stockage_est_refuse_avant_de_commencer(): void
    {
        // Un vétérinaire : 100 Go à lui
        $vet = $this->verifiedVet();
        Video::factory()->create(['seller_user_id' => $vet['user']->id, 'seller_name' => 'Dr Keller', 'created_by_user_id' => $vet['user']->id, 'size_bytes' => 100 * 1000 ** 3 - 1000]);
        $id = $this->draft($vet['jeton']);
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 5000], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['estimated_bytes' => 'Votre stockage est plein : cette vidéo ne tient pas dans ce qui reste.']);
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 500], $this->enTetes($vet['jeton']))->assertOk();

        // Une clinique : les 1000 Go de l'organisation
        $clinique = Clinique::factory()->create();
        ['user' => $admin, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $admin->forceFill(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie])->save();
        $clinique->societe->organisation->forceFill(['stockage_autres_octets' => 1000 * 1000 ** 3])->save();
        $id = $this->draft($jeton, '/api/v1/videos');
        $this->postJson("/api/v1/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 1], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_bytes');
    }

    // ————— Mise en vente —————

    public function test_mettre_en_vente_demande_titre_description_prix_de_2_chf_au_moins_et_public(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $this->upload($vet['jeton'], $id, $this->hlsPackage());
        $this->finish($vet['jeton'], $id)->assertOk();
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", ['title' => null], $this->enTetes($vet['jeton']))->assertOk();

        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/publish", [], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'price_cents', 'audience']);
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", ['price_cents' => 199], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['price_cents' => 'Une vidéo coûte au moins 2 CHF.']);
        // Sans maximum (autre que technique)
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", ['price_cents' => 250_000_00], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_clients_veut_dire_tous_les_proprietaires_et_les_videos_pour_veterinaires_restent_aux_veterinaires(): void
    {
        $pourClients = $this->published(['audience' => 'owners']);
        $pourVetos = $this->published(['audience' => 'vets']);
        ['jeton' => $inconnu] = $this->proprietaireAvecJeton();
        $veto = $this->verifiedVet();

        $vus = collect($this->getJson('/api/v1/espace/videos', $this->enTetes($inconnu))->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$pourClients['id']], $vus->all());
        $this->getJson("/api/v1/espace/videos/{$pourVetos['id']}", $this->enTetes($inconnu))->assertNotFound();

        $vus = collect($this->getJson('/api/v1/espace/videos', $this->enTetes($veto['jeton']))->json('data'))->pluck('id')->sort()->values();
        $this->assertEqualsCanonicalizing([$pourClients['id'], $pourVetos['id']], $vus->all());
    }

    public function test_plus_n_est_propose_que_si_les_clients_font_partie_du_public(): void
    {
        $vet = $this->verifiedVet();
        $id = $this->draft($vet['jeton']);
        $url = "/api/v1/espace/videos/seller/videos/{$id}";

        $this->patchJson($url, ['audience' => 'vets', 'in_plus' => true], $this->enTetes($vet['jeton']))
            ->assertUnprocessable()->assertJsonValidationErrors(['in_plus' => 'Plus n’inclut que des vidéos destinées aux clients.']);
        $this->patchJson($url, ['audience' => 'both', 'in_plus' => true], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.in_plus', true);
        $this->patchJson($url, ['audience' => 'vets'], $this->enTetes($vet['jeton']))->assertOk()->assertJsonPath('data.in_plus', false);
    }

    public function test_la_publication_est_immediate(): void
    {
        $video = $this->published();
        ['jeton' => $owner] = $this->proprietaireAvecJeton();

        $this->getJson("/api/v1/espace/videos/{$video['id']}", $this->enTetes($owner))->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.access', 'preview');
    }

    public function test_dans_l_app_iphone_on_envoie_mais_on_ne_met_pas_en_vente(): void
    {
        $vet = $this->verifiedVet();
        $iphone = $this->enTetes($vet['jeton']) + ['X-Veterinary-Platform' => 'ios'];
        $id = $this->postJson('/api/v1/espace/videos/seller/videos', ['title' => 'Depuis l’iPhone'], $iphone)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/start", ['duration_seconds' => 30, 'estimated_bytes' => 10_000], $iphone)->assertOk();
        $urls = $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/urls", ['files' => array_keys($this->hlsPackage())], $iphone)->assertOk()->json('data');
        foreach ($urls as $url) {
            $this->call('PUT', $url['url'], [], [], [], [], $this->hlsPackage()[$url['name']]);
        }
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/upload/finish", [], $iphone)->assertOk();
        $this->patchJson("/api/v1/espace/videos/seller/videos/{$id}", ['description' => 'x', 'price_cents' => 900, 'audience' => 'owners'], $iphone)->assertOk();

        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/publish", [], $iphone)
            ->assertUnprocessable()->assertJsonValidationErrors(['plateforme' => 'La mise en vente se fait sur le site de Veterinary, pas dans l’app iPhone.']);
        $this->postJson("/api/v1/espace/videos/seller/videos/{$id}/publish", [], $this->enTetes($vet['jeton']))->assertOk();
    }

    public function test_une_video_retiree_quitte_le_catalogue_et_ne_s_efface_pas(): void
    {
        $video = $this->published();
        $jeton = $video['vet']['jeton'];
        ['jeton' => $owner] = $this->proprietaireAvecJeton();

        $this->postJson("/api/v1/espace/videos/seller/videos/{$video['id']}/withdraw", [], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->getJson('/api/v1/espace/videos', $this->enTetes($owner))->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/espace/videos/seller/videos/{$video['id']}", [], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
