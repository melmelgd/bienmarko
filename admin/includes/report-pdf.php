<?php
/**
 * admin/includes/report-pdf.php
 * -----------------------------------------------------------------------
 * Builds the "Export -> PDF" file so it looks the same as the paper
 * version in report-print.php: letterhead, Period / Printed / Prepared-by
 * block, KPI boxes, shaded tables with group/total rows, the grey
 * "How these figures are calculated" box and the closing footer line.
 *
 * Numbers come from the same reports_*_data() functions as the print page
 * and the on-screen reports, over the same resolved period, so the three
 * can't disagree. If you change a report's wording or sections in
 * report-print.php, mirror the change in the matching rpdf_body_*()
 * function below.
 *
 * EXCEPTION: 'financial' and 'performance' are drawn by StatementPDF (bottom
 * of the class section below) from the shared model in
 * includes/report-statement.php — the formal serif statement layout,
 * identical to report-print.php's output for those two reports.
 *
 * Needs: includes/reports-data.php (loaded by reports-export.php) and
 * includes/simple-pdf.php (loaded below).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/simple-pdf.php';

/* ----------------------------------------------------------------------
   Formatting helpers (same output as the rp_* helpers in report-print.php)
---------------------------------------------------------------------- */
function rpdf_money(float $v, int $dec = 2): string
{
    $neg = $v < 0 && round(abs($v), $dec) > 0;
    return ($neg ? "\u{2212}" : '') . "\u{20B1}" . number_format(abs($v), $dec);
}

function rpdf_int($v): string
{
    return number_format((int) $v);
}

function rpdf_pct(float $part, float $whole): string
{
    return $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : "\u{2014}";
}

function rpdf_plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/* ----------------------------------------------------------------------
   Layout layer: SimplePDF + the print page's building blocks.
   Measurements are the print stylesheet's px/pt values converted to
   points (1px = 0.75pt) and line-height 1.45.
---------------------------------------------------------------------- */
class ReportPDF extends SimplePDF
{
    // colours from report-print.php
    private const INK     = '#111111';
    private const GREY_44 = '#444444';
    private const GREY_33 = '#333333';
    private const TH_BG   = '#e3e7eb';
    private const GROUP   = '#f1f3f5';
    private const STRONG  = '#eef1f4';
    private const FLAG    = '#fff7e6';
    private const NOTE_BG = '#f8f9fa';
    private const RULE    = '#c9ced4';
    private const BOX     = '#888888';

    private const LH      = 1.45;     // line-height multiplier
    private const TD_SIZE = 9.5;
    private const TH_SIZE = 8.5;
    private const PAD_Y   = 3.75;     // 5px
    private const PAD_X   = 6.0;      // 8px
    private const H2_H    = 24.2;     // h2 line + padding + border + margin

