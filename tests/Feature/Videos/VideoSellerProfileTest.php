<?php

namespace Tests\Feature\Videos;

use App\Enums\Specialite;
use App\Enums\StatutVerificationVeterinaire;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use App\Models\Video;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Qui présente une vidéo, dans sa fenêtre « Plus d'infos » (31.10.2026,
 * demande de Jim) : la photo, les spécialités et la présentation d'un
 * vétérinaire ; le logo, la photo, la ville et la présentation d'une
 * clinique. Seulement ce qui est déjà public : jamais la photo d'un
 * vétérinaire qui n'a pas accepté l'annuaire, jamais le texte d'un site
 * pas publié.
 */
class VideoSellerProfileTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_veterinaire_d_accord_pour_l_annuaire_est_presente_avec_sa_photo_ses_specialites_et_sa_presentation(): void
    {
        $vet = $this->vet(['annuaire_accord_le' => now(), 'presentation' => 'Vétérinaire à Nyon depuis dix ans.']);
        $vet->specialites()->create(['specialite' => Specialite::Comportement]);
        Employe::factory()->create(['user_id' => $vet->id, 'photo_chemin' => 'photos-employes/abc123.webp', 'accord_site_le' => now()]);
        $video = $this->onSale(['seller_user_id' => $vet->id, 'seller_name' => 'Dr Rachel Solal']);

        $seller = $this->getJson("/api/v1/public/videos/{$video->uuid}")->assertOk()->json('data.seller');

        $this->assertSame('Dr Rachel Solal', $seller['name']);
        $this->assertSame('vet', $seller['kind']);
        $this->assertTrue($seller['is_verified']);
        $this->assertStringContainsString('abc123.webp', (string) $seller['photo_url']);
        $this->assertSame(['Comportement'], $seller['specialities']);
        $this->assertSame('Vétérinaire à Nyon depuis dix ans.', $seller['about']);
        $this->assertNull($seller['logo_url']);
    }

    public function test_sans_son_accord_pour_l_annuaire_un_veterinaire_n_a_que_son_nom(): void
    {
        $vet = $this->vet(['annuaire_accord_le' => null, 'presentation' => 'Texte privé.']);
        $vet->specialites()->create(['specialite' => Specialite::Comportement]);
        Employe::factory()->create(['user_id' => $vet->id, 'photo_chemin' => 'photos-employes/abc123.webp', 'accord_site_le' => now()]);
        $video = $this->onSale(['seller_user_id' => $vet->id, 'seller_name' => 'Dr Rachel Solal']);
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $seller = $this->getJson("/api/v1/espace/videos/{$video->uuid}", $this->enTetes($jeton))->assertOk()->json('data.seller');

        $this->assertSame(['name' => 'Dr Rachel Solal', 'kind' => 'vet', 'is_verified' => true, 'photo_url' => null, 'logo_url' => null, 'place' => null, 'specialities' => [], 'about' => null, 'site_url' => null], $seller);
    }

    public function test_une_photo_que_le_veterinaire_n_a_pas_acceptee_sur_le_site_ne_se_montre_pas(): void
    {
        $vet = $this->vet(['annuaire_accord_le' => now()]);
        Employe::factory()->create(['user_id' => $vet->id, 'photo_chemin' => 'photos-employes/abc123.webp', 'accord_site_le' => null]);
        $video = $this->onSale(['seller_user_id' => $vet->id]);

        $this->assertNull($this->getJson("/api/v1/public/videos/{$video->uuid}")->json('data.seller.photo_url'));
    }

    public function test_une_clinique_est_presentee_avec_son_logo_sa_photo_sa_ville_et_la_presentation_de_son_site_publie(): void
    {
        $clinique = Clinique::factory()->create(['ville' => 'Nyon', 'photo_chemin' => 'photos-cliniques/xyz789.webp']);
        $organisation = $clinique->societe->organisation_id;
        $site = SiteWebFactory::new()->publie()->create(['organisation_id' => $organisation, 'slug' => 'deux-rives', 'logo_chemin' => 'logos/deux-rives.png', 'presentation' => 'Une clinique au bord du lac.']);
        $video = $this->onSale(['seller_user_id' => null, 'seller_clinique_id' => $clinique->id, 'organisation_id' => $organisation, 'seller_name' => 'Clinique des Deux Rives', 'created_by_user_id' => User::factory()->create()->id]);

        $seller = $this->getJson("/api/v1/public/videos/{$video->uuid}")->assertOk()->json('data.seller');

        $this->assertSame('clinic', $seller['kind']);
        $this->assertSame('Clinique des Deux Rives', $seller['name']);
        $this->assertSame('Nyon', $seller['place']);
        $this->assertSame($site->logoUrl(), $seller['logo_url']);
        $this->assertStringContainsString('xyz789', (string) $seller['photo_url']);
        $this->assertSame('Une clinique au bord du lac.', $seller['about']);
        $this->assertSame($site->urlPublique(), $seller['site_url']);

        // Site pas publié : le logo reste (l'annuaire le montre aussi), ni sa présentation ni son adresse
        $site->forceFill(['est_publie' => false])->save();
        $seller = $this->getJson("/api/v1/public/videos/{$video->uuid}")->json('data.seller');
        $this->assertSame($site->logoUrl(), $seller['logo_url']);
        $this->assertNull($seller['about']);
        $this->assertNull($seller['site_url']);
    }

    public function test_les_listes_ne_portent_que_le_nom_du_vendeur(): void
    {
        $vet = $this->vet(['annuaire_accord_le' => now(), 'presentation' => 'Texte.']);
        $video = $this->onSale(['seller_user_id' => $vet->id, 'seller_name' => 'Dr Rachel Solal', 'category' => 'cats']);

        $listed = $this->getJson('/api/v1/public/videos')->assertOk()->json('data.0.seller');
        $this->assertSame(['name' => 'Dr Rachel Solal', 'kind' => 'vet'], $listed);
        $this->assertSame($video->uuid, $this->getJson('/api/v1/public/videos')->json('data.0.id'));
    }

    // ————— Outils —————

    private function vet(array $fields): User
    {
        return User::factory()->create(['verification_veterinaire' => StatutVerificationVeterinaire::Verifie, ...$fields]);
    }

    /** Une vidéo en vente, posée directement (sa mise en vente a ses propres tests). */
    private function onSale(array $fields): Video
    {
        return Video::factory()->create([
            'status' => 'published', 'files_status' => 'ready', 'audience' => 'owners', 'price_cents' => 1500, 'category' => 'cats',
            'duration_seconds' => 30, 'published_at' => now(), ...$fields,
        ]);
    }
}
