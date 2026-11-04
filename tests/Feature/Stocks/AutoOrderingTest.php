<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Enums\StatutCommandeFournisseur;
use App\Mail\AutoBasketDecision;
use App\Mail\AutoBasketNotice;
use App\Mail\PurchaseOrderToSupplier;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\Stock;
use App\Services\GestionnaireStock;
use App\Services\Purchasing\AutoOrdering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CommandeAutomatique;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\FakeCentral;
use Tests\TestCase;

/**
 * Centrales d'achat et commande automatique (décisions de Jim, 03.11.2026),
 * cases 4 à 7 : l'article réglé « automatique » entre sous son seuil dans le
 * panier de sa source préférée ; le panier part seul au minimum ou à sa date
 * butoir, la clinique prévenue la veille ; sous le minimum à la date butoir,
 * la règle de la clinique ; un article indisponible passe à sa source
 * suivante, sinon reste à commander à la main, avec une alerte.
 */
class AutoOrderingTest extends TestCase
{
    use CommandeAutomatique;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        FakeCentral::reset();
        config(['purchasing.connectors.test' => FakeCentral::class, 'purchasing.auto_send_api' => true]);
        Mail::fake();
        // Lundi 2 novembre 2026, 9 h à Zurich
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Zurich'));

        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'email' => 'clinique@deuxrives.ch']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    /** Case 4 : sous son seuil, l'article réglé automatique entre dans le panier automatique de sa source préférée. */
    public function test_sous_son_seuil_l_article_entre_dans_le_panier_de_sa_source_preferee(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $covetrus = $this->fournisseur('Covetrus');
        $metacam = $this->article('Metacam', 5, 3, [$vetpoint, $covetrus], upTo: 6);

        // Encore au dessus du seuil : rien
        $this->sortir($metacam, 1);
        $this->assertNull($this->panier($vetpoint));

        // 2 sur 3 : dans le panier de Vetpoint, assez pour remonter à 6, au prix de cette source
        $this->sortir($metacam, 2);
        $panier = $this->panier($vetpoint);
        $this->assertNotNull($panier);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $panier->statut);
        $this->assertSame($this->clinique->id, (int) $panier->clinique_id);
        $this->assertSame('Vetpoint', $panier->fournisseur);
        $this->assertCount(1, $panier->lignes);
        $ligne = $panier->lignes->first();
        $this->assertSame(4, (int) $ligne->quantite);
        $this->assertSame(20.0, (float) $ligne->cout);
        $this->assertSame(2, (int) $ligne->reason_stock);
        $this->assertSame(3, (int) $ligne->reason_threshold);
        $this->assertNull($this->panier($covetrus));

        // Une autre sortie : ni second panier, ni seconde ligne
        $this->sortir($metacam, 1);
        $this->assertSame(1, CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $vetpoint->id)->count());
        $this->assertCount(1, $this->panier($vetpoint)->lignes);

        // Un deuxième article de la même source : le même panier
        $cerenia = $this->article('Cerenia', 1, 1, [$vetpoint]);
        $this->sortir($cerenia, 1);
        $this->assertSame(1, CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $vetpoint->id)->count());
        $this->assertCount(2, $this->panier($vetpoint)->lignes);

        // Le panier se lit dans Commandes : automatique, sa source, pourquoi chaque article est là
        $lu = collect($this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data'))->firstWhere('id', $panier->id);
        $this->assertTrue($lu['is_auto']);
        $this->assertSame($vetpoint->id, $lu['supplier_id']);
        $this->assertSame(['stock' => 2, 'threshold' => 3], array_intersect_key($lu['lignes'][0]['reason'], ['stock' => 0, 'threshold' => 0]));
    }

    public function test_un_article_qui_n_est_pas_automatique_n_entre_dans_aucun_panier(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $gants = $this->article('Gants', 4, 3, [$vetpoint], isAuto: false);

        $this->sortir($gants, 3);

        $this->assertNull($this->panier($vetpoint));
    }

    public function test_toute_sortie_compte_une_fois_la_transaction_validee(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        app(ContexteClinique::class)->definir($this->clinique);

        // Une sortie annulée avec sa transaction : aucun panier
        try {
            DB::transaction(function () use ($metacam): void {
                app(GestionnaireStock::class)->sortir($metacam, 2, 'Vente');
                throw new \RuntimeException('annulée');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull($this->panier($vetpoint));

        // Validée : le panier
        DB::transaction(fn () => app(GestionnaireStock::class)->sortir($metacam, 2, 'Vente'));
        $this->assertNotNull($this->panier($vetpoint));
    }

    public function test_un_article_deja_en_commande_n_entre_pas_au_panier(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $commande = CommandeFournisseur::factory()->create(['clinique_id' => $this->clinique->id, 'statut' => StatutCommandeFournisseur::Envoyee, 'envoyee_le' => now()]);
        $commande->lignes()->create(['produit_id' => $metacam->id, 'quantite' => 3, 'cout' => 10]);

        $this->sortir($metacam, 2);

        $this->assertNull($this->panier($vetpoint));
    }

    /** Case 5 : le panier part seul dès que son minimum est atteint. */
    public function test_le_panier_part_seul_des_que_son_minimum_est_atteint(): void
    {
        // Du lundi au vendredi à 12 h
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'commandes@covetrus.ch', 'minimum_order' => 100, 'order_days' => [1, 2, 3, 4, 5], 'order_time' => '12:00']);
        $metacam = $this->article('Metacam', 4, 3, [$covetrus]);
        $cerenia = $this->article('Cerenia', 4, 3, [$covetrus]);

        // 5 × 20 = 100 ? Non : la première ligne fait 3 × 20 = 60, sous le minimum
        $this->sortir($metacam, 1);
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($covetrus)->statut);
        // Lundi 12 h est trop proche pour l'avis : la date butoir passe à mardi 12 h, l'avis le dit
        Mail::assertNotSent(PurchaseOrderToSupplier::class);

        // Deuxième article : 60 + 60 = 120, le minimum est atteint. Avis d'abord, 12 heures au moins avant :
        // lundi 9 h, le départ se fixe mardi à 12 h ; puis il part par e-mail avec son bon en PDF
        $this->sortir($cerenia, 1);
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($covetrus)->statut);
        Mail::assertSent(AutoBasketNotice::class, 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:05', 'Europe/Zurich'));
        $this->passe();
        $panier = $this->panier($covetrus);
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $panier->statut);
        $this->assertNotNull($panier->envoyee_le);
        Mail::assertSent(PurchaseOrderToSupplier::class, function (PurchaseOrderToSupplier $mail) use ($panier): bool {
            $pieces = $mail->attachments();

            return $mail->hasTo('commandes@covetrus.ch') && $mail->hasCc('clinique@deuxrives.ch')
                && $mail->order->is($panier) && count($pieces) === 1 && str_starts_with($mail->pdf(), '%PDF-');
        });

        // Un nouvel article sous le seuil ouvre un nouveau panier
        $autre = $this->article('Allermyl', 2, 2, [$covetrus]);
        $this->sortir($autre, 1);
        $this->assertSame(2, CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $covetrus->id)->count());
    }

    /** Case 5 : ou à sa date butoir ; la clinique est prévenue la veille, et peut le modifier ou l'arrêter. */
    public function test_le_panier_part_a_sa_date_butoir_prevenu_la_veille(): void
    {
        // Vendredi 12 h, envoyer quand même sous le minimum
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500, 'order_days' => [5], 'order_time' => '12:00', 'under_minimum' => 'send']);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($centrale);
        $this->assertSame(CarbonImmutable::parse('2026-11-06 12:00', 'Europe/Zurich')->getTimestamp(), $panier->deadline_at->getTimestamp());

        // Mercredi : trop tôt pour prévenir
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Zurich'));
        $this->passe();
        Mail::assertNotSent(AutoBasketNotice::class);

        // Jeudi 13 h, la veille : les gestionnaires du stock sont prévenus, une fois
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00', 'Europe/Zurich'));
        $this->passe();
        $this->passe();
        Mail::assertSent(AutoBasketNotice::class, 1);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($centrale)->statut);

        // L'alerte le dit aussi dans l'app
        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->pluck('titre');
        $this->assertTrue($titres->contains(fn (string $t): bool => str_contains($t, 'Centrale Test') && str_contains($t, 'demain')));

        // Vendredi 12 h 05 : il part, par la centrale
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:05', 'Europe/Zurich'));
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $this->panier($centrale)->statut);
        $this->assertCount(1, FakeCentral::$sent);
    }

    public function test_le_panier_en_pause_ne_part_pas_seul(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10, 'order_days' => [5], 'order_time' => '12:00', 'under_minimum' => 'send']);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        // Pas encore parti : la pause d'abord
        config(['purchasing.connectors.test' => FakeCentral::class]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($centrale);

        $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/pause', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.is_paused', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 13:00', 'Europe/Zurich'));
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($centrale)->statut);
        $this->assertSame([], FakeCentral::$sent);

        // Reprendre : avisé d'abord, il part à la prochaine heure de commande qui laisse 12 heures
        $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/reprendre', [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.is_paused', false);
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($centrale)->statut);
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:05', 'Europe/Zurich'));
        $this->passe();
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $this->panier($centrale)->statut);
    }

    public function test_envoyer_maintenant_un_panier_automatique(): void
    {
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'commandes@covetrus.ch', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$covetrus]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($covetrus);

        $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/envoyer-maintenant', [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.statut', 'sent')->assertJsonPath('data.sent_to', 'commandes@covetrus.ch');
        Mail::assertSent(PurchaseOrderToSupplier::class, 1);
    }

    /** Case 6 : sous le minimum à la date butoir, « demander ». */
    public function test_sous_le_minimum_a_la_date_butoir_la_clinique_est_interrogee(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500, 'order_days' => [5], 'order_time' => '12:00', 'under_minimum' => 'ask']);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);

        // L'avis de la veille, puis la date butoir
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00', 'Europe/Zurich'));
        $this->passe();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:05', 'Europe/Zurich'));
        $this->passe();
        $this->passe();

        $panier = $this->panier($centrale);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $panier->statut);
        $this->assertSame([], FakeCentral::$sent);
        Mail::assertSent(AutoBasketDecision::class, 1);
        $lu = $this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data.0');
        $this->assertTrue($lu['is_awaiting_decision']);

        // « Attendre la date suivante » : vendredi suivant
        $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/attendre', [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.is_awaiting_decision', false);
        $this->assertSame(CarbonImmutable::parse('2026-11-13 12:00', 'Europe/Zurich')->getTimestamp(), $this->panier($centrale)->deadline_at->getTimestamp());
    }

    /** Case 6 : « envoyer quand même » et « attendre la date suivante ». */
    public function test_sous_le_minimum_a_la_date_butoir_envoyer_quand_meme_ou_attendre(): void
    {
        $envoyer = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500, 'order_days' => [5], 'order_time' => '12:00', 'under_minimum' => 'send']);
        $attendre = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'c@covetrus.ch', 'minimum_order' => 500, 'order_days' => [5], 'order_time' => '12:00', 'under_minimum' => 'wait']);
        $this->sortir($this->article('Metacam', 4, 3, [$envoyer]), 1);
        $this->sortir($this->article('Cerenia', 4, 3, [$attendre]), 1);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00', 'Europe/Zurich'));
        $this->passe();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:05', 'Europe/Zurich'));
        $this->passe();

        $this->assertSame(StatutCommandeFournisseur::Envoyee, $this->panier($envoyer)->statut);
        $attente = $this->panier($attendre);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $attente->statut);
        $this->assertSame(CarbonImmutable::parse('2026-11-13 12:00', 'Europe/Zurich')->getTimestamp(), $attente->deadline_at->getTimestamp());
        Mail::assertNotSent(PurchaseOrderToSupplier::class);
        Mail::assertNotSent(AutoBasketDecision::class);
    }

    /** Case 7 : indisponible chez sa centrale, l'article passe seul à sa source suivante, et le panier de l'autre fournisseur le dit. */
    public function test_un_article_indisponible_passe_a_sa_source_suivante(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'c@covetrus.ch', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        $bravecto = $this->article('Bravecto', 4, 3, [$centrale, $covetrus]);
        $this->sortir($metacam, 1);
        $this->sortir($bravecto, 1);
        FakeCentral::$unavailable = [$bravecto->id];

        $this->passeJusquAuDepart();

        // Parti sans lui chez la centrale
        $parti = $this->panier($centrale);
        $this->assertSame(StatutCommandeFournisseur::Envoyee, $parti->statut);
        $this->assertSame([$metacam->id], array_keys(FakeCentral::$sent[0]['lines']));
        $this->assertSame([['produit_id' => $bravecto->id, 'name' => 'Bravecto', 'to' => 'Covetrus']], $parti->moved_out);

        // Dans le panier de Covetrus, qui dit d'où il vient, au prix de cette source
        $ligne = $this->panier($covetrus)->lignes->sole();
        $this->assertSame($bravecto->id, (int) $ligne->produit_id);
        $this->assertSame('Centrale Test', $ligne->moved_from);
        $this->assertSame(21.0, (float) $ligne->cout);
        $lu = collect($this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data'))->firstWhere('supplier_id', $covetrus->id);
        $this->assertSame('Centrale Test', $lu['lignes'][0]['moved_from']);
    }

    /** Case 7 : sans autre source, il reste à commander à la main, avec une alerte. */
    public function test_sans_autre_source_l_article_reste_a_commander_a_la_main_avec_une_alerte(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        $bravecto = $this->article('Bravecto', 4, 3, [$centrale]);
        $this->sortir($metacam, 1);
        $this->sortir($bravecto, 1);
        FakeCentral::$unavailable = [$bravecto->id];

        $this->passeJusquAuDepart();

        $this->assertSame([['produit_id' => $bravecto->id, 'name' => 'Bravecto', 'to' => null]], $this->panier($centrale)->moved_out);
        $this->assertNotNull(Stock::query()->withoutGlobalScopes()->where('produit_id', $bravecto->id)->value('manual_order_since'));

        $alerte = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->first(fn (array $a): bool => str_contains($a['titre'], 'à commander à la main'));
        $this->assertNotNull($alerte);
        $this->assertStringContainsString('Bravecto', $alerte['detail']);
        $this->assertStringContainsString('Centrale Test', $alerte['detail']);

        // Dans le stock bas, il est dit à commander à la main
        $article = collect($this->getJson('/api/v1/stocks/commandes/stock-bas', $this->enTetes($this->jeton))->json('data'))->flatMap(fn (array $g) => $g['articles'])->firstWhere('produit_id', $bravecto->id);
        $this->assertNotNull($article['manual_order']);

        // Commandé à la main : l'alerte tombe
        $this->postJson('/api/v1/stocks/commandes', ['fournisseur' => 'Provet AG', 'lignes' => [['produit_id' => $bravecto->id, 'quantite' => 3]]], $this->enTetes($this->jeton))->assertCreated();
        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->pluck('titre');
        $this->assertFalse($titres->contains(fn (string $t): bool => str_contains($t, 'à commander à la main')));
    }

    public function test_une_centrale_en_panne_laisse_le_panier_et_le_dit(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);
        FakeCentral::$failure = 'La centrale ne répond pas.';

        $this->passeJusquAuDepart();

        $panier = $this->panier($centrale);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $panier->statut);
        $this->assertSame('La centrale ne répond pas.', $panier->auto_error);
    }

    public function test_sans_l_accord_l_envoi_automatique_par_une_centrale_attend(): void
    {
        config(['purchasing.auto_send_api' => false]);
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);

        $this->passeJusquAuDepart();
        $this->passe();

        $this->assertSame(StatutCommandeFournisseur::Brouillon, $this->panier($centrale)->statut);
        $this->assertSame([], FakeCentral::$sent);
        Mail::assertSent(AutoBasketDecision::class, 1);
    }

    public function test_le_service_se_resout(): void
    {
        $this->assertInstanceOf(AutoOrdering::class, app(AutoOrdering::class));
    }
}