    private function upper(string $s): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }

    /* ------------------------------ letterhead ----------------------- */
    public function letterhead(array $property, string $title, string $periodLabel, string $rangeNice,
                               string $generated, string $preparedBy, string $periodNote): void
    {
        $cx = ($this->left() + $this->right()) / 2;
        $W  = $this->contentWidth();

        // property name
        $lh = 17 * self::LH;
        $this->text($cx, $this->y + $lh / 2 + 0.36 * 17, $property['name'], 17, 'b', self::INK, 'c');
        $this->y += $lh;

        // address · phone · email
        $bits = array_values(array_filter([$property['address'], $property['phone'], $property['email']], function ($v) {
            return $v !== '';
        }));
        if ($bits) {
            $this->y += 1.5;
            $lh = 9 * self::LH;
            foreach ($this->wrap(implode('  ·  ', $bits), $W, 9) as $ln) {
                $this->text($cx, $this->y + $lh / 2 + 0.36 * 9, $ln, 9, 'r', self::GREY_44, 'c');
                $this->y += $lh;
            }
        }

        // 2px rule under the masthead
        $this->y += 6;
        $this->hline($this->left(), $this->right(), $this->y + 0.75, 1.5, self::INK);
        $this->y += 1.5;

        // report title
        $this->y += 9;
        $lh = 15 * self::LH;
        $this->text($cx, $this->y + $lh / 2 + 0.36 * 15, $this->upper($title), 15, 'b', self::INK, 'c', 0.9);
        $this->y += $lh + 6;

        // Period / Printed / Prepared by
        $lh    = self::TD_SIZE * self::LH;
        $rowH  = $lh + 3;
        $valX  = $this->left() + 79.37;   // 28mm label column
        $rows  = [['Period', $periodLabel, ($periodLabel !== $rangeNice) ? ' (' . $rangeNice . ')' : ''],
                  ['Printed', $generated, null]];
        if ($preparedBy !== '') {
            $rows[] = ['Prepared by', $preparedBy, null];
        }
        foreach ($rows as $i => $r) {
            $base = $this->y + 1.5 + $lh / 2 + 0.36 * self::TD_SIZE;
            $this->text($this->left(), $base, $r[0], self::TD_SIZE, 'b', self::GREY_44);
            if ($i === 0) {
                $this->text($valX, $base, $r[1], self::TD_SIZE, 'b', self::INK);
                if ($r[2] !== '') {
                    $this->text($valX + $this->textW($r[1], self::TD_SIZE, 'b'), $base, $r[2], self::TD_SIZE, 'r', self::INK);
                }
            } else {
                $this->text($valX, $base, $r[1], self::TD_SIZE, 'r', self::INK);
            }
            $this->y += $rowH;
        }
        $this->y += 3;

        if ($periodNote !== '') {
            $this->y += 1.5;
            $lh = 9 * self::LH;
            foreach ($this->wrap($periodNote, $W, 9, 'i') as $ln) {
                $this->text($this->left(), $this->y + $lh / 2 + 0.36 * 9, $ln, 9, 'i', self::GREY_44);
                $this->y += $lh;
            }
        }
    }

    /* ------------------------------ sections ------------------------- */

    /**
     * Section heading (h2 with rule). $keepH is how much content must fit
     * below it, otherwise heading + content move to the next page together
     * (print: h2 break-after:avoid, .block break-inside:avoid).
     */
    public function sectionStart(string $title, float $keepH): void
    {
        $newPage = $this->ensure(12 + self::H2_H + $keepH);
        if (!$newPage) {
            $this->y += 12;                       // section margin-top: 16px
        }
        $lh = 11.5 * self::LH;
        $this->text($this->left(), $this->y + $lh / 2 + 0.36 * 11.5, $title, 11.5, 'b', self::INK);
        $this->hline($this->left(), $this->right(), $this->y + $lh + 2.25 + 0.375, 0.75, self::INK);
        $this->y += self::H2_H;
    }

    /** Height of a lead / rich paragraph (9.5pt, 6px bottom margin). */
    public function leadHeight($segs): float
    {
        $segs = is_string($segs) ? [[$segs, 'r']] : $segs;
        $n = count($this->wrapRich($segs, $this->contentWidth(), 9.5));
        return $n * 9.5 * self::LH + 4.5;
    }

    public function lead($segs): void
    {
        $segs  = is_string($segs) ? [[$segs, 'r']] : $segs;
        $lines = $this->wrapRich($segs, $this->contentWidth(), 9.5);
        $lh    = 9.5 * self::LH;
        $this->drawRich($this->left(), $this->y, $lines, $lh, 9.5, self::GREY_33);
        $this->y += count($lines) * $lh + 4.5;
    }

    public function emptyHeight(): float
    {
        return 4.5 + 15 + 10 * self::LH + 4.5;
    }

    /** Dashed "nothing to show" box. */
    public function emptyBox(string $msg): void
    {
        $this->y += 4.5;
        $h = 15 + 10 * self::LH;
        $this->rect($this->left(), $this->y, $this->contentWidth(), $h, null, self::BOX, 0.75, true);
        $this->text(($this->left() + $this->right()) / 2, $this->y + $h / 2 + 0.36 * 10, $msg, 10, 'i', self::GREY_44, 'c');
        $this->y += $h + 4.5;
    }

    /* ------------------------------ KPI boxes ------------------------ */

    /** @param array $items [[label, value, sub, strong(bool)], ...] */
    private function kpiLayout(array $items): array
    {
        $n     = max(1, count($items));
        $cellW = $this->contentWidth() / $n;
        $inner = $cellW - 15;
        $cells = [];
        $maxH  = 0.0;
        foreach ($items as $it) {
            $vSize = 15.0;
            while ($vSize > 8 && $this->textW($it[1], $vSize, 'b') > $inner) {
                $vSize -= 0.5;
            }
            $sub = $this->wrap((string) $it[2], $inner, 8.5);
            $h   = 6 + 8.5 * self::LH + 0.75 + 15 * self::LH + 0.75 + count($sub) * 8.5 * self::LH + 6;
            $cells[] = ['label' => $this->upper($it[0]), 'value' => $it[1], 'vSize' => $vSize,
                        'sub' => $sub, 'strong' => !empty($it[3])];
            $maxH = max($maxH, $h);
        }
        return [$cellW, $cells, $maxH + 1.5];     // +border
    }

    public function kpiHeight(array $items): float
    {
        return $this->kpiLayout($items)[2];
    }

    public function kpis(array $items): void
    {
        list($cellW, $cells, $h) = $this->kpiLayout($items);
        $x0 = $this->left();
        $y0 = $this->y;
        foreach ($cells as $i => $c) {
            if ($c['strong']) {
                $this->rect($x0 + $i * $cellW, $y0, $cellW, $h, self::STRONG);
            }
        }
        $this->rect($x0, $y0, $this->contentWidth(), $h, null, self::INK, 1.125);
        for ($i = 1; $i < count($cells); $i++) {
            $this->vline($x0 + $i * $cellW, $y0, $y0 + $h, 0.75, self::INK);
        }
        foreach ($cells as $i => $c) {
            $tx = $x0 + $i * $cellW + 7.5;
            $ty = $y0 + 0.75 + 6;
            $lh = 8.5 * self::LH;
            $this->text($tx, $ty + $lh / 2 + 0.36 * 8.5, $c['label'], 8.5, 'b', self::GREY_33, 'l', 0.34);
            $ty += $lh + 0.75;
            $vh = 15 * self::LH;
            $this->text($tx, $ty + $vh / 2 + 0.36 * $c['vSize'], $c['value'], $c['vSize'], 'b', self::INK);
            $ty += $vh + 0.75;
            foreach ($c['sub'] as $ln) {
                $this->text($tx, $ty + $lh / 2 + 0.36 * 8.5, $ln, 8.5, 'r', self::GREY_44);
                $ty += $lh;
            }
        }
        $this->y += $h;
    }

    /* ------------------------------ tables --------------------------- */

    /**
     * Resolves column widths. A column is ['w' => points] (fixed) or
     * ['f' => weight] (shares the leftover width); 'a' is 'l' or 'r'.
     */
    private function colWidths(array $cols): array
    {
        $fixed  = 0.0;
        $weight = 0.0;
        foreach ($cols as $c) {
            if (isset($c['w'])) {
                $fixed += $c['w'];
            } else {
                $weight += $c['f'] ?? 1;
            }
        }
        $left = max(0.0, $this->contentWidth() - $fixed);
        $out  = [];
        foreach ($cols as $c) {
            $out[] = isset($c['w']) ? (float) $c['w'] : $left * (($c['f'] ?? 1) / max(0.0001, $weight));
        }
        return $out;
    }

    /** Lays out one row (cells may span columns). Returns geometry + lines. */
    private function rowLayout(array $cols, array $w, array $row, bool $isHead): array
    {
        $type   = $row['t'] ?? 'body';
        $size   = $isHead ? self::TH_SIZE : self::TD_SIZE;
        $lh     = $size * self::LH;
        $bold   = $isHead || $type === 'group' || $type === 'total';
        $cells  = [];
        $maxN   = 1;
        $ci     = 0;
        foreach ($row['c'] as $cell) {
            if (!is_array($cell)) {
                $cell = ['t' => (string) $cell];
            }
            $span = $cell['span'] ?? 1;
            $cw   = 0.0;
            for ($k = 0; $k < $span; $k++) {
                $cw += $w[$ci + $k] ?? 0;
            }
            $align = $cols[$ci]['a'] ?? 'l';
            $padL  = self::PAD_X + (($type === 'sub' && $ci === 0) ? 10.5 : 0);
            $style = ($bold || !empty($cell['b'])) ? 'b' : 'r';
            $text  = (string) ($cell['t'] ?? '');
            if ($align === 'r') {
                $lines = [$text];                 // numbers never wrap (nowrap)
            } else {
                $lines = $this->wrap($text, max(10.0, $cw - $padL - self::PAD_X), $size, $style);
            }
            $maxN    = max($maxN, count($lines));
            $x       = 0.0;
            for ($k = 0; $k < $ci; $k++) {
                $x += $w[$k];
            }
            $cells[] = ['x' => $x, 'w' => $cw, 'lines' => $lines, 'style' => $style, 'align' => $align, 'padL' => $padL];
            $ci += $span;
        }
        $border = ($isHead || $type === 'total') ? 2.25 : 0.75;
        return ['h' => 2 * self::PAD_Y + $maxN * $lh + $border, 'cells' => $cells, 'type' => $type,
                'size' => $size, 'lh' => $lh, 'head' => $isHead];
    }

    private function drawRow(array $L): void
    {
        $x0 = $this->left();
        $y0 = $this->y;
        $h  = $L['h'];
        $bg = null;
        if ($L['head'] || $L['type'] === 'total') {
            $bg = self::TH_BG;
        } elseif ($L['type'] === 'group') {
            $bg = self::GROUP;
        } elseif ($L['type'] === 'flag') {
            $bg = self::FLAG;
        }
        if ($bg !== null) {
            $this->rect($x0, $y0, $this->contentWidth(), $h, $bg);
        }
        foreach ($L['cells'] as $c) {
            foreach ($c['lines'] as $i => $ln) {
                $base = $y0 + ($L['head'] || $L['type'] === 'total' ? 1.125 : 0) + self::PAD_Y
                      + $i * $L['lh'] + $L['lh'] / 2 + 0.36 * $L['size'];
                if ($c['align'] === 'r') {
                    $this->text($x0 + $c['x'] + $c['w'] - self::PAD_X, $base, $ln, $L['size'], $c['style'], self::INK, 'r');
                } else {
                    $this->text($x0 + $c['x'] + $c['padL'], $base, $ln, $L['size'], $c['style'], self::INK, 'l');
                }
            }
        }
        if ($L['head'] || $L['type'] === 'total') {
            $this->hline($x0, $this->right(), $y0 + 0.5625, 1.125, self::INK);
            $this->hline($x0, $this->right(), $y0 + $h - 0.5625, 1.125, self::INK);
        } else {
            $this->hline($x0, $this->right(), $y0 + $h - 0.375, 0.75, self::RULE);
        }
        $this->y += $h;
    }

    /** Whole table height (for keep-together blocks). */
    public function tableHeight(array $cols, array $head, array $rows, array $foot = []): float
    {
        $w = $this->colWidths($cols);
        $h = $this->rowLayout($cols, $w, ['c' => $head, 't' => 'head'], true)['h'];
        foreach (array_merge($rows, $foot) as $r) {
            $h += $this->rowLayout($cols, $w, $r, false)['h'];
        }
        return $h;
    }

    /** Header + first row: the least that may sit at the bottom of a page. */
    public function tableKeep(array $cols, array $head, array $rows, array $foot = []): float
    {
        $w   = $this->colWidths($cols);
        $h   = $this->rowLayout($cols, $w, ['c' => $head, 't' => 'head'], true)['h'];
        $all = array_merge($rows, $foot);
        if ($all) {
            $h += $this->rowLayout($cols, $w, $all[0], false)['h'];
        }
        return $h;
    }

    /** Draws a table; the header row repeats on every page it spans. */
    public function table(array $cols, array $head, array $rows, array $foot = []): void
    {
        $w     = $this->colWidths($cols);
        $headL = $this->rowLayout($cols, $w, ['c' => $head, 't' => 'head'], true);
        if ($headL['h'] > $this->remaining() && $this->y > $this->pageTop() + 0.5) {
            $this->addPage();
        }
        $this->drawRow($headL);
        foreach (array_merge($rows, $foot) as $r) {
            $L = $this->rowLayout($cols, $w, $r, false);
            if ($L['h'] > $this->remaining()) {
                $this->addPage();
                $this->drawRow($headL);
            }
            $this->drawRow($L);
        }
    }

    /* ------------------------------ notes + footer ------------------- */

    private function notesLayout(array $items): array
    {
        $W      = $this->contentWidth();
        $textW  = $W - 2 * 9 - 12 - 1.5;     // box padding, ul indent, border
        $lh     = 9 * self::LH;
        $laid   = [];
        $h      = 6 + 0.75 + 10 * self::LH + 3;
        foreach ($items as $segs) {
            $lines  = $this->wrapRich($segs, $textW, 9);
            $laid[] = $lines;
            $h     += count($lines) * $lh + 3;
        }
        $h += 3;
        return [$laid, $h];
    }

    public function notesHeight(array $items): float
    {
        return $this->notesLayout($items)[1];
    }

    /** The grey "How these figures are calculated" box. */
    public function notes(string $heading, array $items): void
    {
        list($laid, $h) = $this->notesLayout($items);
        $newPage = $this->ensure(12 + $h);
        if (!$newPage) {
            $this->y += 12;
        }
        $x = $this->left();
        $this->rect($x, $this->y, $this->contentWidth(), $h, self::NOTE_BG, self::BOX, 0.75);
        $ty = $this->y + 0.75 + 6;
        $hh = 10 * self::LH;
        $this->text($x + 9.75, $ty + $hh / 2 + 0.36 * 10, $heading, 10, 'b', self::INK);
        $ty += $hh + 3;
        $lh = 9 * self::LH;
        foreach ($laid as $lines) {
            $ty += 1.5;
            $this->text($x + 9.75 + 3, $ty + $lh / 2 + 0.36 * 9, "\u{2022}", 9, 'r', self::INK);
            $this->drawRich($x + 9.75 + 12, $ty, $lines, $lh, 9, self::INK);
            $ty += count($lines) * $lh + 1.5;
        }
        $this->y += $h;
    }

    /** Closing line: property · report · period on the left, "Printed ..." on the right. */
    public function docFoot(string $left, string $right): void
    {
        $size  = 8.5;
        $lh    = $size * self::LH;
        $W     = $this->contentWidth();
        $lw    = $this->textW($left, $size);
        $rw    = $this->textW($right, $size);
        $stack = ($lw + $rw + 12) > $W;
        $h     = 18 * 0.75 + 0.75 + 4.5 + ($stack ? 2 : 1) * $lh;
        $newPage = $this->ensure($h);
        if ($newPage) {
            $h -= 13.5;
        } else {
            $this->y += 13.5;
        }
        $this->hline($this->left(), $this->right(), $this->y + 0.375, 0.75, self::INK);
        $ty = $this->y + 0.75 + 4.5;
        $this->text($this->left(), $ty + $lh / 2 + 0.36 * $size, $left, $size, 'r', self::GREY_33);
        if ($stack) {
            $ty += $lh;
            $this->text($this->left(), $ty + $lh / 2 + 0.36 * $size, $right, $size, 'r', self::GREY_33);
        } else {
            $this->text($this->right(), $ty + $lh / 2 + 0.36 * $size, $right, $size, 'r', self::GREY_33, 'r');
        }
        $this->y += $h;
    }
}

