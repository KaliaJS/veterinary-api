<?php

namespace Tests\Feature\Site;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use App\Models\SiteWeb;
use App\Models\User;
use App\Models\Video;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les vidéos de la clinique sur son site web (11.11.2026, décision de
 * Jim) : une page « Vidéos » à la Netflix, seulement les vidéos que vend
 * l'organisation du site, pour les propriétaires ; un interrupteur de
 * l'éditeur la masque. Acheter et regarder se font sur Veterinary (le
 * site ne fait que présenter).
 */
class SiteVideosTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private SiteWeb $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $this->site = SiteWebFactory::new()->publie()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'slug' => 'deux-rives',
        ]);
    }

    /** Une vidéo publiée : celle de la clinique par défaut */
    private function video(array $attributs = [], ?Clinique $clinique = null): Video
    {
        $clinique ??= $this->clinique;

        return Video::query()->create([
            'uuid' => (string) Str::uuid(),
            'kind' => 'video',
            'seller_user_id' => null,
            'seller_clinique_id' => $clinique->id,
            'organisation_id' => $clinique->societe->organisation_id,
            'seller_name' => $clinique->nom,
            'title' => 'Nourrir un chat senior',
            'description' => 'Ce qu’il faut savoir.',
            'price_cents' => 1500,
            'audience' => 'owners',
            'category' => 'nutrition',
            'status' => 'published',
            'files_status' => 'ready',
            'duration_seconds' => 90,
            'poster_token' => Str::random(40),
            'published_at' => now(),
            ...$attributs,
        ]);
    }

    // Case 1 : la page paraît quand la clinique a une vidéo pour les propriétaires

    public function test_le_site_dit_qu_il_a_des_videos_quand_la_clinique_en_vend_aux_proprietaires(): void
    {
        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_videos', false);

        $this->video();

        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_videos', true);
    }

    // Case 2 : seulement les vidéos de la clinique du site, publiées, pour les propriétaires

    public function test_la_page_montre_seulement_les_videos_de_la_clinique_pour_les_proprietaires(): void
    {
        $chat = $this->video(['title' => 'Le chat', 'category' => 'cats'])->uuid;
        $tous = $this->video(['title' => 'Pour tous', 'category' => 'dogs', 'audience' => 'both'])->uuid;
        $this->video(['title' => 'Pour les vétos', 'category' => 'surgery', 'audience' => 'vets']);
        $this->video(['title' => 'Brouillon', 'status' => 'draft', 'published_at' => null]);
        // Une autre organisation, et la vidéo en son nom d'un vétérinaire : jamais sur ce site
        $this->video(['title' => 'Ailleurs'], Clinique::factory()->create());
        $vet = User::factory()->create();
        $this->video(['title' => 'Personnelle', 'seller_user_id' => $vet->id, 'seller_clinique_id' => null, 'organisation_id' => null, 'seller_name' => $vet->name]);

        $data = $this->getJson('/api/v1/public/sites/deux-rives/videos')->assertOk()->json('data');
        $rows = collect($data['rows'])->keyBy(fn (array $row) => $row['key'].($row['category'] ?? ''));

        $this->assertSame(['new', 'categorycats', 'categorydogs'], $rows->keys()->all());
        $this->assertEqualsCanonicalizing([$chat, $tous], array_column($rows['new']['videos'], 'id'));
        $this->assertSame([$chat], array_column($rows['categorycats']['videos'], 'id'));
        // Ce qu'un visiteur en a : la première minute ; rien à lui
        $this->assertSame('preview', $rows['new']['videos'][0]['access']);
        $this->assertArrayNotHasKey('viewing', $rows['new']['videos'][0]);
    }

    // Case 3 : l'interrupteur de l'éditeur masque la page

    public function test_l_interrupteur_de_l_editeur_masque_la_page_videos(): void
    {
        $this->video();
        $jeton = $this->administrateur();

        $this->getJson('/api/v1/site', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.afficher_videos', true);
        $this->patchJson('/api/v1/site', ['afficher_videos' => false], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.afficher_videos', false);

        $this->getJson('/api/v1/public/sites/deux-rives')->assertOk()->assertJsonPath('data.has_videos', false);
        $this->getJson('/api/v1/public/sites/deux-rives/videos')->assertNotFound();
    }

    // Case 4 : l'aperçu de l'éditeur lit la même page, même avant publication

    public function test_l_editeur_voit_la_page_videos_avant_publication(): void
    {
        $id = $this->video()->uuid;
        $this->site->update(['est_publie' => false]);
        $jeton = $this->administrateur();

        $this->getJson('/api/v1/public/sites/deux-rives/videos')->assertNotFound();
        $data = $this->getJson('/api/v1/site/videos', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertSame([$id], array_column($data['rows'][0]['videos'], 'id'));
        $this->getJson('/api/v1/site', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.has_videos', true);
    }

    private function administrateur(): string
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);

        return $jeton;
    }
}
