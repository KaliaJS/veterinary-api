<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\FakeCentral;
use Tests\TestCase;

/**
 * Centrales d'achat et commande automatique (décisions de Jim, 03.11.2026),
 * cases 1 et 2 : les fournisseurs sont une liste de la clinique, chacun avec
 * son canal (une centrale par son interface, un e-mail avec le bon en PDF,
 * ou à la main), son minimum de commande et ses jours et heures de commande.
 * Rien de Vetpoint en dur : les centrales viennent de la configuration.
 */
class PurchasingSuppliersTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        FakeCentral::reset();
        config(['purchasing.connectors.test' => FakeCentral::class]);

        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function creer(array $donnees): TestResponse
    {
        return $this->postJson('/api/v1/stocks/fournisseurs', $donnees, $this->enTetes($this->jeton));
    }

    /** Case 1 : une liste de la clinique, chacun avec son canal ; rien de Vetpoint codé en dur. */
    public function test_les_fournisseurs_sont_une_liste_de_la_clinique_chacun_avec_son_canal(): void
    {
        $this->creer(['name' => 'Vetpoint', 'channel' => 'api', 'connector' => 'vetpoint'])->assertCreated()
            ->assertJsonPath('data.channel', 'api')->assertJsonPath('data.connector', 'vetpoint')->assertJsonPath('data.connector_name', 'Vetpoint')
            ->assertJsonPath('data.is_linked', false);
        $this->creer(['name' => 'Covetrus', 'channel' => 'email', 'email' => 'commandes@covetrus.ch'])->assertCreated()
            ->assertJsonPath('data.email', 'commandes@covetrus.ch');
        $this->creer(['name' => 'Provet AG', 'channel' => 'manual'])->assertCreated();
        // Une autre centrale, déclarée par la configuration seulement : elle se choisit comme Vetpoint
        $this->creer(['name' => 'Centrale Test', 'channel' => 'api', 'connector' => 'test'])->assertCreated()
            ->assertJsonPath('data.connector_name', 'Centrale Test')->assertJsonPath('data.is_linked', true);

        // Le fournisseur d'une autre clinique ne se voit pas d'ici
        app(ContexteClinique::class)->oublier();
        Supplier::query()->withoutGlobalScopes()->create(['clinique_id' => Clinique::factory()->create()->id, 'name' => 'Ailleurs', 'channel' => 'manual']);

        $liste = $this->getJson('/api/v1/stocks/fournisseurs', $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['Centrale Test', 'Covetrus', 'Provet AG', 'Vetpoint'], collect($liste->json('data'))->pluck('name')->all());

        // Les centrales qu'on peut choisir, lues de la configuration
        $centrales = collect($this->getJson('/api/v1/stocks/centrales', $this->enTetes($this->jeton))->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['vetpoint', 'test'], $centrales->pluck('key')->all());
        $this->assertSame('Vetpoint', $centrales->firstWhere('key', 'vetpoint')['name']);
    }

    public function test_le_canal_demande_ce_qui_lui_manque(): void
    {
        // Une centrale inconnue de la configuration est refusée
        $this->creer(['name' => 'Acme', 'channel' => 'api', 'connector' => 'acme'])->assertUnprocessable()->assertJsonValidationErrors('connector');
        $this->creer(['name' => 'Acme', 'channel' => 'api'])->assertUnprocessable()->assertJsonValidationErrors('connector');
        // Par e-mail : l'adresse
        $this->creer(['name' => 'Covetrus', 'channel' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->creer(['name' => 'Covetrus', 'channel' => 'fax'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        // Deux fois le même nom dans la clinique : refusé
        $this->creer(['name' => 'Provet AG', 'channel' => 'manual'])->assertCreated();
        $this->creer(['name' => 'Provet AG', 'channel' => 'manual'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_un_fournisseur_se_modifie_et_se_retire(): void
    {
        $id = $this->creer(['name' => 'Covetrus', 'channel' => 'manual'])->json('data.id');

        $this->patchJson('/api/v1/stocks/fournisseurs/'.$id, ['channel' => 'email', 'email' => 'cmd@covetrus.ch'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.channel', 'email')->assertJsonPath('data.email', 'cmd@covetrus.ch');

        $this->deleteJson('/api/v1/stocks/fournisseurs/'.$id, [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertDatabaseMissing('suppliers', ['id' => $id]);

        // Celui d'une autre clinique n'existe pas d'ici
        app(ContexteClinique::class)->oublier();
        $ailleurs = Supplier::query()->withoutGlobalScopes()->create(['clinique_id' => Clinique::factory()->create()->id, 'name' => 'Ailleurs', 'channel' => 'manual']);
        $this->patchJson('/api/v1/stocks/fournisseurs/'.$ailleurs->id, ['name' => 'Pris'], $this->enTetes($this->jeton))->assertNotFound();
    }

    /** Case 2 : chaque fournisseur a son minimum de commande (CHF) et ses jours et heures de commande (la date butoir). */
    public function test_chaque_fournisseur_a_son_minimum_et_ses_jours_et_heures_de_commande(): void
    {
        // Lundi 2 novembre 2026, 10 h à Zurich : prochaine date butoir mardi 15 h
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Zurich'));

        $reponse = $this->creer([
            'name' => 'Vetpoint', 'channel' => 'manual',
            'minimum_order' => 150, 'order_days' => [2, 5], 'order_time' => '15:00',
            'under_minimum' => 'wait',
        ])->assertCreated();

        $reponse->assertJsonPath('data.minimum_order', 150)
            ->assertJsonPath('data.order_days', [2, 5])
            ->assertJsonPath('data.order_time', '15:00')
            ->assertJsonPath('data.under_minimum', 'wait')
            // L'avis part toujours, au moins 12 heures avant (04.11.2026) : plus de réglage « prévenir la veille »
            ->assertJsonMissingPath('data.notify_eve');
        $this->assertFalse(Schema::hasColumn('suppliers', 'notify_eve'));
        $this->assertSame(CarbonImmutable::parse('2026-11-03 15:00', 'Europe/Zurich')->toIso8601String(), CarbonImmutable::parse($reponse->json('data.next_deadline_at'))->setTimezone('Europe/Zurich')->toIso8601String());

        // Mardi 16 h : la date butoir suivante est vendredi 15 h
        $this->travelTo(CarbonImmutable::parse('2026-11-03 16:00', 'Europe/Zurich'));
        $fiche = $this->getJson('/api/v1/stocks/fournisseurs', $this->enTetes($this->jeton))->json('data.0');
        $this->assertSame(CarbonImmutable::parse('2026-11-06 15:00', 'Europe/Zurich')->getTimestamp(), CarbonImmutable::parse($fiche['next_deadline_at'])->getTimestamp());
    }

    public function test_jours_et_heure_sont_controles(): void
    {
        $this->creer(['name' => 'A', 'channel' => 'manual', 'order_days' => [0, 8]])->assertUnprocessable()->assertJsonValidationErrors(['order_days.0', 'order_days.1']);
        $this->creer(['name' => 'A', 'channel' => 'manual', 'order_time' => '25:00'])->assertUnprocessable()->assertJsonValidationErrors('order_time');
        $this->creer(['name' => 'A', 'channel' => 'manual', 'minimum_order' => -1])->assertUnprocessable()->assertJsonValidationErrors('minimum_order');
        $this->creer(['name' => 'A', 'channel' => 'manual', 'under_minimum' => 'peut-etre'])->assertUnprocessable()->assertJsonValidationErrors('under_minimum');
        // Sans jour de commande : pas de date butoir
        $this->creer(['name' => 'B', 'channel' => 'manual'])->assertCreated()->assertJsonPath('data.next_deadline_at', null)->assertJsonPath('data.minimum_order', 0);
    }

    /** Une centrale ne part seule que si l'envoi automatique est permis (04.11.2026) : l'écran le dit vrai */
    public function test_les_centrales_disent_si_elles_partent_seules(): void
    {
        $lire = fn () => collect($this->getJson('/api/v1/stocks/centrales', $this->enTetes($this->jeton))->assertOk()->json('data'))->firstWhere('key', 'vetpoint');

        config(['purchasing.auto_send_api' => false]);
        $this->assertFalse($lire()['sends_alone']);

        config(['purchasing.auto_send_api' => true]);
        $this->assertTrue($lire()['sends_alone']);
    }
}