/* ----------------------------------------------------------------------
   StatementPDF — the Financial / Performance "statement" layout
   (US Letter, 1-inch margins, Times). Replays the blocks of the model
   built by rst_build_model() in includes/report-statement.php, using the
   same sizes, spacing and margin-collapsing as that page's stylesheet.
---------------------------------------------------------------------- */
class StatementPDF extends SimplePDF
{
    private const INK    = '#000000';
    private const SHADE  = '#e7e6e6';
    private const LH     = 1.15;      // line-height multiplier
    private const PAD_X  = 5.0;       // table cell padding (pt)
    private const PAD_Y  = 2.5;
    private const TD     = 10.5;      // table font size
    private const H2_H   = 16.55;     // 12pt heading line + 2pt gap + 0.75pt rule

    /** Bottom margin (pt) the previous block left behind; the next block's top margin collapses into it. */
    private float $lastAfter = 0.0;

    public function __construct(string $docTitle = '')
    {
        parent::__construct($docTitle, [
            'family'  => 'serif',
            'pageW'   => 612.0,
            'pageH'   => 792.0,
            'margins' => [72.0, 72.0, 72.0, 72.0],
        ]);
    }

    private function lh(float $size): float
    {
        return $size * self::LH;
    }

    /** Baseline for a line box whose top is $top. */
    private function bl(float $top, float $size): float
    {
        return $top + $this->lh($size) / 2 + 0.36 * $size;
    }

    private function atTop(): bool
    {
        return $this->y <= $this->pageTop() + 0.5;
    }

    /** Applies a collapsing top margin (skipped at the top of a page, like the print stylesheet). */
    private function gap(float $before): void
    {
        if (!$this->atTop()) {
            $this->y += max(0.0, $before - $this->lastAfter);
        }
        $this->lastAfter = 0.0;
    }

    private function newPage(): void
    {
        $this->addPage();
        $this->lastAfter = 0.0;
    }

    /* ------------------------------------------------------------------
       Letterhead + title block
    ------------------------------------------------------------------ */
    private function letterhead(array $m): void
    {
        $cx    = ($this->left() + $this->right()) / 2;
        $lines = $m['headLines'];

        $this->text($cx, $this->bl($this->y, 16), rst_upper($m['property']['name']), 16, 'b', self::INK, 'c');
        $this->y += $this->lh(16);
        if (!$lines) {
            $this->headRule();
        } else {
            $this->y += 2;
        }
        foreach ($lines as $i => $ln) {
            $this->text($cx, $this->bl($this->y, 10), $ln, 10, 'r', self::INK, 'c');
            $this->y += $this->lh(10);
            if ($i === count($lines) - 1) {
                $this->headRule();
            }
        }

        /* report title */
        $this->y += 10;
        $this->text($cx, $this->bl($this->y, 14), $m['title'], 14, 'b', self::INK, 'c');
        $this->y += $this->lh(14) + 1;

        $n = count($m['subLines']);
        foreach ($m['subLines'] as $i => $sl) {
            $this->text($cx, $this->bl($this->y, $sl[1]), $sl[0], $sl[1], $sl[2], self::INK, 'c');
            $this->y += $this->lh($sl[1]);
            $this->y += ($i === $n - 1) ? 10 : 1;
        }
        $this->lastAfter = 10.0;

        /* Period / Printed / Prepared by (performance report) */
        if ($m['meta']) {
            $rowH = $this->lh(self::TD) + 2 * self::PAD_Y;
            $valX = $this->left() + 468 * 0.2137 + self::PAD_X;
            foreach ($m['meta'] as $row) {
                $b = $this->bl($this->y + self::PAD_Y, self::TD);
                $this->text($this->left() + self::PAD_X, $b, $row[0], self::TD, 'b', self::INK);
                $this->text($valX, $b, $row[1], self::TD, 'r', self::INK);
                $this->y += $rowH;
            }
            $this->lastAfter = 0.0;
        }
    }

