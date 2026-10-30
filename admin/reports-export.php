<?php
/**
 * admin/reports-export.php
 * -----------------------------------------------------------------------
 * Handles "Export → PDF" and "Export → XLSX" for all four reports.
 * Query params: report=financial|performance|guest-insights|inventory,
 * format=pdf|xlsx, period=week|month|year|custom (+ start/end for custom).
 *
 * Reuses the exact same reports_*_data() functions the on-screen pages
 * call — the export can't drift from what's displayed because it's
 * running the same queries over the same resolved date range.
 *
 * PDF output is laid out by includes/report-pdf.php so it matches the
 * paper version in report-print.php. XLSX is unchanged.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/reports-data.php';
require_once __DIR__ . '/includes/simple-pdf.php';
require_once __DIR__ . '/includes/report-pdf.php';
require_once __DIR__ . '/includes/simple-xlsx.php';

$pdo = bienmarko_db();
reports_ensure_core_tables($pdo);

$report = $_GET['report'] ?? '';
$format = $_GET['format'] ?? '';
$allowedReports = ['financial', 'performance', 'guest-insights', 'inventory'];
$allowedFormats = ['pdf', 'xlsx'];

if (!in_array($report, $allowedReports, true) || !in_array($format, $allowedFormats, true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Unknown report or format requested.';
    exit;
}

$period = reports_resolve_period($_GET);
require_once __DIR__ . '/../includes/site-settings.php';
$subtitle = bienmarko_site_name() . ' · ' . $period['label'];
$dateStamp = date('Y-m-d');

/* PDF: same layout as the print page (letterhead, KPI boxes, tables, notes).
   Streams the file and exits, so the XLSX data-building below only runs for xlsx. */
if ($format === 'pdf') {
    pdf_render_print_report($pdo, $report, $period, 'bienmarko-' . $report . '-report-' . $dateStamp . '.pdf');
}

switch ($report) {

    case 'financial':
        $d = reports_financial_data($pdo, $period['start'], $period['end']);
        $title = 'Financial Report';
        $summary = [
            'Total Revenue: P' . number_format($d['revenue'], 2),
            'Total Expenses: P' . number_format($d['expenses'], 2),
            'Net Profit: P' . number_format($d['profit'], 2),
            'Paid: P' . number_format($d['paidTotal'], 2) . ' (' . $d['paidCount'] . ' txns)   '
                . 'Pending: P' . number_format($d['pendingTotal'], 2) . ' (' . $d['pendingCount'] . ' txns)',
        ];
        $headers = ['Accommodation', 'Type', 'Revenue'];
        $rows = [];
        foreach ($d['byAccommodation'] as $row) {
            $rows[] = [$row['label'], '', 'P' . number_format((float) $row['amount'], 2)];
        }
        foreach ($d['byType'] as $row) {
            $rows[] = ['(By Type) ' . rpt_type_label($row['label']), '', 'P' . number_format((float) $row['amount'], 2)];
        }
        $xlsxHeaders = ['Accommodation / Group', 'Revenue'];
        $xlsxRows = [];
        foreach ($d['byAccommodation'] as $row) {
            $xlsxRows[] = [$row['label'], round((float) $row['amount'], 2)];
        }
        foreach ($d['byType'] as $row) {
            $xlsxRows[] = ['(By Type) ' . rpt_type_label($row['label']), round((float) $row['amount'], 2)];
        }
        break;

    case 'performance':
        $d = reports_performance_data($pdo, $period['start'], $period['end']);
        $title = 'Performance Report';
        $summary = [];
        foreach ($d['types'] as $t) {
            $p = $d['peak'][$t];
            $summary[] = rpt_type_label($t) . ' peak season: ' . $p['label'] . ' (' . $p['pct'] . '%)';
        }
        $headers = ['Rank', 'Type', 'Bookings', 'Revenue'];
        $rows = [];
        foreach ($d['rankings'] as $i => $r) {
            $rows[] = [$i + 1, rpt_type_label($r['label']), $r['bookings'], 'P' . number_format((float) $r['revenue'], 2)];
        }
        $xlsxHeaders = ['Rank', 'Type', 'Bookings', 'Revenue'];
        $xlsxRows = [];
        foreach ($d['rankings'] as $i => $r) {
            $xlsxRows[] = [$i + 1, rpt_type_label($r['label']), (int) $r['bookings'], round((float) $r['revenue'], 2)];
        }
        break;

    case 'guest-insights':
        $d = reports_guest_insights_data($pdo, $period['start'], $period['end']);
        $title = 'Guest Insights Report';
        $summary = [
            'Total Guests: ' . $d['totalGuests'],
            'Repeat Guests: ' . $d['repeatGuests'],
            'Average Rating: ' . ($d['avgRating'] !== null ? number_format($d['avgRating'], 1) . ' / 5.0' : 'No ratings yet'),
        ];
        $headers = ['Guest', 'Visits', 'Most Booked', 'Country', 'Rating'];
        $rows = [];
        foreach ($d['topGuests'] as $g) {
            $rows[] = [$g['name'], $g['visits'], $g['most_booked'], $g['country'], $g['rating'] !== null ? number_format($g['rating'], 1) : '-'];
        }
        $xlsxHeaders = $headers;
        $xlsxRows = [];
        foreach ($d['topGuests'] as $g) {
            $xlsxRows[] = [$g['name'], (int) $g['visits'], $g['most_booked'], $g['country'], $g['rating'] !== null ? (float) $g['rating'] : ''];
        }
        break;

    case 'inventory':
        $d = reports_inventory_data($pdo, $period['start'], $period['end']);
        $title = 'Inventory Report';
        $summary = [
            'Total Items: ' . $d['totalItems'],
            'Low Stock Items: ' . $d['lowStock'],
            'Inventory Value: P' . number_format($d['totalValue'], 2),
            'Total Stock Units: ' . $d['totalUnits'],
        ];
        $statusLabels = ['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock'];
        $headers = ['Item', 'Quantity', 'Min Stock', 'Cost', 'Status'];
        $rows = [];
        foreach ($d['items'] as $it) {
            $rows[] = [
                $it['name'],
                $it['quantity'] . ' units',
                $it['min_stock_level'] . ' units',
                'P' . number_format((float) $it['cost_per_unit'] * (int) $it['quantity'], 2),
                $statusLabels[reports_inv_status($it)],
            ];
        }
        $xlsxHeaders = ['Item', 'Quantity', 'Min Stock', 'Cost', 'Status'];
        $xlsxRows = [];
        foreach ($d['items'] as $it) {
            $xlsxRows[] = [
                $it['name'],
                (int) $it['quantity'],
                (int) $it['min_stock_level'],
                round((float) $it['cost_per_unit'] * (int) $it['quantity'], 2),
                $statusLabels[reports_inv_status($it)],
            ];
        }
        break;
}

$filenameBase = 'bienmarko-' . $report . '-report-' . $dateStamp;

export_xlsx($filenameBase . '.xlsx', $xlsxHeaders, $xlsxRows);