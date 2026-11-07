<?php
/**
 * admin/includes/simple-pdf.php
 * -----------------------------------------------------------------------
 * A dependency-free PDF writer used by the Reports & Analytics
 * "Export -> PDF" option. Hand-writes the raw PDF object/xref structure
 * using the built-in base-14 Helvetica fonts, so it needs no external
 * library (no Composer, no dompdf/mpdf/tcpdf) and works on any stock PHP.
 *
 * This version is a small layout engine, not just a text dumper: it can
 * fill + border rectangles, draw rules, word-wrap text, right/centre
 * align, and paginate. includes/report-pdf.php builds on it to reproduce
 * the paper layout of report-print.php (letterhead, KPI boxes, shaded
 * tables, notes box, footer).
 *
 * Coordinates used by every public method here are TOP-DOWN, in points,
 * measured from the top-left of the page (like CSS), not PDF's native
 * bottom-up system. Page size is A4 portrait with the same margins the
 * print page uses (@page { margin: 13mm 12mm 14mm }).
 *
 * The peso sign has no glyph in the base-14 fonts, so it is drawn as a
 * "P" with two vector bars through it (see peso()).
 *
 * Options (second constructor argument), all optional:
 *   'family'  => 'sans' (Helvetica, default) | 'serif' (Times)
 *   'pageW'   => page width in points  (default A4 595.28)
 *   'pageH'   => page height in points (default A4 841.89)
 *   'margins' => [top, right, bottom, left] in points
 * The statement-style Financial / Performance reports use 'serif' on US
 * Letter with 1-inch margins (see StatementPDF in report-pdf.php).
 * -----------------------------------------------------------------------
 */

