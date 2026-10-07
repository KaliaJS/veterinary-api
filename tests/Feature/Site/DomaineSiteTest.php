<?php

namespace Tests\Feature\Site;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use App\Services\Domaines\ResolveurDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Domaine personnalisé d'un site (étape 1) : saisie normalisée,
 * enregistrement DNS à créer, attente puis actif dès que le domaine
 * pointe vers nous, routes publiques (site d'un domaine, autorisation
 * de certificat), retrait, revérification planifiée, Cloudflare for SaaS.
 */
class DomaineSiteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private string $jeton;

    private bool $pointe = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sites.domaines' => [
            'fournisseur' => 'manuel',
            'cible_cname' => 'sites.veterinary.ch',
            'ip' => null,
            'test_auto' => false,
            'reserves' => ['app.veterinary.ch', 'sites.veterinary.ch'],
            'cloudflare' => ['jeton' => 'cf-test', 'zone' => 'zone123'],
        ]]);

        $this->app->instance(ResolveurDns::class, new class($this) extends ResolveurDns
        {
            public function __construct(private DomaineSiteTest $test) {}

            public function pointeVers(string $domaine, string $cibleCname, ?string $ip): bool
            {
                return $this->test->dnsPointe();
            }
        });

        $clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $clinique->societe->organisation->update(['nom' => 'Deux Rives']);
        $this->jeton = $this->administrateur($clinique);
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($this->jeton))->assertOk();
    }

    public function dnsPointe(): bool
    {
        return $this->pointe;
    }

    private function administrateur(Clinique $clinique): string
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $clinique->societe->organisation_id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)->value('id')]);

        return $jeton;
    }

    public function test_le_domaine_est_normalise_et_attend_le_dns_puis_devient_actif(): void
    {
        $this->putJson('/api/v1/site/domaine', ['domaine' => '  https://WWW.Ma-Clinique.ch/accueil?x=1 '], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine.nom', 'www.ma-clinique.ch')
            ->assertJsonPath('data.domaine.statut', 'attente')
            ->assertJsonPath('data.domaine.enregistrement', ['type' => 'CNAME', 'nom' => 'www', 'valeur' => 'sites.veterinary.ch']);

        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertNotFound();
        $this->get('/api/v1/public/domaines-autorises?domain=www.ma-clinique.ch')->assertNoContent();

        $this->pointe = true;
        $this->postJson('/api/v1/site/domaine/verifier', [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine.statut', 'actif');

        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')
            ->assertOk()
            ->assertJsonPath('data.slug', 'deux-rives');
    }

    public function test_un_domaine_nu_recoit_un_enregistrement_a_quand_une_ip_est_configuree(): void
    {
        config(['sites.domaines.ip' => '203.0.113.10']);

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine.est_nu', true)
            ->assertJsonPath('data.domaine.enregistrement', ['type' => 'A', 'nom' => '@', 'valeur' => '203.0.113.10']);
    }

    public function test_les_domaines_invalides_reserves_ou_deja_pris_sont_refuses(): void
    {
        foreach (['', 'pas un domaine', 'localhost', 'sites.veterinary.ch'] as $domaine) {
            $this->putJson('/api/v1/site/domaine', ['domaine' => $domaine], $this->enTetes($this->jeton))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('domaine');
        }

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.prise.ch'], $this->enTetes($this->jeton))->assertOk();

        app(ContexteClinique::class)->oublier();
        $autre = $this->administrateur(Clinique::factory()->create(['nom' => 'Le Lac']));
        $this->getJson('/api/v1/site', $this->enTetes($autre));
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.prise.ch'], $this->enTetes($autre))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domaine');
    }

    public function test_le_retrait_libere_le_domaine_et_la_commande_revérifie_les_domaines_en_attente(): void
    {
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertOk();

        $this->pointe = true;
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertOk();

        $this->deleteJson('/api/v1/site/domaine', [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine', null);

        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertNotFound();
        $this->get('/api/v1/public/domaines-autorises?domain=www.ma-clinique.ch')->assertNotFound();
    }

    public function test_avec_cloudflare_le_domaine_est_declare_puis_actif_quand_le_certificat_est_emis(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames' => Http::response(['success' => true, 'result' => ['id' => 'ch-42', 'status' => 'pending']]),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-42' => Http::sequence()
                ->push(['result' => ['status' => 'pending', 'ssl' => ['status' => 'pending_validation'], 'verification_errors' => []]])
                ->push(['result' => ['status' => 'active', 'ssl' => ['status' => 'active']]]),
        ]);

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine.statut', 'attente');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer cf-test')
            && $r['hostname'] === 'www.ma-clinique.ch'
            && $r['ssl'] === ['method' => 'http', 'type' => 'dv']);

        $this->postJson('/api/v1/site/domaine/verifier', [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.domaine.statut', 'actif');
    }

    public function test_un_refus_de_cloudflare_remonte_sur_le_champ(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Duplicate custom hostname found.']]], 409)]);

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.domaine.0', 'Duplicate custom hostname found.');
    }

    public function test_un_veterinaire_ne_peut_pas_toucher_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::withoutGlobalScopes()->first());

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_le_domaine_relie_devient_une_origine_cors_autorisee(): void
    {
        config(['cors.allowed_origins' => ['https://app.veterinary.ch', 'https://app.veterinary.test']]);
        $origine = fn (string $o): ?string => $this->getJson('/api/v1/public/sites/deux-rives', ['Origin' => $o])->headers->get('Access-Control-Allow-Origin');

        $this->assertNull($origine('https://www.ma-clinique.ch'));

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame('https://www.ma-clinique.ch', $origine('https://www.ma-clinique.ch'));
        $this->assertNull($origine('https://inconnu.ch'));
    }
}
