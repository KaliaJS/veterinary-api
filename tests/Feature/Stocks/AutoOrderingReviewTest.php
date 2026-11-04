<?php

namespace Tests\Feature\Stocks;

use App\Auth\ContexteClinique;
use App\Enums\StatutCommandeFournisseur;
use App\Mail\AutoBasketNotice;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\Lot;
use App\Models\ProductOrderSetting;
use App\Models\Produit;
use App\Models\Stock;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderPdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CommandeAutomatique;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\FakeCentral;
use Tests\TestCase;

/**
 * Vérification indépendante de la commande automatique (04.11.2026) : ce que
 * la clinique retire reste retiré, un avis 12 heures avant chaque départ,
 * le réglage par clinique, un fournisseur retiré, l'envoi choisi chez une
 * centrale, le bon en colonnes, et les points faibles de l'article.
 */
class AutoOrderingReviewTest extends TestCase
{
    use CommandeAutomatique;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        FakeCentral::reset();
        config(['purchasing.connectors.test' => FakeCentral::class, 'purchasing.auto_send_api' => true]);
        Mail::fake();
        // Lundi 2 novembre 2026, 9 h à Zurich
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'email' => 'clinique@deuxrives.ch']);
        ['jeton' => $this->jeton, 'user' => $user] = $this->employeAvecJeton($this->clinique);
        $this->userId = $user->id;
    }

    /* ————— GRAVE 2 : ce que la clinique retire reste retiré ————— */

    public function test_un_article_retire_du_panier_n_y_revient_qu_au_dessus_du_seuil(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);

        $this->deleteJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes/'.$panier->lignes->first()->id, [], $this->enTetes($this->jeton))->assertOk();
        $this->passe();
        $this->sortir($metacam, 1);
        $this->assertCount(0, $this->panier($vetpoint)->lignes()->get());

        $reglage = $this->setting($metacam);
        $this->assertNotNull($reglage->excluded_at);
        $this->assertStringContainsString($panier->numero, (string) $reglage->excluded_reason);
        $this->assertSame($this->userId, (int) $reglage->excluded_by);
        $this->getJson('/api/v1/stocks/produits/'.$metacam->id, $this->enTetes($this->jeton))->assertJsonPath('data.auto_excluded.reason', $reglage->excluded_reason);

        // Une réception le remonte au-dessus du seuil : il redevient automatique
        $this->entrer($metacam, 5);
        $this->assertNull($this->setting($metacam)->excluded_at);
        $this->sortir($metacam, 5);
        $this->assertCount(1, $this->panier($vetpoint)->lignes()->get());
    }

    public function test_un_panier_supprime_n_est_pas_recree_par_le_balayage(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $cerenia = $this->article('Cerenia', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $this->sortir($cerenia, 1);

        $this->deleteJson('/api/v1/stocks/commandes/'.$this->panier($vetpoint)->id, [], $this->enTetes($this->jeton))->assertNoContent();
        $this->passe();

        $this->assertNull($this->panier($vetpoint));
        $this->assertNotNull($this->setting($metacam)->excluded_at);
        $this->assertNotNull($this->setting($cerenia)->excluded_at);
    }

    /* ————— MOYEN 1 : un avis au moins 12 heures avant chaque départ ————— */

    public function test_minimum_atteint_le_depart_se_fixe_a_l_heure_qui_laisse_12_heures_et_l_avis_le_dit(): void
    {
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'c@covetrus.ch', 'minimum_order' => 10, 'order_days' => [1, 2, 3, 4, 5], 'order_time' => '15:00']);
        $this->sortir($this->article('Metacam', 4, 3, [$covetrus]), 1);

        $this->passe();

        $panier = $this->panier($covetrus);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $panier->statut);
        // Lundi 9 h + 12 h = 21 h : mardi 15 h
        $this->assertSame(CarbonImmutable::parse('2026-11-03 15:00', 'Europe/Zurich')->getTimestamp(), $panier->deadline_at->getTimestamp());
        Mail::assertSent(AutoBasketNotice::class, fn (AutoBasketNotice $m): bool => $m->whenText() === 'demain à 15:00');
        $this->passe();
        Mail::assertSent(AutoBasketNotice::class, 1);
    }

    public function test_une_date_butoir_trop_proche_passe_a_la_suivante_et_l_avis_le_dit(): void
    {
        // Lundi 10 h : à une heure du passage, pas d'avis encore
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500, 'order_days' => [1], 'order_time' => '10:00', 'under_minimum' => 'send']);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);

        $this->passe();

        $panier = $this->panier($centrale);
        $this->assertSame(CarbonImmutable::parse('2026-11-09 10:00', 'Europe/Zurich')->getTimestamp(), $panier->deadline_at->getTimestamp());
        Mail::assertSent(AutoBasketNotice::class, fn (AutoBasketNotice $m): bool => $m->isPostponed && str_contains($m->whenText(), 'lundi 9 novembre à 10:00'));
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:05', 'Europe/Zurich'));
        $this->passe();
        $this->assertSame([], FakeCentral::$sent);
    }

    public function test_aujourd_hui_ou_demain_au_fuseau_de_la_clinique_meme_au_changement_d_heure(): void
    {
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'c@covetrus.ch', 'minimum_order' => 10, 'order_days' => [1, 2, 3, 4, 5, 6, 7], 'order_time' => '15:00']);
        // Mardi 2 h : + 12 h = 14 h, départ aujourd'hui à 15 h
        $this->travelTo(CarbonImmutable::parse('2026-11-03 02:00', 'Europe/Zurich'));
        $this->sortir($this->article('Metacam', 4, 3, [$covetrus]), 1);
        $this->passe();
        Mail::assertSent(AutoBasketNotice::class, fn (AutoBasketNotice $m): bool => $m->whenText() === 'aujourd’hui à 15:00');

        // Samedi 24 octobre 20 h, la nuit du passage à l'heure d'hiver : dimanche 15 h, « demain »
        Mail::fake();
        $provet = $this->fournisseur('Provet AG', ['channel' => 'email', 'email' => 'p@provet.ch', 'minimum_order' => 10, 'order_days' => [1, 2, 3, 4, 5, 6, 7], 'order_time' => '15:00']);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 20:00', 'Europe/Zurich'));
        $this->sortir($this->article('Cerenia', 4, 3, [$provet]), 1);
        $this->passe();
        Mail::assertSent(AutoBasketNotice::class, fn (AutoBasketNotice $m): bool => $m->basket->supplier_id === $provet->id && $m->whenText() === 'demain à 15:00');
        $this->assertSame(CarbonImmutable::parse('2026-10-25 15:00', 'Europe/Zurich')->getTimestamp(), $this->panier($provet)->deadline_at->getTimestamp());
    }

    /* ————— MOYEN 3 : l'automatique se règle par clinique ————— */

    public function test_l_automatique_et_remonter_jusqu_a_se_reglent_par_clinique(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);
        $produit = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'seuil_alerte' => 3]);
        $ici = $this->fournisseur('Vetpoint');

        $this->putJson('/api/v1/stocks/produits/'.$produit->id.'/sources', ['is_auto_order' => true, 'reorder_up_to' => 8, 'sources' => [['supplier_id' => $ici->id]]], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.is_auto_order', true)->assertJsonPath('data.reorder_up_to', 8);
        $this->getJson('/api/v1/stocks/produits/'.$produit->id, $this->enTetes($jetonSoeur))
            ->assertJsonPath('data.is_auto_order', false)->assertJsonPath('data.reorder_up_to', null);
        $this->assertSame(1, ProductOrderSetting::query()->where('produit_id', $produit->id)->count());
    }

    public function test_pas_d_alerte_a_la_main_dans_une_clinique_qui_n_a_pas_active_l_automatique(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $gants = $this->article('Gants', 2, 3, [$vetpoint], isAuto: false);
        Stock::query()->withoutGlobalScopes()->where('produit_id', $gants->id)->update(['manual_order_since' => now(), 'manual_order_reason' => 'Indisponible chez Vetpoint']);

        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->pluck('titre');
        $this->assertFalse($titres->contains(fn (string $t): bool => str_contains($t, 'à commander à la main')));
    }

    /* ————— MOYEN 5 : un fournisseur retiré ————— */

    public function test_retirer_un_fournisseur_rend_son_panier_ordinaire_et_l_article_passe_a_la_source_suivante(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $provet = $this->fournisseur('Provet AG');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint, $provet]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);

        $this->deleteJson('/api/v1/stocks/fournisseurs/'.$vetpoint->id, [], $this->enTetes($this->jeton))->assertNoContent();

        $panier = CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id);
        $this->assertFalse($panier->is_auto);
        $this->assertSame(StatutCommandeFournisseur::Brouillon, $panier->statut);
        $lu = collect($this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data'))->firstWhere('id', $panier->id);
        $this->assertTrue($lu['is_supplier_removed']);

        $this->passe();
        $this->assertSame($metacam->id, (int) $this->panier($provet)->lignes->sole()->produit_id);
    }

    /* ————— MOYEN 6 : envoyer maintenant chez une centrale, avec ses choix ————— */

    public function test_envoyer_maintenant_chez_une_centrale_avec_adresse_et_livraison(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500]);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);
        $panier = $this->panier($centrale);

        $this->getJson('/api/v1/stocks/commandes/'.$panier->id.'/adresses', $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.0.id', 'A1')->assertJsonPath('data.0.par_defaut', true);
        $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/envoyer-maintenant', ['adresse_id' => 'A1', 'livraison' => 'express'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.statut', 'sent');
        $this->assertSame(['adresse_id' => 'A1', 'livraison' => 'express', 'date_souhaitee' => null], FakeCentral::$sent[0]['choice']);
    }

    /* ————— Points faibles ————— */

    public function test_un_article_sans_autre_source_ne_revient_pas_chez_la_meme_centrale_avant_une_reception(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        $bravecto = $this->article('Bravecto', 5, 3, [$centrale]);
        $this->sortir($metacam, 1);
        $this->sortir($bravecto, 2);
        FakeCentral::$unavailable = [$bravecto->id];
        $this->passeJusquAuDepart();

        $this->sortir($bravecto, 1);
        $this->assertNull($this->panier($centrale)?->lignes->firstWhere('produit_id', $bravecto->id));

        // Réceptionné : il redevient automatique
        $this->entrer($bravecto, 1);
        $this->sortir($bravecto, 1);
        $this->assertNotNull($this->panier($centrale)?->lignes->firstWhere('produit_id', $bravecto->id));
    }

    public function test_la_photo_de_vitrine_seulement_en_boutique_jamais_pour_un_stupefiant_et_retiree_hors_boutique(): void
    {
        Storage::fake('prive');
        $photo = fn () => ['photo' => UploadedFile::fake()->image('p.jpg', 400, 400)];
        $horsBoutique = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_boutique' => false, 'est_stupefiant' => false]);
        $stup = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_boutique' => true, 'est_stupefiant' => true, 'tableau_stupefiant' => 'a']);
        $vendu = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'est_boutique' => true, 'est_stupefiant' => false]);

        $this->post('/api/v1/stocks/produits/'.$horsBoutique->id.'/photo', $photo(), $this->enTetes($this->jeton) + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/api/v1/stocks/produits/'.$stup->id.'/photo', $photo(), $this->enTetes($this->jeton) + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/api/v1/stocks/produits/'.$vendu->id.'/photo', $photo(), $this->enTetes($this->jeton))->assertOk();
        $chemin = $vendu->refresh()->photo_chemin;

        $this->patchJson('/api/v1/boutique/produits/'.$vendu->id.'/vitrine', ['est_boutique' => false], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.photo_url', null);
        Storage::disk('prive')->assertMissing($chemin);
    }

    public function test_un_lot_sans_peremption_est_garde_pour_le_materiel_et_une_peremption_passee_est_refusee(): void
    {
        $creer = fn (array $d) => $this->postJson('/api/v1/stocks/produits', ['prix' => 10, ...$d], $this->enTetes($this->jeton));

        $id = $creer(['nom' => 'Laisse', 'categorie' => 'acc', 'quantite_initiale' => 3, 'lot' => 'L-77'])->assertCreated()->json('data.id');
        $lot = Lot::query()->withoutGlobalScopes()->where('produit_id', $id)->sole();
        $this->assertSame('L-77', $lot->numero_lot);
        $this->assertNull($lot->peremption_le);
        $this->getJson('/api/v1/stocks/lots', $this->enTetes($this->jeton))->assertOk();

        $creer(['nom' => 'Metacam', 'categorie' => 'med', 'quantite_initiale' => 3, 'lot' => 'A1'])->assertUnprocessable()->assertJsonValidationErrors('lot_peremption');
        $creer(['nom' => 'Metacam', 'categorie' => 'med', 'quantite_initiale' => 3, 'lot_peremption' => '2026-10'])
            ->assertUnprocessable()->assertJsonPath('errors.lot_peremption.0', 'La péremption de ce lot est déjà passée.');
        $creer(['nom' => 'Metacam', 'categorie' => 'med', 'quantite_initiale' => 3, 'lot_peremption' => '2026-11'])->assertCreated();
    }

    /* ————— MOYEN 2 : le bon en colonnes ————— */

    public function test_le_bon_en_pdf_garde_la_quantite_et_la_reference_d_un_nom_long(): void
    {
        $covetrus = $this->fournisseur('Covetrus');
        $nom = 'Royal Canin Veterinary Diet Gastrointestinal Low Fat chien croquettes sac de 12 kg, formule 2026';
        $produit = $this->article($nom, 4, 3, [$covetrus]);
        $produit->sources()->update(['reference' => 'CV-778812']);
        $this->sortir($produit, 1);
        app(ContexteClinique::class)->oublier();

        $pdf = app(PurchaseOrderPdf::class)->render($this->panier($covetrus));

        $this->assertStringContainsString('(CV-778812) Tj', $pdf);
        $this->assertStringContainsString('(3) Tj', $pdf);
        // Le nom entier, jamais abrégé (l'en-tête de la clinique, tiré au hasard, peut l'être), passé à la ligne dans sa colonne
        preg_match_all('/\(([^)]*)\) Tj/', $pdf, $m);
        $this->assertStringContainsString($nom, implode(' ', $m[1]));
    }

    public function test_les_sources_et_l_automatique_se_lisent_par_clinique_dans_la_liste(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint], upTo: 7);

        $lu = collect($this->getJson('/api/v1/stocks/produits', $this->enTetes($this->jeton))->json('data'))->firstWhere('id', $metacam->id);
        $this->assertTrue($lu['is_auto_order']);
        $this->assertSame(7, $lu['reorder_up_to']);
        $this->assertSame('Vetpoint', $lu['sources'][0]['supplier_name']);
        $this->assertInstanceOf(Supplier::class, Supplier::query()->withoutGlobalScopes()->find($vetpoint->id));
    }

    /* ————— Deuxième vérification (04.11.2026) : un écart ne survit pas à la chute suivante ————— */

    public function test_remonte_au_dessus_du_seuil_l_article_sort_du_panier_et_revient_a_la_chute_suivante(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $this->assertCount(1, $this->panier($vetpoint)->lignes);

        // Une entrée de 3 : 6, au-dessus du seuil ; la ligne sort d'elle-même, sans écart (le panier vide s'efface)
        $this->entrer($metacam, 3);
        $this->assertNull($this->panier($vetpoint));
        $this->assertNull($this->setting($metacam)->excluded_at);

        // Une sortie de 4 : 2, sous le seuil ; il revient au panier
        $this->sortir($metacam, 4);
        $this->assertCount(1, $this->panier($vetpoint)->lignes()->get());
    }

    public function test_retirer_une_ligne_au_dessus_du_seuil_ne_pose_pas_d_ecart(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        // Le stock remonte hors des gestes de l'app (sans événement) : la ligne est encore là
        Stock::query()->withoutGlobalScopes()->where('produit_id', $metacam->id)->update(['quantite' => 6]);

        $this->deleteJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes/'.$panier->lignes->first()->id, [], $this->enTetes($this->jeton))->assertOk();

        $this->assertNull($this->setting($metacam)->excluded_at);
        Stock::query()->withoutGlobalScopes()->where('produit_id', $metacam->id)->update(['quantite' => 3]);
        $this->sortir($metacam, 1);
        $this->assertCount(1, $this->panier($vetpoint)->lignes()->get());
    }

    public function test_la_fiche_reprend_la_commande_automatique_a_la_main(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 2, 3, [$vetpoint]);
        $this->setting($metacam)->update(['excluded_at' => now(), 'excluded_reason' => 'Retiré du panier automatique BC-2026-001', 'excluded_by' => $this->userId]);

        $this->putJson('/api/v1/stocks/produits/'.$metacam->id.'/sources', ['clear_exclusion' => true], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.auto_excluded', null)->assertJsonPath('data.is_auto_order', true);
        $this->sortir($metacam, 1);
        $this->assertCount(1, $this->panier($vetpoint)->lignes()->get());
    }

    public function test_un_panier_qui_ne_part_pas_seul_est_signale_apres_trois_jours(): void
    {
        // Le réglage d'un fournisseur tout juste créé : ni minimum, ni jour de commande
        $provet = $this->fournisseur('Provet AG');
        $this->sortir($this->article('Metacam', 4, 3, [$provet]), 1);
        $titres = fn () => collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->pluck('titre');
        $this->assertFalse($titres()->contains(fn (string $t): bool => str_contains($t, 'Provet AG')));

        $this->travel(3)->days();
        $this->travel(1)->hours();
        $alerte = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->json('data.alertes'))->first(fn (array $a): bool => str_contains($a['titre'], 'Provet AG'));
        $this->assertNotNull($alerte);
        $this->assertStringContainsString('attend depuis 3 jours', $alerte['titre']);
        $this->assertStringContainsString('ni minimum ni jour de commande', $alerte['detail']);
    }

    public function test_la_commande_dit_le_nom_de_sa_centrale(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 500]);
        $this->sortir($this->article('Metacam', 4, 3, [$centrale]), 1);

        $lu = collect($this->getJson('/api/v1/stocks/commandes?statut=draft', $this->enTetes($this->jeton))->json('data'))->firstWhere('supplier_id', $centrale->id);
        $this->assertSame('Centrale Test', $lu['connector_name']);
    }

    /* ————— Troisième vérification (04.11.2026) ————— */

    public function test_une_ligne_ajoutee_a_la_main_survit_a_une_sortie_au_dessus_du_seuil(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $cerenia = $this->article('Cerenia', 20, 3, [$vetpoint], isAuto: false);
        $sansSeuil = $this->article('Gants', 10, 0, [$vetpoint], isAuto: false);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        // Ajoutés à la main pour atteindre le minimum
        foreach ([$cerenia, $sansSeuil] as $produit) {
            $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes', ['produit_id' => $produit->id, 'quantite' => 2], $this->enTetes($this->jeton))->assertOk();
        }

        $this->sortir($cerenia, 1);
        $this->sortir($sansSeuil, 1);

        $produits = $this->panier($vetpoint)->lignes()->pluck('produit_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$metacam->id, $cerenia->id, $sansSeuil->id], $produits);
    }

    public function test_un_envoi_en_attente_n_est_pas_touche_quand_le_stock_remonte(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint', ['channel' => 'api', 'connector' => 'vetpoint', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        // Parti chez Vetpoint, sans réponse encore
        CommandeFournisseur::query()->withoutGlobalScopes()->whereKey($panier->id)->update(['vetpoint_envoi_le' => now()]);

        $this->entrer($metacam, 3);

        $this->assertCount(1, CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id)->lignes()->get());
    }

    public function test_un_envoi_pose_entre_la_lecture_et_le_verrou_ne_perd_pas_de_ligne(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint', ['channel' => 'api', 'connector' => 'vetpoint', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        // Un autre onglet lance l'envoi juste après que le panier a été lu, avant le verrou
        $isDone = false;
        CommandeFournisseur::retrieved(function (CommandeFournisseur $lu) use (&$isDone, $panier): void {
            if (! $isDone && $lu->id === $panier->id) {
                $isDone = true;
                DB::table('commandes_fournisseur')->where('id', $panier->id)->update(['vetpoint_envoi_le' => now()]);
            }
        });

        $this->entrer($metacam, 3);

        $this->assertTrue($isDone);
        $this->assertCount(1, CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id)->lignes()->get());
    }

    /* ————— Quatrième vérification (04.11.2026) : ce qu'une personne touche devient manuel ————— */

    public function test_une_quantite_changee_a_la_main_rend_la_ligne_manuelle(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        $ligne = $panier->lignes->first();

        $this->patchJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes/'.$ligne->id, ['quantite' => 20], $this->enTetes($this->jeton))->assertOk();
        $this->assertNull($ligne->refresh()->reason_at);

        // Un retour remonte le stock : la ligne réglée à la main reste, avec son panier
        $this->entrer($metacam, 3);
        $this->assertSame(20, (int) CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id)?->lignes()->sole()->quantite);
    }

    public function test_une_ligne_manuelle_deplacee_chez_la_source_suivante_reste_manuelle(): void
    {
        $centrale = $this->fournisseur('Centrale Test', ['channel' => 'api', 'connector' => 'test', 'minimum_order' => 10]);
        $covetrus = $this->fournisseur('Covetrus', ['channel' => 'email', 'email' => 'c@covetrus.ch', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$centrale]);
        $gants = $this->article('Gants', 7, 0, [$centrale, $covetrus], isAuto: false);
        $this->sortir($metacam, 1);
        $this->postJson('/api/v1/stocks/commandes/'.$this->panier($centrale)->id.'/lignes', ['produit_id' => $gants->id, 'quantite' => 7], $this->enTetes($this->jeton))->assertOk();
        FakeCentral::$unavailable = [$gants->id];

        $this->passeJusquAuDepart();

        $ligne = $this->panier($covetrus)->lignes->sole();
        $this->assertSame($gants->id, (int) $ligne->produit_id);
        $this->assertNull($ligne->reason_at);
        // Sortis : la ligne déplacée reste
        $this->sortir($gants, 1);
        $this->assertCount(1, $this->panier($covetrus)->lignes()->get());
    }

    public function test_un_panier_de_lignes_manuelles_reste_entier(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint');
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $gants = $this->article('Gants', 7, 0, [$vetpoint], isAuto: false);
        $cerenia = $this->article('Cerenia', 20, 3, [$vetpoint], isAuto: false);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        foreach ([$gants, $cerenia] as $produit) {
            $this->postJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes', ['produit_id' => $produit->id, 'quantite' => 2], $this->enTetes($this->jeton))->assertOk();
        }
        // La ligne de l'automatique réglée à la main : tout le panier est manuel
        $this->patchJson('/api/v1/stocks/commandes/'.$panier->id.'/lignes/'.$panier->lignes->first()->id, ['quantite' => 5], $this->enTetes($this->jeton))->assertOk();

        $this->entrer($metacam, 5);
        $this->sortir($gants, 1);
        $this->sortir($cerenia, 1);
        $this->passe();

        $this->assertCount(3, CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id)?->lignes()->get() ?? []);
    }

    public function test_un_panier_en_cours_d_envoi_chez_sa_centrale_ne_recoit_aucune_ligne(): void
    {
        $vetpoint = $this->fournisseur('Vetpoint', ['channel' => 'api', 'connector' => 'vetpoint', 'minimum_order' => 500]);
        $metacam = $this->article('Metacam', 4, 3, [$vetpoint]);
        $cerenia = $this->article('Cerenia', 4, 3, [$vetpoint]);
        $this->sortir($metacam, 1);
        $panier = $this->panier($vetpoint);
        CommandeFournisseur::query()->withoutGlobalScopes()->whereKey($panier->id)->update(['vetpoint_envoi_le' => now()]);

        $this->sortir($cerenia, 1);

        $this->assertSame([$metacam->id], CommandeFournisseur::query()->withoutGlobalScopes()->find($panier->id)->lignes()->pluck('produit_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(1, CommandeFournisseur::query()->withoutGlobalScopes()->where('supplier_id', $vetpoint->id)->count());
    }
}
