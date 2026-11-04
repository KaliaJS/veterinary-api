<?php

namespace Tests\Unit;

use App\Support\Pdf\SimplePdf;
use PHPUnit\Framework\TestCase;

/**
 * Le PDF maison du bon de commande (04.11.2026) : les accents en WinAnsi, des
 * pages qui s'enchaînent, un nom long qui passe à la ligne sans jamais
 * couper les autres colonnes.
 */
class SimplePdfTest extends TestCase
{
    public function test_les_accents_s_ecrivent_en_winansi(): void
    {
        $pdf = (new SimplePdf)->line('Médicament à l’unité, œil, déjà payé')->output();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString("(M\xE9dicament \xE0 l'unit\xE9, oeil, d\xE9j\xE0 pay\xE9) Tj", $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }

    public function test_les_pages_s_enchainent_et_se_numerotent(): void
    {
        $pdf = new SimplePdf('Clinique des Deux Rives · IDE CHE-123.456.789');
        foreach (range(1, 120) as $i) {
            $pdf->line('Ligne '.$i);
        }
        $out = $pdf->output();

        $this->assertMatchesRegularExpression('/\/Count ([2-9])/', $out);
        $this->assertStringContainsString('(1 / ', $out);
        $this->assertStringContainsString('(Ligne 120) Tj', $out);
        $this->assertSame(substr_count($out, '/Type /Page '), (int) preg_replace('/.*\/Count (\d+).*/s', '$1', $out));
    }

    public function test_une_rangee_en_colonnes_passe_le_nom_long_a_la_ligne_sans_couper_les_autres(): void
    {
        $nom = 'Royal Canin Veterinary Diet Gastrointestinal Low Fat chien croquettes sac de 12 kg, formule 2026';
        $out = (new SimplePdf)->row([$nom, 'CV-778812', '3', 'CHF 21.50', 'CHF 64.50'], [250, 80, 30, 70, 69], ['left', 'left', 'right', 'right', 'right'])->output();

        $this->assertStringContainsString('(CV-778812) Tj', $out);
        $this->assertStringContainsString('(3) Tj', $out);
        $this->assertStringContainsString('(CHF 64.50) Tj', $out);
        $this->assertStringNotContainsString('...', $out);
        // Le nom en au moins deux lignes, entier
        preg_match_all('/\(([^)]*)\) Tj/', $out, $m);
        $morceaux = array_values(array_filter($m[1], fn (string $t): bool => ! in_array($t, ['CV-778812', '3', 'CHF 21.50', 'CHF 64.50'], true)));
        $this->assertGreaterThanOrEqual(2, count($morceaux));
        $this->assertSame($nom, implode(' ', $morceaux));
    }

    public function test_un_mot_plus_large_que_sa_colonne_se_coupe_sans_deborder(): void
    {
        $reference = 'REF-VETPOINT-0123456789-ABCDEFGHIJ-2026';
        $lignes = SimplePdf::wrap($reference, 60, 9.5);

        $this->assertGreaterThan(1, count($lignes));
        $this->assertSame($reference, implode('', $lignes));
        foreach ($lignes as $ligne) {
            $this->assertLessThanOrEqual(60, SimplePdf::width($ligne, 9.5));
        }
        // Un nom ordinaire passe toujours à la ligne aux espaces
        $this->assertSame(['Metacam 1,5 mg/ml', 'chien 100 ml'], SimplePdf::wrap('Metacam 1,5 mg/ml chien 100 ml', 90, 9.5));
    }
}