    /** 6pt padding, 1.5pt rule, 6pt margin — the letterhead's closing line. */
    private function headRule(): void
    {
        $this->y += 6;
        $this->hline($this->left(), $this->right(), $this->y + 0.75, 1.5, self::INK);
        $this->y += 1.5 + 6;
    }

    /* ------------------------------------------------------------------
       Block metrics: [margin-before, height, margin-after]
    ------------------------------------------------------------------ */
    private function metrics(array $b, array $m): array
    {
        $W = $this->contentWidth();
        switch ($b['t']) {
            case 'summary':
                $n = count($this->wrapRich($b['segs'], $W, 11));
                return [(float) $b['before'], $n * $this->lh(11), 6.0];
            case 'h2':
                return [13.0, self::H2_H, 5.0];
            case 'table':
                return [0.0, $this->tableLayout($b)['total'], 0.0];
            case 'italic':
                $n = count($this->wrap($b['text'], $W, 10, 'i'));
                return [4.0, $n * $this->lh(10), 4.0];
            case 'chart':
                $n = count($this->wrap($b['caption'], $W, 10, 'i'));
                return [3.0, $b['chart']['h'] + 3 + $n * $this->lh(10), 4.0];
            case 'notes':
                $h = 0.0;
                foreach ($b['items'] as $segs) {
                    $h += count($this->wrapRich($segs, $W - 18, self::TD)) * $this->lh(self::TD) + 4;
                }
                return [0.0, $h - 4, 4.0];
            case 'signatures':
                return [24.0, 35 + 30 + 16 + 25, 0.0];
        }
        return [0.0, 0.0, 0.0];
    }

    /**
     * Height a run of blocks needs from the current position, margins collapsed exactly as
     * drawBlock() does (the last block's own bottom margin is not counted).
     */
    private function flowHeight(array $blocks, array $m): float
    {
        $h = 0.0;
        $after = $this->lastAfter;
        foreach ($blocks as $b) {
            [$before, $bh, $aft] = $this->metrics($b, $m);
            $h += max(0.0, $before - $after) + $bh;
            $after = $aft;
            $h += $aft;
        }
        return $h - $after;
    }

    /* ------------------------------------------------------------------
       Tables
    ------------------------------------------------------------------ */

    /** Resolves a table block into row geometry. */
    private function tableLayout(array $b): array
    {
        $cols = [];
        foreach ($b['cols'] as $c) { $cols[] = $c['w'] / 20.0; }          // twips -> pt (sums to 468)
        $lh = $this->lh(self::TD);

        $mk = function (array $cells, string $kind, bool $head) use ($b, $cols, $lh) {
            $bold  = $head || in_array($kind, ['label', 'subtotal', 'total'], true);
            $style = $bold ? 'b' : 'r';
            $out = []; $maxN = 1;
            foreach ($cells as $i => $text) {
                $align = $b['cols'][$i]['a'];
                $padL  = self::PAD_X + (($kind === 'indent' && $i === 0) ? 18.0 : 0.0);
                $lines = ($align === 'r') ? [(string) $text]
                       : $this->wrap((string) $text, max(10.0, $cols[$i] - $padL - self::PAD_X), self::TD, $style);
                $maxN  = max($maxN, count($lines));
                $out[] = ['lines' => $lines, 'a' => $align, 'padL' => $padL, 'w' => $cols[$i]];
            }
            $extraTop = ($head || in_array($kind, ['subtotal', 'total'], true)) ? 0.5 : 0.0;
            $extraBot = $head ? 0.5 : ($kind === 'total' ? 2.25 : 0.0);
            return ['cells' => $out, 'kind' => $head ? 'head' : $kind, 'style' => $style,
                    'top' => $extraTop, 'bot' => $extraBot,
                    'h' => $maxN * $lh + 2 * self::PAD_Y + $extraTop + $extraBot];
        };

        $head = $mk($b['head'], 'head', true);
        $rows = [];
        $total = $head['h'];
        foreach ($b['rows'] as $r) {
            $L = $mk($r['c'], $r['k'], false);
            if ($r['k'] === 'blank') { $L['cells'] = []; }
            $rows[] = $L;
            $total += $L['h'];
        }
        return ['head' => $head, 'rows' => $rows, 'total' => $total];
    }

    private function drawRow(array $L): void
    {
        $x0 = $this->left();
        $y0 = $this->y;
        $lh = $this->lh(self::TD);
        if ($L['kind'] === 'head') {
            $this->rect($x0, $y0, $this->contentWidth(), $L['h'], self::SHADE);
        }
        $x = $x0;
        foreach ($L['cells'] as $c) {
            foreach ($c['lines'] as $i => $ln) {
                $base = $this->bl($y0 + $L['top'] + self::PAD_Y + $i * $lh, self::TD);
                if ($c['a'] === 'r') {
                    $this->text($x + $c['w'] - self::PAD_X, $base, $ln, self::TD, $L['style'], self::INK, 'r');
                } elseif ($c['a'] === 'c') {
                    $this->text($x + $c['w'] / 2, $base, $ln, self::TD, $L['style'], self::INK, 'c');
                } else {
                    $this->text($x + $c['padL'], $base, $ln, self::TD, $L['style'], self::INK, 'l');
                }
            }
            $x += $c['w'];
        }
        if ($L['kind'] === 'head') {
            $this->hline($x0, $this->right(), $y0 + 0.25, 0.5, self::INK);
            $this->hline($x0, $this->right(), $y0 + $L['h'] - 0.25, 0.5, self::INK);
        } elseif ($L['kind'] === 'subtotal' || $L['kind'] === 'total') {
            $this->hline($x0, $this->right(), $y0 + 0.25, 0.5, self::INK);
            if ($L['kind'] === 'total') {                     // accounting double underline
                $this->hline($x0, $this->right(), $y0 + $L['h'] - 2.0, 0.75, self::INK);
                $this->hline($x0, $this->right(), $y0 + $L['h'] - 0.375, 0.75, self::INK);
            }
        }
        $this->y += $L['h'];
    }

    private function drawTable(array $b): void
    {
        $T = $this->tableLayout($b);
        if ($T['head']['h'] + ($T['rows'] ? $T['rows'][0]['h'] : 0) > $this->remaining() && !$this->atTop()) {
            $this->newPage();
        }
        $this->drawRow($T['head']);
        foreach ($T['rows'] as $r) {
            if ($r['h'] > $this->remaining()) {
                $this->newPage();
                $this->drawRow($T['head']);           // header row repeats on every page
            }
            $this->drawRow($r);
        }
    }

