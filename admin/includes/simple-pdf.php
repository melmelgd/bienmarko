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

    /** Current top-down cursor (points from the top of the page). */
    public float $y = 0.0;

    protected string $docTitle = '';

    public function __construct(string $docTitle = '')
    {
        $this->docTitle      = $docTitle;
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
        $t = ($style === 'b') ? self::W_BOLD : self::W_REG;   // oblique shares regular metrics
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
        $w    += $pesos * 0.667 * $size;
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

    /** Draws the peso sign: a Helvetica "P" crossed by two bars. */
    protected function peso(float $x, float $base, float $size, string $style, string $color): void
    {
        $this->rawText($x, $base, 'P', $size, $style, $color, 0.0);
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
                $cx += 0.667 * $size + $cs;
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

    /* ------------------------------------------------------------------
       Output
    ------------------------------------------------------------------ */

    /** Streams the finished PDF to the browser and exits. */
    public function output(string $filename): void
    {
        $objs = [];

        $objs[$this->fontRegId]     = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[$this->fontBoldId]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[$this->fontObliqueId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';

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