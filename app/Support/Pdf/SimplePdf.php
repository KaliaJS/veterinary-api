<?php

namespace App\Support\Pdf;

/**
 * Un PDF maison, écrit à la main comme celui de l'app web (src/lib/pdfDoc.js) :
 * PDF 1.4, A4, Helvetica et Helvetica-Bold de base en WinAnsi, du texte et
 * des filets, des pages qui s'enchaînent. Assez pour un bon de commande
 * envoyé par e-mail (03.11.2026), sans bibliothèque.
 */
final class SimplePdf
{
    private const float WIDTH = 595.0;

    private const float HEIGHT = 842.0;

    public const float LEFT = 48.0;

    public const float RIGHT = 547.0;

    private const float TOP = 790.0;

    private const float BOTTOM = 70.0;

    private const string INK = '0.07 0.09 0.12';

    private const string GRAY = '0.45 0.48 0.53';

    private const string LINE = '0.86 0.88 0.91';

    /** Largeurs d'Helvetica, de l'espace (32) au tilde (126), en millièmes */
    private const array NORMAL = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const array BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** Les signes hors du Latin-1 qui ont un équivalent, comme src/lib/winAnsi.js */
    private const array MAP = [
        '—' => '-', '–' => '-', '−' => '-', '‐' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"',
        '€' => 'EUR', 'œ' => 'oe', 'Œ' => 'OE', '…' => '...', '•' => '-', '≤' => '<=', '≥' => '>=', "\u{202F}" => ' ',
    ];

    /** @var list<string> */
    private array $pages = [];

    private string $ops = '';

    private float $y = self::TOP;

    /** Le pied de chaque page (la société, son IDE) */
    public function __construct(private string $footer = '') {}

    /** Une ligne de texte, avec une valeur alignée à droite ; trop longue, elle s'abrège. */
    public function line(string $text, float $size = 10, bool $isBold = false, ?string $right = null, bool $isGray = false, float $gap = 4): self
    {
        $this->room($size + $gap);
        $font = $isBold ? '/F2' : '/F1';
        $color = $isGray ? self::GRAY : self::INK;
        $rightWidth = $right !== null ? self::width($right, $size, $isBold) + 14 : 0;
        $text = self::fit($text, self::RIGHT - self::LEFT - $rightWidth, $size, $isBold);
        $this->y -= $size;
        $this->ops .= self::text(self::LEFT, $this->y, $font, $size, $color, $text);
        if ($right !== null) {
            $this->ops .= self::text(self::RIGHT - self::width($right, $size, $isBold), $this->y, $font, $size, $color, $right);
        }
        $this->y -= $gap;

        return $this;
    }

    /**
     * Une rangée en colonnes (04.11.2026) : chaque cellule passe à la ligne
     * dans sa colonne, aux espaces, sans jamais rien couper ni abréger ; la
     * rangée prend la hauteur de sa plus haute cellule.
     *
     * @param  list<string>  $cells
     * @param  list<float|int>  $widths  largeurs des colonnes, en points
     * @param  list<string>  $aligns  « left » ou « right » par colonne
     */
    public function row(array $cells, array $widths, array $aligns = [], float $size = 9.5, bool $isBold = false, bool $isGray = false, float $gap = 5): self
    {
        $leading = $size + 2.5;
        $wrapped = [];
        foreach ($cells as $i => $cell) {
            $wrapped[$i] = self::wrap($cell, (float) ($widths[$i] ?? 80) - 6, $size, $isBold);
        }
        $height = max(array_map('count', $wrapped)) * $leading;
        $this->room($height + $gap);
        $font = $isBold ? '/F2' : '/F1';
        $color = $isGray ? self::GRAY : self::INK;
        $x = self::LEFT;
        foreach ($wrapped as $i => $lines) {
            $width = (float) ($widths[$i] ?? 80);
            foreach ($lines as $k => $line) {
                $y = $this->y - $size - $k * $leading;
                $at = ($aligns[$i] ?? 'left') === 'right' ? $x + $width - self::width($line, $size, $isBold) : $x;
                $this->ops .= self::text($at, $y, $font, $size, $color, $line);
            }
            $x += $width;
        }
        $this->y -= $height + $gap;

        return $this;
    }