    /* ------------------------------------------------------------------
       Blocks
    ------------------------------------------------------------------ */
    private function drawBlock(array $b, array $m): void
    {
        $W = $this->contentWidth();
        [$before, $h, $after] = $this->metrics($b, $m);

        /* Anything that cannot be split moves to the next page as a whole. */
        if (in_array($b['t'], ['summary', 'italic', 'chart', 'signatures'], true)) {
            if ($this->y + max(0.0, $before - $this->lastAfter) + $h > $this->pageBottom() && !$this->atTop()) {
                $this->newPage();
            }
        }
        $this->gap($before);

        switch ($b['t']) {
            case 'summary':
                $lines = $this->wrapRich($b['segs'], $W, 11);
                $this->drawRichJustified($this->left(), $this->y, $lines, $this->lh(11), 11, $W, self::INK);
                break;

            case 'h2':
                $this->text($this->left(), $this->bl($this->y, 12), $b['text'], 12, 'b', self::INK);
                $this->hline($this->left(), $this->right(), $this->y + $this->lh(12) + 2 + 0.375, 0.75, self::INK);
                break;

            case 'table':
                $this->drawTable($b);
                $h = 0.0;                              // drawTable advanced y itself
                break;

            case 'italic':
                $y = $this->y;
                foreach ($this->wrap($b['text'], $W, 10, 'i') as $ln) {
                    $this->text($this->left(), $this->bl($y, 10), $ln, 10, 'i', self::INK);
                    $y += $this->lh(10);
                }
                break;

            case 'chart':
                $this->drawChart($b['chart'], $this->left() + ($W - $b['chart']['w']) / 2, $this->y);
                $y = $this->y + $b['chart']['h'] + 3;
                $cx = ($this->left() + $this->right()) / 2;
                foreach ($this->wrap($b['caption'], $W, 10, 'i') as $ln) {
                    $this->text($cx, $this->bl($y, 10), $ln, 10, 'i', self::INK, 'c');
                    $y += $this->lh(10);
                }
                break;

            case 'notes':
                $this->drawNotes($b);
                $h = 0.0;                              // drawNotes advanced y itself
                break;

            case 'signatures':
                $this->drawSignatures($m);
                break;
        }
        $this->y += $h + $after;                       // bottom margin; the next block's top margin collapses into it
        $this->lastAfter = $after;
    }

    private function drawNotes(array $b): void
    {
        $W  = $this->contentWidth();
        $lh = $this->lh(self::TD);
        $n  = count($b['items']);
        foreach ($b['items'] as $i => $segs) {
            $lines = $this->wrapRich($segs, $W - 18, self::TD);
            $h = count($lines) * $lh;
            if ($this->y + $h > $this->pageBottom() && !$this->atTop()) {
                $this->newPage();
            }
            $this->text($this->left(), $this->bl($this->y, self::TD), ($i + 1) . '.', self::TD, 'r', self::INK);
            $this->drawRichJustified($this->left() + 18, $this->y, $lines, $lh, self::TD, $W - 18, self::INK);
            $this->y += $h + (($i < $n - 1) ? 4 : 0);
        }
    }

    private function drawSignatures(array $m): void
    {
        $w = [150.0, 16.5, 150.0, 16.5, 135.0];
        $x = [];
        $acc = $this->left();
        foreach ($w as $cw) { $x[] = $acc; $acc += $cw; }
        $name = 'Name';
        $y = $this->y;
        $lh = $this->lh(10);

        foreach ([[0, 'Prepared by:'], [2, 'Reviewed by:'], [4, 'Approved by:']] as [$k, $label]) {
            $this->text($x[$k] + $w[$k] / 2, $this->bl($y + 2, 10), $label, 10, 'b', self::INK, 'c');
        }
        $y += 35 + 30;                                                     // label row + blank signing space
        foreach ([[0, $name], [2, 'Name / Signature'], [4, 'Owner / Manager']] as [$k, $label]) {
            $this->hline($x[$k], $x[$k] + $w[$k], $y + 0.25, 0.5, self::INK);
            $this->text($x[$k] + $w[$k] / 2, $this->bl($y + 0.5 + 2, 10), $label, 10, 'r', self::INK, 'c');
        }
        $y += 16;
        foreach ([0, 2, 4] as $k) {
            $this->text($x[$k] + $w[$k] / 2, $this->bl($y + 2, 10), 'Date: ______________', 10, 'r', self::INK, 'c');
        }
    }

    /** Replays the chart primitives of the model at ($ox, $oy). */
    private function drawChart(array $chart, float $ox, float $oy): void
    {
        $anchor = ['start' => 'l', 'middle' => 'c', 'end' => 'r'];
        foreach ($chart['prims'] as $p) {
            switch ($p[0]) {
                case 'line':
                    $this->line($ox + $p[1], $oy + $p[2], $ox + $p[3], $oy + $p[4], $p[5], $p[6]);
                    break;
                case 'poly':
                    $this->polyline(array_map(function ($q) use ($ox, $oy) { return [$ox + $q[0], $oy + $q[1]]; }, $p[1]), $p[2], $p[3]);
                    break;
                case 'fill':
                    $this->polygon(array_map(function ($q) use ($ox, $oy) { return [$ox + $q[0], $oy + $q[1]]; }, $p[1]), $p[2]);
                    break;
                case 'circle':
                    $this->circle($ox + $p[1], $oy + $p[2], $p[3], $p[4]);
                    break;
                case 'text':
                    if ((float) $p[8] != 0.0) {
                        $this->textRot($ox + $p[1], $oy + $p[2], $p[3], $p[4], $p[5], $p[7], (float) $p[8], 'c');
                    } else {
                        $this->text($ox + $p[1], $oy + $p[2], $p[3], $p[4], $p[5], $p[7], $anchor[$p[6]]);
                    }
                    break;
            }
        }
    }

    /* ------------------------------------------------------------------
       Entry point
    ------------------------------------------------------------------ */
    public function renderModel(array $m): void
    {
        $this->letterhead($m);

        $blocks = $m['blocks'];
        for ($i = 0, $n = count($blocks); $i < $n; $i++) {
            $b = $blocks[$i];
            if ($b['t'] !== 'h2') {
                $this->drawBlock($b, $m);
                continue;
            }
            /* A heading travels with the table / figure under it (and a table's trailing remark). */
            $group = [$b];
            $rowCount = 0;
            if (isset($blocks[$i + 1]) && !in_array($blocks[$i + 1]['t'], ['h2', 'signatures'], true)) {
                $group[] = $blocks[++$i];
                if ($group[1]['t'] === 'table') { $rowCount = count($group[1]['rows']); }
                if ($group[1]['t'] === 'table' && isset($blocks[$i + 1]) && $blocks[$i + 1]['t'] === 'italic') {
                    $group[] = $blocks[++$i];
                }
            }
            $need = ($rowCount <= 24 || $group[1]['t'] !== 'table')
                ? $this->flowHeight($group, $m)
                : self::H2_H + 13 + $this->tableKeep($group[1]);
            if ($this->y + $need > $this->pageBottom() && !$this->atTop()) {
                $this->newPage();
            }
            foreach ($group as $g) { $this->drawBlock($g, $m); }
        }

        $footer = $m['footerText'];
        $this->onEachPage(function (SimplePDF $pdf, int $no, int $count) use ($footer) {
            $size  = 9;
            $ruleY = $pdf->pageH - 72 + 27;                   // 27pt below the text area, like the print footer
            $base  = $pdf->pageH - 30 - 10.35 + 10.35 / 2 + 0.36 * $size;
            $pdf->hline($pdf->left(), $pdf->right(), $ruleY, 0.5, '#000000');
            $pdf->text($pdf->left(), $base, $footer, $size, 'r', '#000000', 'l');
            $pdf->text($pdf->right(), $base, 'Page ' . $no . ' of ' . $count, $size, 'r', '#000000', 'r');
        });
    }

    /** Heading's companion: header row + first body row (the least that may sit at the foot of a page). */
    private function tableKeep(array $b): float
    {
        $T = $this->tableLayout($b);
        return $T['head']['h'] + ($T['rows'] ? $T['rows'][0]['h'] : 0.0);
    }
}

/* ----------------------------------------------------------------------
   Report bodies — one per report, mirroring rp_body_*() in report-print.php
---------------------------------------------------------------------- */

function rpdf_kpi_section(ReportPDF $pdf, array $items): void
{
    $pdf->sectionStart('Summary', $pdf->kpiHeight($items));
    $pdf->kpis($items);
}