class SimplePDF
{
    protected const W_REG = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 761,
        556, 278, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278,
        278, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 278, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    protected const W_BOLD = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 761,
        556, 278, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 278, 611, 278,
        278, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 278, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];

    /* Times-Roman / Times-Bold / Times-Italic (Adobe base-14 AFM widths,
       WinAnsi codes 0-255, 1000 units per em). Italic is NOT the same as
       regular in Times, so it has its own table. */
    protected const W_T_REG = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        250, 333, 408, 500, 500, 833, 778, 180, 333, 333, 500, 564, 250, 333, 250, 278,
        500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 278, 278, 564, 564, 564, 444,
        921, 722, 667, 667, 722, 611, 556, 722, 722, 333, 389, 722, 611, 889, 722, 722,
        556, 722, 667, 556, 611, 722, 722, 944, 722, 722, 611, 333, 278, 333, 469, 500,
        333, 444, 500, 444, 500, 444, 333, 500, 500, 278, 278, 500, 278, 778, 500, 500,
        500, 500, 333, 389, 278, 500, 500, 722, 500, 500, 444, 480, 200, 480, 541, 761,
        500, 0, 333, 500, 444, 1000, 500, 500, 333, 1000, 556, 333, 889, 0, 611, 0,
        0, 333, 333, 444, 444, 350, 500, 1000, 333, 980, 389, 333, 722, 0, 444, 722,
        250, 333, 500, 500, 500, 500, 200, 500, 333, 760, 276, 500, 564, 333, 760, 333,
        400, 564, 300, 300, 333, 500, 453, 250, 333, 300, 310, 500, 750, 750, 750, 444,
        722, 722, 722, 722, 722, 722, 889, 667, 611, 611, 611, 611, 333, 333, 333, 333,
        722, 722, 722, 722, 722, 722, 722, 564, 722, 722, 722, 722, 722, 722, 556, 500,
        444, 444, 444, 444, 444, 444, 667, 444, 444, 444, 444, 444, 278, 278, 278, 278,
        500, 500, 500, 500, 500, 500, 500, 564, 500, 500, 500, 500, 500, 500, 500, 500,
    ];

    protected const W_T_BOLD = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        250, 333, 555, 500, 500, 1000, 833, 278, 333, 333, 500, 570, 250, 333, 250, 278,
        500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 570, 570, 570, 500,
        930, 722, 667, 722, 722, 667, 611, 778, 778, 389, 500, 778, 667, 944, 722, 778,
        611, 778, 722, 556, 667, 722, 722, 1000, 722, 722, 667, 333, 278, 333, 581, 500,
        333, 500, 556, 444, 556, 444, 333, 500, 556, 278, 333, 556, 278, 833, 556, 500,
        556, 556, 444, 389, 333, 556, 500, 722, 500, 500, 444, 394, 220, 394, 520, 761,
        500, 0, 333, 500, 500, 1000, 500, 500, 333, 1000, 556, 333, 1000, 0, 667, 0,
        0, 333, 333, 500, 500, 350, 500, 1000, 333, 1000, 389, 333, 722, 0, 444, 722,
        250, 333, 500, 500, 500, 500, 220, 500, 333, 747, 300, 500, 570, 333, 747, 333,
        400, 570, 300, 300, 333, 556, 540, 250, 333, 300, 330, 500, 750, 750, 750, 500,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 389, 389, 389, 389,
        722, 722, 778, 778, 778, 778, 778, 570, 778, 722, 722, 722, 722, 722, 611, 556,
        500, 500, 500, 500, 500, 500, 722, 444, 444, 444, 444, 444, 278, 278, 278, 278,
        500, 556, 500, 500, 500, 500, 500, 570, 500, 556, 556, 556, 556, 500, 556, 500,
    ];

    protected const W_T_ITAL = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        250, 333, 420, 500, 500, 833, 778, 214, 333, 333, 500, 675, 250, 333, 250, 278,
        500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 675, 675, 675, 500,
        920, 611, 611, 667, 722, 611, 611, 722, 722, 333, 444, 667, 556, 833, 667, 722,
        611, 722, 611, 500, 556, 722, 611, 833, 611, 556, 556, 389, 278, 389, 422, 500,
        333, 500, 500, 444, 500, 444, 278, 500, 500, 278, 278, 444, 278, 722, 500, 500,
        500, 500, 389, 389, 278, 500, 444, 667, 444, 444, 389, 400, 275, 400, 541, 761,
        500, 0, 333, 500, 556, 889, 500, 500, 333, 1000, 500, 333, 944, 0, 556, 0,
        0, 333, 333, 556, 556, 350, 500, 889, 333, 980, 389, 333, 667, 0, 389, 556,
        250, 389, 500, 500, 500, 500, 275, 500, 333, 760, 276, 500, 675, 333, 760, 333,
        400, 675, 300, 300, 333, 500, 523, 250, 333, 300, 310, 500, 750, 750, 750, 500,
        611, 611, 611, 611, 611, 611, 889, 667, 611, 611, 611, 611, 333, 333, 333, 333,
        722, 667, 722, 722, 722, 722, 722, 675, 722, 722, 722, 722, 722, 556, 611, 500,
        500, 500, 500, 500, 500, 500, 667, 444, 444, 444, 444, 444, 278, 278, 278, 278,
        500, 500, 500, 500, 500, 500, 500, 675, 500, 500, 500, 500, 500, 444, 500, 444,
    ];

    /** @var array<int,int> */
    protected array $pageIds = [];
    /** @var array<int,string> */
    protected array $pageContent = [];
    protected int $nextId = 1;

    public float $pageW = 595.28;   // A4, points
    public float $pageH = 841.89;
    protected float $mL = 34.02;    // 12mm
    protected float $mR = 34.02;
    protected float $mT = 36.85;    // 13mm
    protected float $mB = 39.69;    // 14mm

    protected int $fontRegId;
    protected int $fontBoldId;
    protected int $fontObliqueId;
    protected int $curPage = 0;

    /** 'sans' = Helvetica family, 'serif' = Times family. */
    protected string $family = 'sans';

    /** Optional callback run once per page at output() time: fn(SimplePDF, int $pageNo, int $pageCount). */
    protected ?\Closure $pageDecorator = null;

    /** Current top-down cursor (points from the top of the page). */
    public float $y = 0.0;

    protected string $docTitle = '';

    public function __construct(string $docTitle = '', array $opts = [])
    {
        $this->docTitle = $docTitle;
        if (($opts['family'] ?? '') === 'serif') {
            $this->family = 'serif';
        }
        if (isset($opts['pageW'])) { $this->pageW = (float) $opts['pageW']; }
        if (isset($opts['pageH'])) { $this->pageH = (float) $opts['pageH']; }
        if (isset($opts['margins']) && is_array($opts['margins']) && count($opts['margins']) === 4) {
            [$this->mT, $this->mR, $this->mB, $this->mL] = array_map('floatval', array_values($opts['margins']));
        }
        $this->fontRegId     = $this->reserveId();
        $this->fontBoldId    = $this->reserveId();
        $this->fontObliqueId = $this->reserveId();
        $this->addPage();
    }

    protected function reserveId(): int
    {
        return $this->nextId++;
    }

    /* ------------------------------------------------------------------
       Page geometry
    ------------------------------------------------------------------ */
    public function addPage(): void
    {
        $id = $this->reserveId();
        $this->pageIds[] = $id;
        $this->pageContent[$id] = '';
        $this->curPage = $id;
        $this->y = $this->mT;
    }

    public function left(): float         { return $this->mL; }
    public function right(): float        { return $this->pageW - $this->mR; }
    public function contentWidth(): float { return $this->pageW - $this->mL - $this->mR; }
    public function pageTop(): float      { return $this->mT; }
    public function pageBottom(): float   { return $this->pageH - $this->mB; }
    public function remaining(): float    { return $this->pageBottom() - $this->y; }

    /**
     * Makes sure $h points fit below the cursor. If not, starts a new page.
     * Returns true when a new page was started (CSS drops a block's top
     * margin at a page break, so callers use this to skip their margin).
     */
    public function ensure(float $h): bool
    {
        if ($h > $this->remaining() && $this->y > $this->mT + 0.5) {
            $this->addPage();
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------
       Text helpers
    ------------------------------------------------------------------ */

    /** Normalises glyphs the base-14 fonts lack (U+2212 minus -> en dash). */
    public function norm(string $s): string
    {
        return str_replace(["\u{2212}", "\u{00A0}"], ["\u{2013}", ' '], $s);
    }

    /** UTF-8 -> single-byte WinAnsi (CP1252). Peso signs must already be removed. */
    protected function enc(string $s): string
    {
        $c = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) : false;
        if ($c === false || $c === null) {
            $c = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : $s;
        }
        return $c;
    }

    protected function bytesWidth(string $bytes, string $style): float
    {
        if ($this->family === 'serif') {
            $t = ($style === 'b') ? self::W_T_BOLD : (($style === 'i') ? self::W_T_ITAL : self::W_T_REG);
        } else {
            $t = ($style === 'b') ? self::W_BOLD : self::W_REG;   // Helvetica-Oblique shares regular metrics
        }
        $w = 0;
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $w += $t[ord($bytes[$i])];
        }
        return (float) $w;
    }

    /** Rendered width of a string in points. */
    public function textW(string $s, float $size, string $style = 'r', float $cs = 0.0): float
    {
        $s     = $this->norm($s);
        $pesos = substr_count($s, "\u{20B1}");
        $bytes = $this->enc(str_replace("\u{20B1}", '', $s));
        $w     = $this->bytesWidth($bytes, $style) * $size / 1000.0;
        $w    += $pesos * $this->pesoAdvance($style) * $size;
        $w    += substr_count($s, "\u{2013}\u{20B1}") * 0.18 * $size;   // gap between a minus and the peso sign
        $w    += $cs * (strlen($bytes) + $pesos);
        return $w;
    }

    protected function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return sprintf('%.3F %.3F %.3F',
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255
        );
    }

    protected function pdfEsc(string $bytes): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $bytes);
    }

    protected function raw(string $ops): void
    {
        $this->pageContent[$this->curPage] .= $ops . "\n";
    }

    protected function rawText(float $x, float $base, string $utf8, float $size, string $style, string $color, float $cs): void
    {
        if ($utf8 === '') {
            return;
        }
        $font = ($style === 'b') ? 'F2' : (($style === 'i') ? 'F3' : 'F1');
        $this->raw(sprintf(
            "q %s rg BT /%s %.2F Tf %.3F Tc %.2F %.2F Td (%s) Tj ET Q",
            $this->rgb($color), $font, $size, $cs, $x, $this->pageH - $base,
            $this->pdfEsc($this->enc($utf8))
        ));
    }

    /** Advance width of the drawn peso sign, in em. */
    protected function pesoAdvance(string $style): float
    {
        if ($this->family === 'serif') {
            return ($style === 'b') ? 0.675 : 0.625;
        }
        return 0.667;
    }

    /** Draws the peso sign: a "P" crossed by two bars. */
    protected function peso(float $x, float $base, float $size, string $style, string $color): void
    {
        $this->rawText($x, $base, 'P', $size, $style, $color, 0.0);
        if ($this->family === 'serif') {
            $lw    = $size * (($style === 'b') ? 0.046 : 0.036);
            $right = ($style === 'b') ? 0.600 : 0.545;
            foreach ([0.285, 0.395] as $k) {
                $this->hline($x - 0.02 * $size, $x + $right * $size, $base - $k * $size, $lw, $color);
            }
            return;
        }
        $lw = $size * (($style === 'b') ? 0.07 : 0.055);
        foreach ([0.31, 0.43] as $k) {
            $this->hline($x - 0.05 * $size, $x + 0.60 * $size, $base - $k * $size, $lw, $color);
        }
    }

    /**
     * Draws one line of text.
     * @param float  $x      anchor x (left edge, right edge, or centre, per $align)
     * @param float  $base   baseline, measured from the top of the page
     * @param string $style  'r' regular, 'b' bold, 'i' oblique
     * @param string $align  'l' | 'r' | 'c'
     * @param float  $cs     extra letter-spacing in points
     */
    public function text(float $x, float $base, string $str, float $size = 10, string $style = 'r',
                         string $color = '#111111', string $align = 'l', float $cs = 0.0): void
    {
        $str = $this->norm($str);
        if ($str === '') {
            return;
        }
        $w = $this->textW($str, $size, $style, $cs);
        if ($align === 'r') {
            $x -= $w;
        } elseif ($align === 'c') {
            $x -= $w / 2;
        }
        $cx    = $x;
        $prev  = '';
        $parts = preg_split('/(\x{20B1})/u', $str, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            if ($p === "\u{20B1}") {
                if ($prev !== '' && substr($prev, -3) === "\u{2013}") {
                    $cx += 0.18 * $size;          // keep the minus off the peso bars
                }
                $this->peso($cx, $base, $size, $style, $color);
                $cx += $this->pesoAdvance($style) * $size + $cs;
            } else {
                $this->rawText($cx, $base, $p, $size, $style, $color, $cs);
                $cx += $this->bytesWidth($this->enc($p), $style) * $size / 1000.0 + $cs * strlen($this->enc($p));
            }
            $prev = $p;
        }
    }

    /** Greedy word-wrap of plain text. Returns an array of lines. */
    public function wrap(string $s, float $maxW, float $size, string $style = 'r'): array
    {
        $s = $this->norm($s);
        if (trim($s) === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $cur   = '';
        foreach ($words as $word) {
            if ($cur !== '' && $this->textW($cur . ' ' . $word, $size, $style) <= $maxW) {
                $cur .= ' ' . $word;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
                $cur = '';
            }
            if ($this->textW($word, $size, $style) <= $maxW) {
                $cur = $word;
                continue;
            }
            // A single word wider than the column: break it by characters.
            $chunk = '';
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if ($chunk !== '' && $this->textW($chunk . $ch, $size, $style) > $maxW) {
                    $lines[] = $chunk;
                    $chunk = $ch;
                } else {
                    $chunk .= $ch;
                }
            }
            $cur = $chunk;
        }
        if ($cur !== '' || !$lines) {
            $lines[] = $cur;
        }
        return $lines;
    }

    /**
     * Word-wrap for mixed styles. $segs = [[text, style], ...] with style
     * 'r' | 'b' | 'i'. Returns lines; each line is a list of tokens with
     * keys t (text), s (style) and x (offset from the line start).
     */
    public function wrapRich(array $segs, float $maxW, float $size): array
    {
        $tokens  = [];
        $pending = false;
        foreach ($segs as $seg) {
            $text  = $this->norm((string) $seg[0]);
            $style = isset($seg[1]) ? (string) $seg[1] : 'r';
            $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $p) {
                if (trim($p) === '') {
                    $pending = true;
                    continue;
                }
                $tokens[] = ['t' => $p, 's' => $style, 'sp' => $pending, 'w' => $this->textW($p, $size, $style)];
                $pending  = false;
            }
        }
        $space = $this->textW(' ', $size, 'r');
        $lines = [];
        $line  = [];
        $x     = 0.0;
        foreach ($tokens as $tk) {
            $gap = ($tk['sp'] && $line) ? $space : 0.0;
            if ($line && $tk['sp'] && ($x + $gap + $tk['w']) > $maxW) {
                $lines[] = $line;
                $line = [];
                $x    = 0.0;
                $gap  = 0.0;
            }
            $tk['x'] = $x + $gap;
            $x       = $tk['x'] + $tk['w'];
            $line[]  = $tk;
        }
        if ($line) {
            $lines[] = $line;
        }
        if (!$lines) {
            $lines[] = [];
        }
        return $lines;
    }

    /** Draws lines produced by wrapRich(). $top is the top of the first line box. */
    public function drawRich(float $x, float $top, array $lines, float $lh, float $size, string $color = '#111111'): void
    {
        foreach ($lines as $i => $line) {
            $base = $top + $i * $lh + $lh / 2 + 0.36 * $size;
            foreach ($line as $tk) {
                $this->text($x + $tk['x'], $base, $tk['t'], $size, $tk['s'], $color);
            }
        }
    }

    /* ------------------------------------------------------------------
       Shapes
    ------------------------------------------------------------------ */

    /** Horizontal rule centred on $y (top-down), from $x1 to $x2. */
    public function hline(float $x1, float $x2, float $y, float $lw = 0.75, string $color = '#111111'): void
    {
        $py = $this->pageH - $y;
        $this->raw(sprintf("q %s RG %.3F w %.2F %.2F m %.2F %.2F l S Q", $this->rgb($color), $lw, $x1, $py, $x2, $py));
    }

    /** Vertical rule centred on $x, from $y1 to $y2 (top-down). */
    public function vline(float $x, float $y1, float $y2, float $lw = 0.75, string $color = '#111111'): void
    {
        $this->raw(sprintf("q %s RG %.3F w %.2F %.2F m %.2F %.2F l S Q", $this->rgb($color), $lw, $x, $this->pageH - $y1, $x, $this->pageH - $y2));
    }

    /**
     * Filled and/or stroked rectangle. $top is the top edge (top-down).
     * The stroke is drawn inside the box, like a CSS border.
     */
    public function rect(float $x, float $top, float $w, float $h, ?string $fill, ?string $stroke = null,
                         float $lw = 0.75, bool $dash = false): void
    {
        $op = '';
        if ($fill !== null) {
            $op .= $this->rgb($fill) . ' rg ';
        }
        if ($stroke !== null) {
            $op .= $this->rgb($stroke) . sprintf(' RG %.3F w ', $lw) . ($dash ? '[3 2] 0 d ' : '');
            $x += $lw / 2;
            $top += $lw / 2;
            $w -= $lw;
            $h -= $lw;
        }
        $paint = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw(sprintf("q %s%.2F %.2F %.2F %.2F re %s Q", $op, $x, $this->pageH - $top - $h, $w, $h, $paint));
    }

    /** Justified version of drawRich(): every line except the last is stretched to $maxW. */
    public function drawRichJustified(float $x, float $top, array $lines, float $lh, float $size,
                                      float $maxW, string $color = '#111111'): void
    {
        $last = count($lines) - 1;
        foreach ($lines as $i => $line) {
            $base  = $top + $i * $lh + $lh / 2 + 0.36 * $size;
            $shift = array_fill(0, count($line), 0.0);
            if ($i < $last && count($line) > 1) {
                $end  = end($line);
                $used = $end['x'] + $end['w'];
                $gaps = 0;
                foreach ($line as $k => $tk) {
                    if ($k > 0 && !empty($tk['sp'])) { $gaps++; }
                }
                if ($gaps > 0 && $used < $maxW) {
                    $extra = ($maxW - $used) / $gaps;
                    $n = 0;
                    foreach ($line as $k => $tk) {
                        if ($k > 0 && !empty($tk['sp'])) { $n++; }
                        $shift[$k] = $n * $extra;
                    }
                }
            }
            foreach ($line as $k => $tk) {
                $this->text($x + $tk['x'] + $shift[$k], $base, $tk['t'], $size, $tk['s'], $color);
            }
        }
    }

    /* ------------------------------------------------------------------
       Extra vector primitives (used by the statement reports' chart)
    ------------------------------------------------------------------ */

    /** Straight line between two points (top-down coordinates). $dash e.g. [3, 2]. */
    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.75,
                         string $color = '#111111', ?array $dash = null): void
    {
        $d = $dash ? sprintf('[%s] 0 d ', implode(' ', array_map(function ($v) { return sprintf('%.2F', $v); }, $dash))) : '';
        $this->raw(sprintf("q %s RG %.3F w %s%.2F %.2F m %.2F %.2F l S Q",
            $this->rgb($color), $lw, $d, $x1, $this->pageH - $y1, $x2, $this->pageH - $y2));
    }

    /** Open path through $pts = [[x, y], ...] (top-down), round joins and caps. */
    public function polyline(array $pts, float $lw = 1.0, string $color = '#111111'): void
    {
        if (count($pts) < 2) {
            return;
        }
        $p = sprintf("%.2F %.2F m", $pts[0][0], $this->pageH - $pts[0][1]);
        for ($i = 1; $i < count($pts); $i++) {
            $p .= sprintf(" %.2F %.2F l", $pts[$i][0], $this->pageH - $pts[$i][1]);
        }
        $this->raw(sprintf("q %s RG %.3F w 1 J 1 j %s S Q", $this->rgb($color), $lw, $p));
    }

    /** Closed, filled (and optionally stroked) polygon. */
    public function polygon(array $pts, ?string $fill, ?string $stroke = null, float $lw = 0.75): void
    {
        if (count($pts) < 3) {
            return;
        }
        $p = sprintf("%.2F %.2F m", $pts[0][0], $this->pageH - $pts[0][1]);
        for ($i = 1; $i < count($pts); $i++) {
            $p .= sprintf(" %.2F %.2F l", $pts[$i][0], $this->pageH - $pts[$i][1]);
        }
        $op = '';
        if ($fill !== null)   { $op .= $this->rgb($fill) . ' rg '; }
        if ($stroke !== null) { $op .= $this->rgb($stroke) . sprintf(' RG %.3F w 1 j ', $lw); }
        $paint = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw(sprintf("q %s%s h %s Q", $op, $p, $paint));
    }

    /** Circle (four Bezier arcs) centred on ($cx, $cy). */
    public function circle(float $cx, float $cy, float $r, ?string $fill, ?string $stroke = null, float $lw = 0.75): void
    {
        $k  = 0.5522847498 * $r;
        $py = $this->pageH - $cy;
        $p  = sprintf("%.2F %.2F m ", $cx + $r, $py)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c ", $cx + $r, $py + $k, $cx + $k, $py + $r, $cx, $py + $r)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c ", $cx - $k, $py + $r, $cx - $r, $py + $k, $cx - $r, $py)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c ", $cx - $r, $py - $k, $cx - $k, $py - $r, $cx, $py - $r)
            . sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c h", $cx + $k, $py - $r, $cx + $r, $py - $k, $cx + $r, $py);
        $op = '';
        if ($fill !== null)   { $op .= $this->rgb($fill) . ' rg '; }
        if ($stroke !== null) { $op .= $this->rgb($stroke) . sprintf(' RG %.3F w ', $lw); }
        $paint = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->raw(sprintf("q %s%s %s Q", $op, $p, $paint));
    }

    /**
     * Text rotated counter-clockwise by $deg (90 = reads bottom-to-top).
     * $x/$base is the anchor point (baseline), $align 'l' | 'c' | 'r' along the text direction.
     */
    public function textRot(float $x, float $base, string $str, float $size, string $style, string $color,
                            float $deg, string $align = 'c'): void
    {
        $str = $this->norm($str);
        if ($str === '') {
            return;
        }
        $w = $this->textW($str, $size, $style);
        $a = deg2rad($deg);
        $c = cos($a);
        $s = sin($a);
        $off = ($align === 'c') ? $w / 2 : (($align === 'r') ? $w : 0.0);
        $px = $x - $off * $c;
        $py = ($this->pageH - $base) - $off * $s;
        $font = ($style === 'b') ? 'F2' : (($style === 'i') ? 'F3' : 'F1');
        $this->raw(sprintf("q %s rg BT /%s %.2F Tf %.4F %.4F %.4F %.4F %.2F %.2F Tm (%s) Tj ET Q",
            $this->rgb($color), $font, $size, $c, $s, -$s, $c, $px, $py, $this->pdfEsc($this->enc($str))));
    }

    /** Runs $cb(SimplePDF, pageNo, pageCount) for every page when the file is written (footers, "Page X of Y"). */
    public function onEachPage(\Closure $cb): void
    {
        $this->pageDecorator = $cb;
    }

    /* ------------------------------------------------------------------
       Output
    ------------------------------------------------------------------ */

    /** Streams the finished PDF to the browser and exits. */
    public function output(string $filename): void
    {
        $objs = [];

        if ($this->pageDecorator !== null) {
            $total = count($this->pageIds);
            foreach ($this->pageIds as $i => $pid) {
                $this->curPage = $pid;
                ($this->pageDecorator)($this, $i + 1, $total);
            }
        }

        $names = ($this->family === 'serif')
            ? ['Times-Roman', 'Times-Bold', 'Times-Italic']
            : ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique'];
        $objs[$this->fontRegId]     = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $names[0] . ' /Encoding /WinAnsiEncoding >>';
        $objs[$this->fontBoldId]    = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $names[1] . ' /Encoding /WinAnsiEncoding >>';
        $objs[$this->fontObliqueId] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $names[2] . ' /Encoding /WinAnsiEncoding >>';

        $contentIds = [];
        foreach ($this->pageIds as $pid) {
            $cid = $this->reserveId();
            $contentIds[$pid] = $cid;
            $stream = $this->pageContent[$pid];
            $objs[$cid] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $pagesId = $this->reserveId();
        $kids = [];
        foreach ($this->pageIds as $pid) {
            $kids[] = "{$pid} 0 R";
            $objs[$pid] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] "
                . "/Resources << /Font << /F1 {$this->fontRegId} 0 R /F2 {$this->fontBoldId} 0 R /F3 {$this->fontObliqueId} 0 R >> >> "
                . "/Contents {$contentIds[$pid]} 0 R >>";
        }
        $objs[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($this->pageIds) . ' >>';

        $catalogId = $this->reserveId();
        $objs[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";

        $infoId = $this->reserveId();
        $safeTitle = preg_replace('/[^\x20-\x7E]+/u', '-', $this->norm($this->docTitle));   // ASCII only: PDF Info strings use PDFDocEncoding, not WinAnsi
        $objs[$infoId] = '<< /Title (' . $this->pdfEsc((string) $safeTitle) . ') /Producer (Bien Marko Admin) >>';

        ksort($objs);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $maxId = max(array_keys($objs));
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: max-age=0');
        echo $pdf;
        exit;
    }
}