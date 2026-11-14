<?php

namespace Tests\Feature\Boutique;

use App\Models\Clinique;
use App\Models\Facture;
use App\Models\Produit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les chiffres de la boutique refaits (Jim, 11.11.2026, maquette
 * /ui/boutique-refonte, choix B) : la période (7 jours, 30 jours,
 * 12 mois), le chiffre d'affaires jour par jour (ou mois par mois),
 * l'évolution de chaque nombre par rapport à la période d'avant et la part
 * de chaque famille. Tout est servi : l'écran ne calcule rien.
 */
class ChiffresBoutiqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Europe/Zurich'));
        // Les chiffres : l'administrateur et « Voir les chiffres » (audit C11, 14.11.2026)
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
    }

    private function produit(string $categorie, float $cout = 10): Produit
    {
        return Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'categorie' => $categorie,
            'est_boutique' => true,
            'est_sur_ordonnance' => false,
            'prix' => 30,
            'cout' => $cout,
        ]);
    }

    /** Une vente du comptoir payée à cette heure de la clinique, une ligne de ce produit */
    private function vente(string $quand, Produit $produit, int $quantite, float $prix = 30): void
    {
        $facture = Facture::factory()->payee()->create([
            'clinique_id' => $this->clinique->id,
            'payee_le' => CarbonImmutable::parse($quand, 'Europe/Zurich')->utc(),
            'vente_comptoir' => true,
        ]);
        $facture->lignes()->create(['produit_id' => $produit->id, 'libelle' => $produit->nom, 'quantite' => $quantite, 'prix_chf' => $prix, 'type' => 'med']);
    }

    private function chiffres(array $requete = []): array
    {
        return $this->getJson('/api/v1/boutique/statistiques?'.http_build_query($requete), $this->enTetes($this->jeton))->assertOk()->json('data');
    }

    public function test_sans_periode_ce_sont_les_7_jours_jour_par_jour(): void
    {
        $croquettes = $this->produit('ali');
        $this->vente('2026-11-08 09:00', $croquettes, 1);
        $this->vente('2026-11-14 10:00', $croquettes, 2);

        $data = $this->chiffres();

        $this->assertSame(['jours' => 7, 'du' => '2026-11-08', 'au' => '2026-11-14', 'granularite' => 'jour'], $data['periode']);
        $this->assertSame(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'], array_column($data['serie'], 'cle'));
        $this->assertEquals(30, $data['serie'][0]['chiffre_affaires']);
        $this->assertEquals(0, $data['serie'][1]['chiffre_affaires']);
        $this->assertEquals(60, $data['serie'][6]['chiffre_affaires']);
        $this->assertSame(1, $data['serie'][6]['tickets']);
        $this->assertEquals(90, $data['totaux']['chiffre_affaires']);
    }

    public function test_30_jours_et_12_mois(): void
    {
        $croquettes = $this->produit('ali');
        $this->vente('2026-10-16 09:00', $croquettes, 1);
        $this->vente('2026-10-15 09:00', $croquettes, 4);
        $this->vente('2025-12-03 09:00', $croquettes, 3);
        $this->vente('2025-11-30 09:00', $croquettes, 5);

        $trente = $this->chiffres(['periode' => 30]);
        $this->assertSame('2026-10-16', $trente['periode']['du']);
        $this->assertCount(30, $trente['serie']);
        // Le 15 octobre est hors des 30 jours
        $this->assertEquals(30, $trente['totaux']['chiffre_affaires']);

        $an = $this->chiffres(['periode' => 365]);
        $this->assertSame(['jours' => 365, 'du' => '2025-12-01', 'au' => '2026-11-14', 'granularite' => 'mois'], $an['periode']);
        $this->assertSame('2025-12', $an['serie'][0]['cle']);
        $this->assertSame('2026-11', $an['serie'][11]['cle']);
        $this->assertCount(12, $an['serie']);
        $this->assertEquals(90, $an['serie'][0]['chiffre_affaires']);
        // Novembre 2025 est hors des 12 mois
        $this->assertEquals(240, $an['totaux']['chiffre_affaires']);
    }

    public function test_une_autre_periode_est_refusee(): void
    {
        $this->getJson('/api/v1/boutique/statistiques?periode=12', $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('periode');
    }

    public function test_chaque_nombre_dit_son_evolution_par_rapport_a_la_periode_d_avant(): void
    {
        $croquettes = $this->produit('ali', cout: 15);
        // Les 7 jours d'avant (du 1er au 7 novembre) : un ticket de 100
        $this->vente('2026-11-03 09:00', $croquettes, 1, 100);
        // Cette semaine : deux tickets, 150 en tout
        $this->vente('2026-11-10 09:00', $croquettes, 1, 100);
        $this->vente('2026-11-12 09:00', $croquettes, 1, 50);

        $data = $this->chiffres();

        $this->assertEquals(['chiffre_affaires' => 100, 'tickets' => 1, 'panier_moyen' => 100, 'marge_pct' => 85], $data['precedent']);
        $this->assertSame(['chiffre_affaires' => 50, 'tickets' => 100, 'panier_moyen' => -25, 'marge_pct' => -5], $data['evolutions']);
    }

    public function test_sans_rien_avant_pas_d_evolution(): void
    {
        $this->vente('2026-11-12 09:00', $this->produit('ali'), 1);

        $data = $this->chiffres();

        $this->assertSame(['chiffre_affaires' => null, 'tickets' => null, 'panier_moyen' => null, 'marge_pct' => null], $data['evolutions']);
    }

    public function test_la_part_de_chaque_famille(): void
    {
        $this->vente('2026-11-12 09:00', $this->produit('ali'), 3);
        $this->vente('2026-11-12 10:00', $this->produit('acc'), 1);

        $data = $this->chiffres();

        $this->assertEquals([
            ['categorie' => 'ali', 'chiffre_affaires' => 90, 'part_pct' => 75],
            ['categorie' => 'acc', 'chiffre_affaires' => 30, 'part_pct' => 25],
        ], $data['par_famille']);
    }
}