function rpdf_body_financial(ReportPDF $pdf, PDO $pdo, array $period): void
{
    $d       = reports_financial_data($pdo, $period['start'], $period['end']);
    $revenue = (float) $d['revenue'];
    $expense = (float) $d['expenses'];
    $profit  = (float) $d['profit'];

    $entries = (int) array_sum(array_column($d['expensesByCategory'], 'entries'));
    rpdf_kpi_section($pdf, [
        ['Total Revenue',  rpdf_money($revenue), rpdf_plural((int) $d['bookingCount'], 'booking'), false],
        ['Total Expenses', rpdf_money($expense), rpdf_plural($entries, 'expense entry', 'expense entries'), false],
        [$profit < 0 ? 'Net Loss' : 'Net Profit', rpdf_money($profit), "Revenue \u{2212} Expenses", true],
    ]);

    /* ---- Revenue by Accommodation ---- */
    $groups = [];
    foreach ($d['byAccommodation'] as $r) {
        $type = trim((string) $r['type']) !== '' ? (string) $r['type'] : 'Other';
        if (!isset($groups[$type])) {
            $groups[$type] = ['label' => rpt_type_label($type), 'bookings' => 0, 'amount' => 0.0, 'rows' => []];
        }
        $groups[$type]['bookings'] += (int) $r['bookings'];
        $groups[$type]['amount']   += (float) $r['amount'];
        $groups[$type]['rows'][]    = $r;
    }
    uasort($groups, function ($a, $b) {
        return $b['amount'] <=> $a['amount'];
    });
    $listedAmount   = array_sum(array_column($groups, 'amount'));
    $listedBookings = array_sum(array_column($groups, 'bookings'));
    $gapAmount      = round($revenue - $listedAmount, 2);
    $gapBookings    = (int) $d['bookingCount'] - (int) $listedBookings;

    if ($revenue <= 0 && empty($groups)) {
        $pdf->sectionStart('Revenue by Accommodation', $pdf->emptyHeight());
        $pdf->emptyBox('No revenue recorded for this period.');
    } else {
        $cols = [['f' => 1, 'a' => 'l'], ['w' => 70, 'a' => 'r'], ['w' => 96, 'a' => 'r'], ['w' => 96, 'a' => 'r']];
        $head = ['Accommodation', 'Bookings', 'Revenue', '% of Revenue'];
        $rows = [];
        foreach ($groups as $g) {
            $rows[] = ['t' => 'group', 'c' => [$g['label'], rpdf_int($g['bookings']), rpdf_money($g['amount']), rpdf_pct($g['amount'], $revenue)]];
            foreach ($g['rows'] as $r) {
                $rows[] = ['t' => 'sub', 'c' => [(string) $r['label'], rpdf_int($r['bookings']), rpdf_money((float) $r['amount']), rpdf_pct((float) $r['amount'], $revenue)]];
            }
        }
        if (abs($gapAmount) >= 0.01 || $gapBookings !== 0) {
            $rows[] = ['t' => 'group', 'c' => ['Other / accommodation no longer listed', rpdf_int(max(0, $gapBookings)), rpdf_money($gapAmount), rpdf_pct($gapAmount, $revenue)]];
        }
        $foot = [['t' => 'total', 'c' => ['Total Revenue', rpdf_int($d['bookingCount']), rpdf_money($revenue), $revenue > 0 ? '100.0%' : "\u{2014}"]]];
        $pdf->sectionStart('Revenue by Accommodation', $pdf->tableKeep($cols, $head, $rows, $foot));
        $pdf->table($cols, $head, $rows, $foot);
    }

    /* ---- Payment Collection (kept together) ---- */
    $lead1 = 'Of the revenue above, how much has actually been received.';
    $lead2 = [
        ['Booking payment status: ', 'r'],
        [rpdf_int($d['fullyPaidCount']), 'b'], [' fully paid · ', 'r'],
        [rpdf_int($d['partialCount']), 'b'],   [' partially paid · ', 'r'],
        [rpdf_int($d['unpaidCount']), 'b'],    [' unpaid.', 'r'],
    ];
    $cols = [['f' => 1, 'a' => 'l'], ['w' => 70, 'a' => 'r'], ['w' => 96, 'a' => 'r']];
    $head = ['', 'Bookings', 'Amount'];
    $rows = [
        ['t' => 'body',  'c' => ['Total billed (Total Revenue)', rpdf_int($d['bookingCount']), rpdf_money($revenue)]],
        ['t' => 'body',  'c' => ['Collected (payments received)', rpdf_int($d['paidCount']), rpdf_money((float) $d['collected'])]],
        ['t' => 'total', 'c' => ['Outstanding balance (still to collect)', rpdf_int($d['pendingCount']), rpdf_money((float) $d['outstanding'])]],
    ];
    $pdf->sectionStart('Payment Collection', $pdf->leadHeight($lead1) + $pdf->tableHeight($cols, $head, $rows) + $pdf->leadHeight($lead2));
    $pdf->lead($lead1);
    $pdf->table($cols, $head, $rows);
    $pdf->lead($lead2);

    /* ---- Expenses by Category ---- */
    if (empty($d['expensesByCategory'])) {
        $pdf->sectionStart('Expenses by Category', $pdf->emptyHeight());
        $pdf->emptyBox('No expenses recorded for this period.');
    } else {
        $cols = [['f' => 1, 'a' => 'l'], ['w' => 70, 'a' => 'r'], ['w' => 96, 'a' => 'r'], ['w' => 96, 'a' => 'r']];
        $head = ['Category', 'Entries', 'Amount', '% of Expenses'];
        $rows = [];
        foreach ($d['expensesByCategory'] as $r) {
            $rows[] = ['t' => 'body', 'c' => [(string) $r['label'], rpdf_int($r['entries']), rpdf_money((float) $r['amount']), rpdf_pct((float) $r['amount'], $expense)]];
        }
        $foot = [['t' => 'total', 'c' => ['Total Expenses', rpdf_int($entries), rpdf_money($expense), $expense > 0 ? '100.0%' : "\u{2014}"]]];
        $pdf->sectionStart('Expenses by Category', $pdf->tableKeep($cols, $head, $rows, $foot));
        $pdf->table($cols, $head, $rows, $foot);
    }

    $pdf->notes('How these figures are calculated', [
        [['Revenue', 'b'], [' is the billed amount (after discounts and extra charges) of ', 'r'], ['Confirmed', 'i'], [' and ', 'r'], ['Completed', 'i'], [' bookings whose ', 'r'], ['check-in date', 'i'], [' falls in this period. Cancelled and Pending bookings are not counted.', 'r']],
        [['Collected', 'b'], [' is the money recorded as received on those same bookings, including partial payments. ', 'r'], ['Outstanding', 'b'], [' is what is still owed on them.', 'r']],
        [['Expenses', 'b'], [' are the expenses recorded with a date inside this period. ', 'r'], ['Net profit', 'b'], [" = Revenue \u{2212} Expenses.", 'r']],
    ]);
}