    /**
     * Le texte en lignes qui tiennent dans la largeur, aux espaces ; un mot
     * plus large que la colonne (une référence longue sans espace) se coupe
     * entre deux caractères, pour ne jamais déborder sur la colonne voisine.
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $width, float $size, bool $isBold = false): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if (self::width($try, $size, $isBold) <= $width) {
                $line = $try;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            // Trop large seul : coupé par caractères
            $line = '';
            foreach (mb_str_split($word) as $char) {
                if ($line !== '' && self::width($line.$char, $size, $isBold) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $char;
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /** Un filet sur toute la largeur. */
    public function rule(float $gap = 8): self
    {
        $this->room($gap);
        $this->y -= $gap / 2;
        $this->ops .= self::LINE.' RG 0.7 w '.self::LEFT.' '.self::num($this->y).' m '.self::RIGHT.' '.self::num($this->y)." l S\n";
        $this->y -= $gap / 2;

        return $this;
    }

    public function space(float $height): self
    {
        $this->y -= $height;

        return $this;
    }

    /** Le document entier. */
    public function output(): string
    {
        $this->closePage();
        $objects = [];
        $count = count($this->pages);
        // 1 catalogue, 2 pages, 3 et 4 les polices, puis contenu et page par page
        $kids = [];
        foreach (array_keys($this->pages) as $i) {
            $kids[] = (6 + $i * 2).' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Producer (Veterinary) >>';
        foreach ($this->pages as $i => $content) {
            $page = $count > 1 ? $content.self::text(self::RIGHT - self::width(($i + 1).' / '.$count, 8, false), 40, '/F1', 8, self::GRAY, ($i + 1).' / '.$count) : $content;
            $objects[6 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.(7 + $i * 2).' 0 R >>';
            $objects[7 + $i * 2] = '<< /Length '.strlen($page)." >>\nstream\n".$page."\nendstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R /Info 5 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** La largeur d'un texte en points, tel que le PDF l'écrit. */
    public static function width(string $text, float $size, bool $isBold = false): float
    {
        $table = $isBold ? self::BOLD : self::NORMAL;
        $total = 0;
        foreach (str_split(self::winAnsi($text)) as $byte) {
            $code = ord($byte);
            $total += $code >= 32 && $code <= 126 ? $table[$code - 32] : 556;
        }

        return $total * $size / 1000;
    }

    /** Le texte en WinAnsi (Latin-1) ; ce qui n'a pas d'équivalent devient « ? », qui se voit. */
    public static function winAnsi(string $text): string
    {
        $text = strtr($text, self::MAP);
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);
            $out .= $code < 256 ? chr($code) : '?';
        }

        return $out;
    }

    private static function fit(string $text, float $width, float $size, bool $isBold): string
    {
        if (self::width($text, $size, $isBold) <= $width) {
            return $text;
        }
        $chars = mb_str_split($text);
        while ($chars !== [] && self::width(rtrim(implode('', $chars)).'...', $size, $isBold) > $width) {
            array_pop($chars);
        }

        return rtrim(implode('', $chars)).'...';
    }

    private static function text(float $x, float $y, string $font, float $size, string $color, string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::winAnsi($text));

        return $color.' rg BT '.$font.' '.self::num($size).' Tf 1 0 0 1 '.self::num($x).' '.self::num($y).' Tm ('.$escaped.") Tj ET\n";
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Une nouvelle page quand la suivante ne tient plus. */
    private function room(float $height): void
    {
        if ($this->y - $height < self::BOTTOM) {
            $this->closePage();
            $this->y = self::TOP;
        }
    }

    private function closePage(): void
    {
        if ($this->ops === '' && $this->pages !== []) {
            return;
        }
        $footer = $this->footer !== '' ? self::text(self::LEFT, 40, '/F1', 8, self::GRAY, self::fit($this->footer, 400, 8, false)) : '';
        $this->pages[] = $this->ops.$footer;
        $this->ops = '';
    }
}
