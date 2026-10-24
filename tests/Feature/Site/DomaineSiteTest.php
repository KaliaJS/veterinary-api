<?php

namespace Tests\Feature\Site;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use App\Services\Domaines\ResolveurDns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

    /** Le client a posé l'enregistrement TXT demandé */
    private bool $txtPose = true;

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

            public function txt(string $nom): array
            {
                return $this->test->dnsTxt($nom);
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

    /** @return list<string> */
    public function dnsTxt(string $nom): array
    {
        if (! $this->txtPose) {
            return [];
        }
        $site = SiteWeb::withoutGlobalScopes()->whereNotNull('domaine_jeton')->get()
            ->first(fn (SiteWeb $site): bool => $nom === DomaineSite::PREFIXE_TXT.'.'.$site->domaine);

        return $site ? [DomaineSite::valeurTxt($site->domaine_jeton)] : [];
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

    public function test_un_refus_de_cloudflare_remonte_sur_le_champ_en_francais(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Duplicate custom hostname found.']]], 409)]);

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.domaine.0', 'Ce domaine est déjà relié à un autre site.');

        // Un autre refus : jamais le texte de Cloudflare
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1414, 'message' => 'Invalid custom hostname: bad TLD']]], 400)]);
        $message = $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->json('errors.domaine.0');
        $this->assertStringNotContainsString('TLD', $message);

        // Cloudflare injoignable : un message simple, le détail au journal
        Http::fake(['api.cloudflare.com/*' => fn () => throw new ConnectionException('cURL error 28: timeout after 15001 ms')]);
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.domaine.0', 'Le service des domaines ne répond pas. Réessayez dans quelques minutes.');
    }

    public function test_un_veterinaire_ne_peut_pas_toucher_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::withoutGlobalScopes()->first());

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_le_domaine_relie_devient_une_origine_cors_autorisee(): void
    {
        $origine = fn (string $o): ?string => $this->getJson('/api/v1/public/sites/deux-rives', ['Origin' => $o])->headers->get('Access-Control-Allow-Origin');

        $this->assertNull($origine('https://www.ma-clinique.ch'));

        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertOk();

        // En attente (DNS pas encore vérifié) : pas encore une origine
        $this->assertNull($origine('https://www.ma-clinique.ch'));

        Cache::forget('sites.domaines.cors');
        $this->pointe = true;
        $this->postJson('/api/v1/site/domaine/verifier', [], $this->enTetes($this->jeton))
            ->assertJsonPath('data.domaine.statut', 'actif');

        $this->assertSame('https://www.ma-clinique.ch', $origine('https://www.ma-clinique.ch'));
        $this->assertNull($origine('https://inconnu.ch'));
    }

    public function test_seules_les_origines_de_l_app_sont_autorisees(): void
    {
        $origine = fn (string $o): ?string => $this->getJson('/api/v1/public/sites/deux-rives', ['Origin' => $o])->headers->get('Access-Control-Allow-Origin');

        $this->assertSame('https://app.veterinary.test', $origine('https://app.veterinary.test'));
        $this->assertSame('capacitor://localhost', $origine('capacitor://localhost'));
        $this->assertNull($origine('https://site-malveillant.example'));
    }

    public function test_sans_l_enregistrement_txt_le_domaine_reste_en_attente(): void
    {
        $this->txtPose = false;
        $this->pointe = true;

        $info = $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.domaine.statut', 'attente')
            ->assertJsonPath('data.domaine.verification.type', 'TXT')
            ->assertJsonPath('data.domaine.verification.nom', '_veterinary.www')
            ->json('data.domaine');
        $this->assertStringStartsWith('veterinary-verification=', $info['verification']['valeur']);
        $this->assertStringContainsString('TXT', (string) $info['erreur']);
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertNotFound();

        $this->txtPose = true;
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertOk();
    }

    public function test_un_sous_domaine_de_la_plateforme_est_refuse(): void
    {
        foreach (['clinique.veterinary.ch', 'veterinary.ch', 'a.b.veterinary.ch'] as $domaine) {
            $this->putJson('/api/v1/site/domaine', ['domaine' => $domaine], $this->enTetes($this->jeton))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('domaine');
        }
    }

    public function test_un_domaine_actif_qui_ne_pointe_plus_tient_un_jour_puis_se_desactive(): void
    {
        $this->pointe = true;
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertJsonPath('data.domaine.statut', 'actif');
        // Relié depuis des mois
        SiteWeb::withoutGlobalScopes()->update(['domaine_relie_le' => now()->subDays(90)]);

        // Le DNS change : une heure après, toujours servi (panne passagère ?)
        $this->pointe = false;
        $this->travel(2)->hours();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertOk();

        // Un jour sans réponse juste : il ne l'est plus
        $this->travel(1)->days();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertNotFound();

        // Relié depuis des mois : il a 14 jours pour revenir, pas zéro
        $this->travel(10)->days();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->assertSame('www.ma-clinique.ch', SiteWeb::withoutGlobalScopes()->firstOrFail()->domaine);
        $this->pointe = true;
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertOk();
    }

    /** Cloudflare en panne : on ne sait rien, le domaine actif le reste ; la boucle continue pour les autres. */
    public function test_une_panne_de_cloudflare_ne_desactive_pas_le_domaine(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);
        $enPanne = false;
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames' => fn (Request $r) => Http::response(['success' => true, 'result' => ['id' => $r['hostname'] === 'www.ma-clinique.ch' ? 'ch-1' : 'ch-2']]),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-1' => function () use (&$enPanne) {
                return $enPanne ? Http::response('Bad gateway', 502) : Http::response(['result' => ['status' => 'active', 'ssl' => ['status' => 'active']]]);
            },
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-2' => Http::response(['result' => ['status' => 'active', 'ssl' => ['status' => 'active']]]),
        ]);
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertJsonPath('data.domaine.statut', 'actif');

        // Un second site, en attente, que la boucle doit vérifier malgré la panne du premier
        app(ContexteClinique::class)->oublier();
        $autre = Clinique::factory()->create();
        $jetonAutre = $this->administrateur($autre);
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($jetonAutre))->assertOk();
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.autre-clinique.ch'], $this->enTetes($jetonAutre))->assertOk();
        SiteWeb::withoutGlobalScopes()->where('domaine', 'www.autre-clinique.ch')->update(['domaine_statut' => DomaineSite::ATTENTE, 'domaine_verifie_le' => null]);

        // « Vérifier » à la main pendant la panne : le dire, sans rien changer
        app(ContexteClinique::class)->oublier();
        $enPanne = true;
        $this->postJson('/api/v1/site/domaine/verifier', [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['domaine' => 'Le service des domaines ne répond pas. Réessayez dans quelques minutes.']);
        $this->assertSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->where('domaine', 'www.ma-clinique.ch')->value('domaine_statut'));

        // Deux jours de panne : le domaine reste actif, l'autre site est vérifié quand même
        app(ContexteClinique::class)->oublier();
        $this->travel(2)->days();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();

        $this->assertSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->where('domaine', 'www.ma-clinique.ch')->value('domaine_statut'));
        $this->getJson('/api/v1/public/domaines/www.ma-clinique.ch')->assertOk();
        $this->assertSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->where('domaine', 'www.autre-clinique.ch')->value('domaine_statut'));
    }

    /** Deux domaines actifs : le premier en panne chez Cloudflare n'arrête pas la vérification du second (24.10.2026). */
    public function test_une_panne_sur_un_domaine_n_arrete_pas_la_verification_des_autres(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);
        $second = 'actif';
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames' => fn (Request $r) => Http::response(['success' => true, 'result' => ['id' => $r['hostname'] === 'www.ma-clinique.ch' ? 'ch-1' : 'ch-2']]),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-1' => fn () => throw new ConnectionException('cURL error 28: timeout'),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-2' => function () use (&$second) {
                return Http::response(['result' => ['status' => $second, 'ssl' => ['status' => 'active']]]);
            },
        ]);
        // Deux sites actifs, le premier vérifié d'abord
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertOk();
        app(ContexteClinique::class)->oublier();
        $autre = Clinique::factory()->create();
        $jetonAutre = $this->administrateur($autre);
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($jetonAutre))->assertOk();
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.autre-clinique.ch'], $this->enTetes($jetonAutre))->assertOk();
        SiteWeb::withoutGlobalScopes()->update(['domaine_statut' => DomaineSite::ACTIF, 'domaine_verifie_le' => now()->subHours(3), 'domaine_relie_le' => now()->subDays(90)]);
        app(ContexteClinique::class)->oublier();

        // Le second ne pointe plus vers Cloudflare : sa vérification passe malgré la panne du premier
        $second = 'moved';
        $this->travel(2)->days();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();

        $this->assertSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->where('domaine', 'www.ma-clinique.ch')->value('domaine_statut'));
        $this->assertNotSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->where('domaine', 'www.autre-clinique.ch')->value('domaine_statut'));
    }

    /** Trop d'appels (429) ou jeton refusé (401, 403) : comme une panne, le domaine reste tel quel. */
    public function test_un_refus_de_cloudflare_qui_n_est_pas_le_domaine_ne_change_rien(): void
    {
        config(['sites.domaines.fournisseur' => 'cloudflare']);
        $statut = 200;
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames' => Http::response(['success' => true, 'result' => ['id' => 'ch-1']]),
            'api.cloudflare.com/client/v4/zones/zone123/custom_hostnames/ch-1' => function () use (&$statut) {
                return $statut === 200 ? Http::response(['result' => ['status' => 'active', 'ssl' => ['status' => 'active']]]) : Http::response(['success' => false], $statut);
            },
        ]);
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertJsonPath('data.domaine.statut', 'actif');
        SiteWeb::withoutGlobalScopes()->update(['domaine_verifie_le' => now()->subHours(3), 'domaine_relie_le' => now()->subDays(90)]);
        app(ContexteClinique::class)->oublier();

        foreach ([429, 401, 403] as $i => $statut) {
            $this->travel(2)->days();
            $this->artisan('sites:verifier-domaines')->assertSuccessful();
            $this->assertSame(DomaineSite::ACTIF, SiteWeb::withoutGlobalScopes()->value('domaine_statut'), (string) $statut);
        }
    }

    public function test_un_domaine_en_attente_plus_de_quatorze_jours_est_retire(): void
    {
        $this->putJson('/api/v1/site/domaine', ['domaine' => 'www.ma-clinique.ch'], $this->enTetes($this->jeton))->assertJsonPath('data.domaine.statut', 'attente');

        $this->travel(15)->days();
        $this->artisan('sites:verifier-domaines')->assertSuccessful();
        $this->assertNull(SiteWeb::withoutGlobalScopes()->firstOrFail()->domaine);
    }
}
