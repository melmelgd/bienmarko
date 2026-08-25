<?php
/**
 * admin/includes/simple-xlsx.php
 * -----------------------------------------------------------------------
 * A dependency-free .xlsx writer used only by the Reports & Analytics
 * "Export → XLSX" option. Hand-writes the minimal OOXML spreadsheet
 * package (Content_Types, relationships, workbook, one worksheet) using
 * PHP's built-in ZipArchive extension — no Composer / PhpSpreadsheet
 * needed, so it works on a stock XAMPP install with the zip extension
 * enabled (on by default).
 *
 * Supports one sheet, a bold header row, and either numeric or text
 * cells (numbers written as real numbers so Excel/LibreOffice can sum
 * and format them; everything else as inline strings).
 * -----------------------------------------------------------------------
 */

/** Converts a 0-based column index to a spreadsheet column letter (0 -> A, 27 -> AB). */
function xlsx_col_letter(int $index): string
{
    $index++; // to 1-based
    $letter = '';
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index  = intdiv($index, 26);
    }
    return $letter;
}

function xlsx_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Streams a single-sheet .xlsx file to the browser and exits.
 *
 * @param string $filename Download filename, e.g. "financial-report.xlsx"
 * @param array  $headers  Column header labels, e.g. ['Room','Revenue']
 * @param array  $rows     Array of rows; each row is an array of scalar
 *                         values in the same order as $headers. Numeric
 *                         values are written as numbers; everything else
 *                         as text.
 */
function export_xlsx(string $filename, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "XLSX export requires the PHP zip extension, which isn't enabled on this server.";
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'bmxlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
        '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
        '<borders count="1"><border/></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>' .
        '<cellXfs count="2"><xf numFmtId="0" fontId="0" xfId="0"/><xf numFmtId="0" fontId="1" xfId="0" applyFont="1"/></cellXfs>' .
        '</styleSheet>'
    );

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
           '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    $xml .= '<row r="1">';
    foreach ($headers as $i => $h) {
        $cell = xlsx_col_letter($i) . '1';
        $xml .= '<c r="' . $cell . '" t="inlineStr" s="1"><is><t>' . xlsx_escape((string) $h) . '</t></is></c>';
    }
    $xml .= '</row>';

    $r = 2;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $val) {
            $cell = xlsx_col_letter($i) . $r;
            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $cell . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . xlsx_escape((string) $val) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $r++;
    }
    $xml .= '</sheetData></worksheet>';

    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    unlink($tmp);
    exit;
}
