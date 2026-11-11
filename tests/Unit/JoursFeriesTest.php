<?php

namespace Tests\Unit;

use App\Support\JoursFeries;
use PHPUnit\Framework\TestCase;

/**
 * Les jours fériés par canton (É23), d'après l'annexe du SECO « Jours
 * fériés en Suisse », état au 12.12.2024 (LTr art. 20a al. 1).
 */
class JoursFeriesTest extends TestCase
{
    /** @return array<string, string> date => clé */
    private function jours(string $canton, int $annee, ?string $variante = null): array
    {
        return array_map(fn (array $jour): string => $jour['cle'], JoursFeries::annee($canton, $variante, $annee));
    }

    public function test_paques_se_calcule(): void
    {
        $this->assertSame('2026-04-05', JoursFeries::paques(2026)->toDateString());
        $this->assertSame('2027-03-28', JoursFeries::paques(2027)->toDateString());
        $this->assertSame('2038-04-25', JoursFeries::paques(2038)->toDateString());
    }

    public function test_vaud_2026(): void
    {
        $this->assertSame([
            '2026-01-01' => 'nouvel_an', '2026-01-02' => 'berchtold', '2026-04-03' => 'vendredi_saint', '2026-04-06' => 'lundi_paques',
            '2026-05-14' => 'ascension', '2026-05-25' => 'lundi_pentecote', '2026-08-01' => 'fete_nationale', '2026-09-21' => 'lundi_jeune',
            '2026-12-25' => 'noel',
        ], $this->jours('VD', 2026));
    }

    public function test_geneve_le_jeune_genevois_et_la_restauration(): void
    {
        $jours = $this->jours('GE', 2026);
        $this->assertSame('jeune_genevois', $jours['2026-09-10']);
        $this->assertSame('restauration_ge', $jours['2026-12-31']);
        $this->assertArrayNotHasKey('2026-12-26', $jours);
    }

    public function test_la_nafelser_fahrt_sort_de_la_semaine_sainte(): void
    {
        $this->assertSame('fahrtsfest', $this->jours('GL', 2026)['2026-04-09']);
        $this->assertSame('fahrtsfest', $this->jours('GL', 2027)['2027-04-01']);
        $this->assertSame('fahrtsfest', $this->jours('GL', 2034)['2034-04-13']);
    }

    public function test_neuchatel_le_2_janvier_et_la_saint_etienne_seulement_un_lundi(): void
    {
        $this->assertSame('berchtold', $this->jours('NE', 2023)['2023-01-02']);
        $this->assertArrayNotHasKey('2026-01-02', $this->jours('NE', 2026));
        $this->assertSame('saint_etienne', $this->jours('NE', 2022)['2022-12-26']);
        $this->assertArrayNotHasKey('2026-12-26', $this->jours('NE', 2026));
        $this->assertSame('fete_dieu', $this->jours('NE', 2026, 'landeron')['2026-06-04']);
        $this->assertArrayNotHasKey('2026-06-04', $this->jours('NE', 2026));
    }

    public function test_appenzell_pas_de_saint_etienne_quand_noel_tombe_un_lundi_ou_un_vendredi(): void
    {
        $this->assertSame('saint_etienne', $this->jours('AR', 2025)['2025-12-26']);
        $this->assertArrayNotHasKey('2028-12-26', $this->jours('AR', 2028));
        $this->assertArrayNotHasKey('2026-12-26', $this->jours('AI', 2026));
    }

    public function test_soleure_le_1er_mai_l_apres_midi_et_ses_regions(): void
    {
        $this->assertSame(0.5, JoursFeries::annee('SO', null, 2026)['2026-05-01']['part']);
        $this->assertArrayHasKey('2026-08-15', $this->jours('SO', 2026));
        $this->assertArrayNotHasKey('2026-08-15', $this->jours('SO', 2026, 'bucheggberg'));
    }

    public function test_fribourg_et_argovie_par_region(): void
    {
        $this->assertContains('fete_dieu', $this->jours('FR', 2026));
        $this->assertNotContains('lundi_paques', $this->jours('FR', 2026));
        $this->assertContains('lundi_paques', $this->jours('FR', 2026, 'reformee'));
        $this->assertContains('berchtold', $this->jours('AG', 2026));
        $this->assertContains('immaculee', $this->jours('AG', 2026, 'laufenburg_muri'));
    }

    public function test_le_1er_aout_partout_meme_sans_canton(): void
    {
        $this->assertSame(['2026-08-01' => 'fete_nationale'], $this->jours('XX', 2026));
        $this->assertSame('fete_nationale', JoursFeries::annee(null, null, 2026)['2026-08-01']['cle']);
    }

    public function test_une_clinique_retire_ou_ajoute_un_jour(): void
    {
        $jours = array_map(fn (array $j): string => $j['cle'], JoursFeries::annee('VD', null, 2026, ['lundi_jeune'], ['fete_travail', 'inconnu']));
        $this->assertArrayNotHasKey('2026-09-21', $jours);
        $this->assertSame('fete_travail', $jours['2026-05-01']);
        $this->assertNotContains('inconnu', $jours);
    }
}