function rpdf_body_performance(ReportPDF $pdf, PDO $pdo, array $period): void
{
    $d          = reports_performance_data($pdo, $period['start'], $period['end']);
    $unitCounts = $d['unitCounts'];
    $types = array_values(array_filter($d['types'], function ($t) use ($unitCounts) {
        return ($unitCounts[$t] ?? 0) > 0;
    }));

    $ranked = [];
    $seen   = [];
    foreach ($d['rankings'] as $r) {
        $ranked[] = ['type' => $r['label'], 'bookings' => (int) $r['bookings'], 'revenue' => (float) $r['revenue']];
        $seen[$r['label']] = true;
    }
    foreach ($types as $t) {
        if (!isset($seen[$t])) {
            $ranked[] = ['type' => $t, 'bookings' => 0, 'revenue' => 0.0];
        }
    }
    $totalBookings = array_sum(array_column($ranked, 'bookings'));
    $totalRevenue  = array_sum(array_column($ranked, 'revenue'));

    /* ---- Performance Ranking ---- */
    $lead = 'Accommodation types ranked by number of bookings (Confirmed and Completed, check-in within the period).';
    if (empty($ranked)) {
        $pdf->sectionStart('Performance Ranking — ' . $period['label'], $pdf->leadHeight($lead) + $pdf->emptyHeight());
        $pdf->lead($lead);
        $pdf->emptyBox('No accommodations are set up yet.');
    } else {
        $cols = [['w' => 34, 'a' => 'r'], ['f' => 1, 'a' => 'l'], ['w' => 62, 'a' => 'r'], ['w' => 70, 'a' => 'r'], ['w' => 96, 'a' => 'r']];
        $head = ['Rank', 'Accommodation Type', 'Units', 'Bookings', 'Revenue'];
        $rows = [];
        foreach ($ranked as $i => $r) {
            $rows[] = ['t' => 'body', 'c' => [
                $r['bookings'] > 0 ? (string) ($i + 1) : "\u{2014}",
                rpt_type_label($r['type']),
                rpdf_int($unitCounts[$r['type']] ?? 0),
                rpdf_int($r['bookings']),
                rpdf_money($r['revenue']),
            ]];
        }
        $unitTotal = array_sum(array_map(function ($t) use ($unitCounts) {
            return $unitCounts[$t] ?? 0;
        }, array_column($ranked, 'type')));
        $foot = [['t' => 'total', 'c' => ['', 'Total', rpdf_int($unitTotal), rpdf_int($totalBookings), rpdf_money($totalRevenue)]]];
        $pdf->sectionStart('Performance Ranking — ' . $period['label'], $pdf->leadHeight($lead) + $pdf->tableKeep($cols, $head, $rows, $foot));
        $pdf->lead($lead);
        $pdf->table($cols, $head, $rows, $foot);
        if ($totalBookings === 0) {
            $pdf->lead('No bookings were recorded in this period.');
        }
    }

    if (!empty($types)) {
        /* ---- Peak Season by Type (kept together) ---- */
        $lead = 'The longest stretch of months in which a type was busier than its own yearly average.';
        $cols = [['f' => 1, 'a' => 'l'], ['f' => 1.3, 'a' => 'l'], ['w' => 130, 'a' => 'r']];
        $head = ['Accommodation Type', 'Peak Months', 'Avg. Occupancy in Peak'];
        $rows = [];
        foreach ($types as $t) {
            $p      = $d['peak'][$t];
            $noData = ($p['label'] === 'No data');
            $rows[] = ['t' => 'body', 'c' => [rpt_type_label($t), $noData ? 'No bookings this year' : (string) $p['label'], $noData ? "\u{2014}" : (int) $p['pct'] . '%']];
        }
        $pdf->sectionStart('Peak Season by Type — ' . (int) $d['year'], $pdf->leadHeight($lead) + $pdf->tableHeight($cols, $head, $rows));
        $pdf->lead($lead);
        $pdf->table($cols, $head, $rows);

        /* ---- Monthly Occupancy (kept together) ---- */
        $lead       = 'Percentage of available unit-days that were booked, by month.';
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $cols = [['w' => 100, 'a' => 'l']];
        $head = ['Month'];
        foreach ($types as $t) {
            $cols[] = ['f' => 1, 'a' => 'r'];
            $head[] = rpt_type_label($t);
        }
        $rows = [];
        foreach ($monthNames as $mi => $mn) {
            $cells = [$mn];
            foreach ($types as $t) {
                $cells[] = (int) $d['occupancy'][$t][$mi + 1] . '%';
            }
            $rows[] = ['t' => 'body', 'c' => $cells];
        }
        $avg = ['Year average'];
        foreach ($types as $t) {
            $avg[] = number_format(array_sum($d['occupancy'][$t]) / 12, 1) . '%';
        }
        $foot = [['t' => 'total', 'c' => $avg]];
        $pdf->sectionStart('Monthly Occupancy — ' . (int) $d['year'], $pdf->leadHeight($lead) + $pdf->tableHeight($cols, $head, $rows, $foot));
        $pdf->lead($lead);
        $pdf->table($cols, $head, $rows, $foot);
    }

    $pdf->notes('How these figures are calculated', [
        [['Ranking', 'b'], [' counts Confirmed and Completed bookings by check-in date within the selected period; ties are broken by revenue.', 'r']],
        [['Occupancy', 'b'], [' = nights booked ÷ (number of units × days in the month). It always covers the whole calendar year, whatever period is selected above.', 'r']],
        [['The ', 'r'], ['Swimming Pool', 'b'], [' is shared by many guests at once, so its figure counts booking-days and can exceed 100% when several bookings overlap.', 'r']],
    ]);
}

function rpdf_body_guest_insights(ReportPDF $pdf, PDO $pdo, array $period): void
{
    $d = reports_guest_insights_data($pdo, $period['start'], $period['end']);

    rpdf_kpi_section($pdf, [
        ['Total Guests',  rpdf_int($d['totalGuests']),  'Booked in this period', false],
        ['Repeat Guests', rpdf_int($d['repeatGuests']), 'Registered, 2+ bookings overall', false],
        ['Average Guest Rating',
            $d['avgRating'] !== null ? number_format((float) $d['avgRating'], 1) . ' / 5' : "\u{2014}",
            $d['avgRating'] !== null ? 'All-time · ' . rpdf_plural((int) $d['ratingCount'], 'published review') : 'No published reviews yet',
            false],
    ]);

    $lead = 'Registered guests with two or more bookings, most visits first (up to 5).';
    if (empty($d['topGuests'])) {
        $pdf->sectionStart('Top Returning Guests (all-time)', $pdf->leadHeight($lead) + $pdf->emptyHeight());
        $pdf->lead($lead);
        $pdf->emptyBox('No guest has booked more than once yet.');
    } else {
        $cols = [['w' => 34, 'a' => 'r'], ['f' => 1.1, 'a' => 'l'], ['w' => 62, 'a' => 'r'], ['f' => 1.4, 'a' => 'l'], ['f' => 0.9, 'a' => 'l'], ['w' => 58, 'a' => 'r']];
        $head = ['#', 'Guest', 'Total Visits', 'Most Booked Accommodation', 'Country', 'Avg. Rating'];
        $rows = [];
        foreach ($d['topGuests'] as $i => $g) {
            $rows[] = ['t' => 'body', 'c' => [
                (string) ($i + 1),
                ['t' => (string) $g['name'], 'b' => true],
                rpdf_int($g['visits']),
                (string) $g['most_booked'],
                (string) $g['country'],
                $g['rating'] !== null ? number_format((float) $g['rating'], 1) : "\u{2014}",
            ]];
        }
        $pdf->sectionStart('Top Returning Guests (all-time)', $pdf->leadHeight($lead) + $pdf->tableKeep($cols, $head, $rows));
        $pdf->lead($lead);
        $pdf->table($cols, $head, $rows);
    }

    $pdf->notes('How these figures are calculated', [
        [['Total Guests', 'b'], [' counts each guest once if they have a Confirmed, Completed or Pending booking with check-in in this period (walk-in guests are counted by name and email).', 'r']],
        [['Repeat Guests', 'b'], [' are registered guests from that group who have more than one booking overall. Walk-in guests without an account cannot be matched across visits, so they are not included.', 'r']],
        [['Ratings', 'b'], [' use published feedback only, across all dates. Cancelled bookings are never counted. A blank country is shown as Philippines.', 'r']],
    ]);
}

