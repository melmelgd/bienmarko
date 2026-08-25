<?php
/**
 * admin/includes/simple-pdf.php
 * -----------------------------------------------------------------------
 * A dependency-free PDF writer used only by the Reports & Analytics
 * "Export → PDF" option. Hand-writes the raw PDF object/xref structure
 * using the built-in base-14 Helvetica font, so it needs no external
 * library (no Composer, no dompdf/mpdf/tcpdf) and works on any stock
 * PHP install.
 *
 * Deliberately minimal: title + a few summary lines + a paginated table
 * with a repeating header row. That's everything a report export needs;
 * it is not a general-purpose PDF library.
 * -----------------------------------------------------------------------
 */

class SimplePDF
{
    /** @var array<int,string> */
    private array $pageIds = [];
    /** @var array<int,string> */
    private array $pageContent = [];
    private int $nextId = 1;

    private float $pageW       = 612; // US Letter, points
    private float $pageH       = 792;
    private float $marginX     = 40;
    private float $marginTop   = 50;
    private float $marginBottom = 40;

    private int $fontRegId;
    private int $fontBoldId;
    private int $curPage;
    private float $cursorY;

    public function __construct()
    {
        $this->fontRegId  = $this->reserveId();
        $this->fontBoldId = $this->reserveId();
        $this->addPage();
    }

    private function reserveId(): int
    {
        return $this->nextId++;
    }

    public function addPage(): void
    {
        $id = $this->reserveId();
        $this->pageIds[] = $id;
        $this->pageContent[$id] = '';
        $this->curPage = $id;
        $this->cursorY = $this->pageH - $this->marginTop;
    }

    /**
     * Converts UTF-8 report text to the single-byte WinAnsiEncoding
     * (CP1252) that PDF string literals need, then escapes the PDF
     * special characters. The peso sign has no CP1252/WinAnsi glyph so
     * it's substituted with "P" first; //TRANSLIT//IGNORE handles any
     * other character the base-14 Helvetica font can't represent.
     */
    private function esc(string $s): string
    {
        $s = str_replace('₱', 'P', $s);
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($converted !== false) {
            $s = $converted;
        }
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        return $s;
    }

    public function text(float $x, float $y, string $str, float $size = 10, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->pageContent[$this->curPage] .= sprintf(
            "BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n",
            $font, $size, $x, $y, $this->esc($str)
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
    {
        $this->pageContent[$this->curPage] .= sprintf(
            "%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $y1, $x2, $y2
        );
    }

    public function pageWidth(): float  { return $this->pageW; }
    public function marginX(): float    { return $this->marginX; }
    public function bottomLimit(): float { return $this->marginBottom; }
    public function topStart(): float   { return $this->pageH - $this->marginTop; }

    /** Streams the finished PDF to the browser and exits. */
    public function output(string $filename): void
    {
        $objs = [];

        $objs[$this->fontRegId]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[$this->fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

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
                . "/Resources << /Font << /F1 {$this->fontRegId} 0 R /F2 {$this->fontBoldId} 0 R >> >> "
                . "/Contents {$contentIds[$pid]} 0 R >>";
        }
        $objs[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($this->pageIds) . ' >>';

        $catalogId = $this->reserveId();
        $objs[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";

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
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: max-age=0');
        echo $pdf;
        exit;
    }
}

/**
 * Lays out a titled report (title, subtitle/date range, optional summary
 * stat lines, then a paginated data table with a repeating header row)
 * onto a SimplePDF and streams it. Shared by every Reports & Analytics
 * PDF export so the page-layout logic lives in one place.
 *
 * @param string   $filename Download filename, e.g. "financial-report.pdf"
 * @param string   $title    Report title, e.g. "Financial Report"
 * @param string   $subtitle e.g. "Bien Marko's Pension House · August 2026"
 * @param string[] $summary  Optional key-stat lines shown above the table
 * @param string[] $headers  Table column headers
 * @param array    $rows     Table rows (arrays of scalar values)
 */
function pdf_render_report(string $filename, string $title, string $subtitle, array $summary, array $headers, array $rows): void
{
    $pdf = new SimplePDF();
    $x = $pdf->marginX();
    $y = $pdf->topStart();

    $pdf->text($x, $y, $title, 16, true);
    $y -= 20;
    $pdf->text($x, $y, $subtitle, 10, false);
    $y -= 10;
    $pdf->line($x, $y, $pdf->pageWidth() - $x, $y);
    $y -= 20;

    foreach ($summary as $line) {
        $pdf->text($x, $y, $line, 11, false);
        $y -= 16;
    }
    if (!empty($summary)) {
        $y -= 8;
    }

    $colCount = max(1, count($headers));
    $tableW   = $pdf->pageWidth() - (2 * $x);
    $colW     = $tableW / $colCount;

    $drawHeaderRow = function () use (&$y, $pdf, $headers, $x, $colW) {
        foreach ($headers as $i => $h) {
            $pdf->text($x + $i * $colW, $y, (string) $h, 9, true);
        }
        $y -= 6;
        $pdf->line($x, $y, $pdf->pageWidth() - $x, $y);
        $y -= 14;
    };

    $drawHeaderRow();

    if (empty($rows)) {
        $pdf->text($x, $y, 'No data available for the selected period.', 9, false);
        $y -= 16;
    }

    foreach ($rows as $row) {
        if ($y < $pdf->bottomLimit() + 20) {
            $pdf->addPage();
            $y = $pdf->topStart();
            $drawHeaderRow();
        }
        foreach (array_values($row) as $i => $val) {
            $pdf->text($x + $i * $colW, $y, (string) $val, 9, false);
        }
        $y -= 16;
    }

    $pdf->output($filename);
}
