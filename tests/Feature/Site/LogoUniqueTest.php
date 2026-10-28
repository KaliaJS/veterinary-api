<?php

namespace Tests\Feature\Site;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Models\Facture;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Un seul logo (décision de Jim, 28.10.2026) : celui du site web de
 * l'organisation. La fiche des cliniques et l'en-tête des pièces (factures,
 * ordonnances, devis) le servent ; sans logo de site, rien.
 */
class LogoUniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_les_cliniques_et_les_pieces_servent_le_logo_du_site(): void
    {
        app(ContexteClinique::class)->oublier();
        $site = SiteWebFactory::new()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'logo_chemin' => 'sites/logos/essai.png']);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $logo = $site->logoUrl();

        $this->assertNotNull($logo);
        $this->getJson('/api/v1/organisation/cliniques', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.0.logo_url', $logo);
        $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.en_tete.logo_url', $logo);
        $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.clinique.en_tete.logo_url', $logo);
    }

    public function test_sans_logo_de_site_aucun_logo(): void
    {
        $this->getJson('/api/v1/organisation/cliniques', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.0.logo_url', null);
    }

    /** Une liste de factures ne relit pas le site une fois par facture. */
    public function test_une_liste_de_pieces_lit_le_logo_une_fois(): void
    {
        app(ContexteClinique::class)->oublier();
        SiteWebFactory::new()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'logo_chemin' => 'sites/logos/essai.png']);
        Facture::factory()->count(5)->create(['clinique_id' => $this->clinique->id]);

        DB::enableQueryLog();
        $this->getJson('/api/v1/facturation/factures', $this->enTetes($this->jeton))->assertOk();
        $lectures = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'sites_web'))->count();

        $this->assertLessThanOrEqual(1, $lectures);
    }
}