function rpdf_body_inventory(ReportPDF $pdf, PDO $pdo, array $period): void
{
    $d      = reports_inventory_data($pdo, $period['start'], $period['end']);
    $labels = ['out_of_stock' => 'OUT OF STOCK', 'low_stock' => 'Low stock', 'in_stock' => 'In stock'];
    $rank   = ['out_of_stock' => 0, 'low_stock' => 1, 'in_stock' => 2];

    $items = $d['items'];
    $out = 0;
    $low = 0;
    foreach ($items as $k => $it) {
        $items[$k]['_status'] = reports_inv_status($it);
        if ($items[$k]['_status'] === 'out_of_stock') { $out++; }
        if ($items[$k]['_status'] === 'low_stock')    { $low++; }
    }
    usort($items, function ($a, $b) use ($rank) {
        return [$rank[$a['_status']], strtolower($a['name'])] <=> [$rank[$b['_status']], strtolower($b['name'])];
    });
    $needRestock = $out + $low;

    rpdf_kpi_section($pdf, [
        ['Total Items', rpdf_int($d['totalItems']), rpdf_plural((int) $d['totalUnits'], 'unit') . ' on hand', false],
        ['Need Restocking', rpdf_int($needRestock), rpdf_int($out) . ' out of stock · ' . rpdf_int($low) . ' low', $needRestock > 0],
        ['Inventory Value', rpdf_money((float) $d['totalValue']), 'On hand × unit cost', false],
    ]);

    /* ---- Stock Levels ---- */
    $lead = 'Items needing attention are listed first.';
    if (empty($items)) {
        $pdf->sectionStart('Stock Levels', $pdf->leadHeight($lead) + $pdf->emptyHeight());
        $pdf->lead($lead);
        $pdf->emptyBox('No inventory items have been added yet.');
    } else {
        $cols = [['f' => 1.4, 'a' => 'l'], ['f' => 1, 'a' => 'l'], ['w' => 50, 'a' => 'r'], ['w' => 52, 'a' => 'r'], ['w' => 64, 'a' => 'r'], ['w' => 74, 'a' => 'r'], ['w' => 72, 'a' => 'l']];
        $head = ['Item', 'Category', 'On Hand', 'Minimum', 'Unit Cost', 'Value', 'Status'];
        $rows = [];
        foreach ($items as $it) {
            $rows[] = ['t' => $it['_status'] === 'in_stock' ? 'body' : 'flag', 'c' => [
                ['t' => (string) $it['name'], 'b' => true],
                (string) $it['category'],
                rpdf_int($it['quantity']),
                rpdf_int($it['min_stock_level']),
                rpdf_money((float) $it['cost_per_unit']),
                rpdf_money((float) $it['cost_per_unit'] * (int) $it['quantity']),
                ['t' => $labels[$it['_status']], 'b' => true],
            ]];
        }
        $foot = [['t' => 'total', 'c' => [
            ['t' => 'Total (' . rpdf_plural((int) $d['totalItems'], 'item') . ')', 'span' => 2],
            rpdf_int($d['totalUnits']), '', '', rpdf_money((float) $d['totalValue']), '',
        ]]];
        $pdf->sectionStart('Stock Levels', $pdf->leadHeight($lead) + $pdf->tableKeep($cols, $head, $rows, $foot));
        $pdf->lead($lead);
        $pdf->table($cols, $head, $rows, $foot);
    }

    /* ---- Most Used Items (kept together) ---- */
    $title = 'Most Used Items — ' . $period['label'];
    if (empty($d['mostUsed'])) {
        $pdf->sectionStart($title, $pdf->emptyHeight());
        $pdf->emptyBox('No item usage was logged in this period.');
    } else {
        $cols = [['w' => 34, 'a' => 'r'], ['f' => 1, 'a' => 'l'], ['w' => 90, 'a' => 'r'], ['w' => 100, 'a' => 'r']];
        $head = ['#', 'Item', 'Units Used', 'On Hand Now'];
        $rows = [];
        foreach ($d['mostUsed'] as $i => $m) {
            $rows[] = ['t' => 'body', 'c' => [(string) ($i + 1), (string) $m['name'], rpdf_int($m['used']), rpdf_int($m['stock'])]];
        }
        $pdf->sectionStart($title, $pdf->tableHeight($cols, $head, $rows));
        $pdf->table($cols, $head, $rows);
    }

    $pdf->notes('How these figures are calculated', [
        [['Out of stock', 'b'], [' = none on hand. ', 'r'], ['Low stock', 'b'], [" = on hand is at or below the item's minimum level. Both count as “Need Restocking”.", 'r']],
        [['Value', 'b'], [' = units on hand × unit cost. Stock levels are a snapshot taken when this report was printed, not for the period.', 'r']],
        [['Units Used', 'b'], [' is the usage recorded on the Inventory page within the selected period (top 6 items).', 'r']],
    ]);
}

/* ----------------------------------------------------------------------
   Entry point used by reports-export.php
---------------------------------------------------------------------- */
function pdf_render_print_report(PDO $pdo, string $report, array $period, string $filename): void
{
    /* Financial and Performance: formal statement layout (shared model with the print page). */
    if ($report === 'financial' || $report === 'performance') {
        require_once __DIR__ . '/report-statement.php';
        $m   = rst_build_model($pdo, $report, $period);
        $pdf = new StatementPDF($m['reportTitle'] . ' — ' . $period['label'] . ' — ' . $m['property']['name']);
        $pdf->renderModel($m);
        $pdf->output($filename);   // streams the file and exits
    }

    $titles = [
        'financial'      => 'Financial Report',
        'performance'    => 'Performance Report',
        'guest-insights' => 'Guest Insights Report',
        'inventory'      => 'Inventory Report',
    ];
    $reportTitle = $titles[$report];

    /* Letterhead details — same sources as report-print.php. */
    $property = ['name' => "Bien Marko's Pension House", 'address' => '', 'phone' => '', 'email' => ''];
    try {
        $row = $pdo->query('SELECT property_name, contact_email, contact_number, address FROM system_settings WHERE id = 1')->fetch();
        if ($row) {
            if (trim((string) $row['property_name']) !== '') { $property['name'] = trim((string) $row['property_name']); }
            $property['address'] = trim((string) $row['address']);
            $property['phone']   = trim((string) $row['contact_number']);
            $property['email']   = trim((string) $row['contact_email']);
        }
    } catch (\Throwable $e) {
        /* system_settings not created yet — keep defaults */
    }

    $preparedBy = '';
    try {
        $st = $pdo->prepare('SELECT name FROM admins WHERE id = :id');
        $st->execute(['id' => (int) ($_SESSION['auth_user_id'] ?? 0)]);
        $preparedBy = trim((string) $st->fetchColumn());
    } catch (\Throwable $e) {
        /* leave blank */
    }

    $startNice = (new DateTime($period['start']))->format('M j, Y');
    $endNice   = (new DateTime($period['end']))->format('M j, Y');
    $rangeNice = $startNice === $endNice ? $startNice : $startNice . ' – ' . $endNice;
    $generated = date('F j, Y \a\t g:i A');

    $periodNote = '';
    if ($report === 'inventory') {
        $periodNote = 'Stock levels are as of the time of printing; item usage is for the period shown.';
    } elseif ($report === 'guest-insights') {
        $periodNote = 'Guest counts are for the period shown; ratings and returning guests are all-time.';
    } elseif ($report === 'performance') {
        $periodNote = 'Rankings are for the period shown; monthly occupancy covers the full calendar year ' . (int) date('Y', strtotime($period['end'])) . '.';
    }

    $pdf = new ReportPDF($reportTitle . ' — ' . $period['label'] . ' — ' . $property['name']);
    $pdf->letterhead($property, $reportTitle, $period['label'], $rangeNice, $generated, $preparedBy, $periodNote);

    switch ($report) {
        case 'financial':      rpdf_body_financial($pdf, $pdo, $period);      break;
        case 'performance':    rpdf_body_performance($pdf, $pdo, $period);    break;
        case 'guest-insights': rpdf_body_guest_insights($pdf, $pdo, $period); break;
        case 'inventory':      rpdf_body_inventory($pdf, $pdo, $period);      break;
    }

    $pdf->docFoot(
        $property['name'] . ' · ' . $reportTitle . ' · ' . $period['label'],
        'Printed ' . $generated
    );
    $pdf->output($filename);
}