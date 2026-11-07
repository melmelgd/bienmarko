<?php
/**
 * admin/includes/report-statement.php
 * -----------------------------------------------------------------------
 * "Statement" layout for the Financial and Performance reports: the formal,
 * serif, US-Letter document with a letterhead, Summary paragraph, numbered
 * Schedules, Notes to the Report, signature lines and "Page X of Y" footer
 * (the layout of Financial_Report_*.docx / Performance_Report_*.docx).
 *
 * ONE model, TWO renderers
 *   rst_build_model()   -> neutral list of blocks (heading, table, chart,
 *                          notes, signatures ...) with every number, label
 *                          and sentence already worked out.
 *   rst_render_html()   -> the browser print page (report-print.php).
 *   StatementPDF        -> the PDF export (includes/report-pdf.php).
 * Both renderers draw the SAME model, so the printout and the PDF cannot
 * drift apart. Numbers come from the same reports_*_data() functions as
 * the on-screen reports and the XLSX export (includes/reports-data.php).
 *
 * Guest Insights and Inventory keep their existing layout; only
 * 'financial' and 'performance' go through this file.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/reports-data.php';
require_once __DIR__ . '/simple-pdf.php';

const RST_FONT_STACK = '"Times New Roman", Times, "Liberation Serif", Tinos, serif';

/* ======================================================================
   Small helpers
====================================================================== */
function rst_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Table number: 12,550.00 — negatives in parentheses, the accounting way: (35,550.00). */
function rst_num(float $v, int $dec = 2): string
{
    $neg = $v < 0 && round(abs($v), $dec) > 0;
    $s   = number_format(abs($v), $dec);
    return $neg ? '(' . $s . ')' : $s;
}

/** Peso amount for running text: ₱17,550.00 (always the absolute value; wording carries the sign). */
function rst_peso(float $v, int $dec = 2): string
{
    return "\u{20B1}" . number_format(abs($v), $dec);
}

function rst_int($v): string
{
    return number_format((int) $v);
}

/** "37.5%" — or an em dash when the whole is zero. Negative shares go in parentheses: (202.6%). */
function rst_pct(float $part, float $whole): string
{
    if ($whole <= 0) {
        return "\u{2014}";
    }
    $v = $part / $whole * 100;
    $s = number_format(abs($v), 1) . '%';
    return ($v < 0 && round(abs($v), 1) > 0) ? '(' . $s . ')' : $s;
}

function rst_plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/** "A", "A and B", "A, B and C". */
function rst_join_and(array $items): string
{
    $items = array_values($items);
    $n = count($items);
    if ($n === 0) { return ''; }
    if ($n === 1) { return $items[0]; }
    return implode(', ', array_slice($items, 0, $n - 1)) . ' and ' . $items[$n - 1];
}

function rst_upper(string $s): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

/* ======================================================================
   Letterhead data (property from Settings, admin from session)
====================================================================== */
function rst_property(PDO $pdo): array
{
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
    return $property;
}

function rst_prepared_by(PDO $pdo): string
{
    $name = '';
    try {
        $st = $pdo->prepare('SELECT name FROM admins WHERE id = :id');
        $st->execute(['id' => (int) ($_SESSION['auth_user_id'] ?? 0)]);
        $name = trim((string) $st->fetchColumn());
    } catch (\Throwable $e) {
        /* leave blank */
    }
    /* Account names are often typed in lower case ("shayra"); a signature line reads better capitalised. */
    if ($name !== '' && $name === (function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name))) {
        $name = function_exists('mb_convert_case') ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : ucwords($name);
    }
    return $name;
}

/* ======================================================================
   Period wording
====================================================================== */
function rst_period_info(array $period): array
{
    $s  = new DateTime($period['start']);
    $e  = new DateTime($period['end']);
    $sl = $s->format('F j, Y');
    $el = $e->format('F j, Y');
    $range = ($sl === $el) ? $sl : $sl . ' – ' . $el;

    switch ($period['period']) {
        case 'year':
            $phrase = 'For the Year Ended ' . $el;
            $inline = 'the year ' . $e->format('Y');
            break;
        case 'month':
            $phrase = 'For the Month Ended ' . $el;
            $inline = 'the month of ' . $s->format('F Y');
            break;
        case 'week':
            $phrase = 'For the Week Ended ' . $el;
            $inline = 'the week of ' . $period['label'];
            break;
        default:
            $phrase = ($sl === $el) ? 'For ' . $el : 'For the Period ' . $sl . ' to ' . $el;
            $inline = ($sl === $el) ? $el : 'the period ' . $sl . ' to ' . $el;
    }
    return ['phrase' => $phrase, 'inline' => $inline, 'range' => $range];
}

/** "Financial_Report_2026_Bien_Markos_Pension_House" — becomes the default "Save as PDF" file name. */
function rst_file_stem(string $reportTitle, array $period, array $property): string
{
    $raw  = $reportTitle . ' ' . $period['label'] . ' ' . $property['name'];
    $raw  = str_replace(["'", "\u{2019}"], '', $raw);
    $stem = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $raw), '_');
    return $stem !== '' ? $stem : 'Report';
}

/* ======================================================================
   MODEL: shared building blocks
====================================================================== */
const RST_MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const RST_MONTHS_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** 'Oct' -> 'October'; 'Oct-Dec' -> 'October – December'; anything else unchanged. */
function rst_expand_months(string $label): string
{
    $parts = explode('-', $label);
    $out   = [];
    foreach ($parts as $p) {
        $i = array_search(trim($p), RST_MONTHS_ABBR, true);
        $out[] = $i === false ? trim($p) : RST_MONTHS_LONG[$i];
    }
    return implode(' – ', $out);
}

/** "Rooms – Family Room"; just the type label when the name adds nothing ("Pool"). */
function rst_accommodation_label(array $r): string
{
    $name = trim((string) $r['label']);
    $type = trim((string) ($r['type'] ?? ''));
    if ($type === '') {
        return $name;
    }
    $typeLabel = rpt_type_label($type);
    $same = [strtolower($type), strtolower($typeLabel), strtolower(rpt_type_short($type))];
    if ($name === '' || in_array(strtolower($name), $same, true)) {
        return $typeLabel;
    }
    return $typeLabel . ' – ' . $name;
}

function rst_seg_bold(string $lead, string $rest): array
{
    return [[$lead . ' ', 'b'], [$rest, 'r']];
}

/* ======================================================================
   MODEL: Financial — Statement of Income and Expenses
====================================================================== */
function rst_model_financial(PDO $pdo, array $period): array
{
    $d       = reports_financial_data($pdo, $period['start'], $period['end']);
    $info    = rst_period_info($period);
    $revenue = (float) $d['revenue'];
    $expense = (float) $d['expenses'];
    $profit  = (float) $d['profit'];
    $count   = (int) $d['bookingCount'];
    $collected   = (float) $d['collected'];
    $outstanding = (float) $d['outstanding'];
    $isLoss  = $profit < 0;

    /* Revenue lines: one per accommodation, then whatever the join to
       accommodations could not see (accommodation deleted after booking)
       so every table below adds up to Total Revenue. */
    $lines = [];
    foreach ($d['byAccommodation'] as $r) {
        $lines[] = ['label' => rst_accommodation_label($r), 'bookings' => (int) $r['bookings'], 'amount' => (float) $r['amount']];
    }
    $listedAmount   = array_sum(array_column($lines, 'amount'));
    $listedBookings = array_sum(array_column($lines, 'bookings'));
    $gapAmount      = round($revenue - $listedAmount, 2);
    $gapBookings    = $count - (int) $listedBookings;
    $hasGap         = abs($gapAmount) >= 0.01 || $gapBookings !== 0;

    $expLines   = $d['expensesByCategory'];
    $expEntries = (int) array_sum(array_column($expLines, 'entries'));

    /* ---------------- Summary sentence ---------------- */
    if (abs($profit) < 0.005) {
        $result = 'resulting in neither a net income nor a net loss';
    } else {
        $result = 'resulting in a net ' . ($isLoss ? 'loss' : 'income') . ' of ' . rst_peso($profit);
    }
    $summary = 'For ' . $info['inline'] . ', the business earned ' . rst_peso($revenue) . ' from '
        . rst_plural($count, 'booking') . ' and incurred expenses of ' . rst_peso($expense) . ', ' . $result . '.';

    $blocks = [];
    $blocks[] = ['t' => 'summary', 'segs' => [['Summary. ', 'b'], [$summary, 'r']], 'before' => 0];

    /* ---------------- Statement of Income and Expenses ---------------- */
    $rows = [['k' => 'label', 'c' => ['REVENUE', '', '']]];
    foreach ($lines as $l) {
        $rows[] = ['k' => 'indent', 'c' => [$l['label'], '2', rst_num($l['amount'])]];
    }
    if ($hasGap) {
        $rows[] = ['k' => 'indent', 'c' => ['Other bookings (discontinued / unlisted accommodations)', '2', rst_num($gapAmount)]];
    }
    if (!$lines && !$hasGap) {
        $rows[] = ['k' => 'indent', 'c' => ['No revenue recorded in this period', '', rst_num(0)]];
    }
    $rows[] = ['k' => 'subtotal', 'c' => ['Total Revenue', '', rst_num($revenue)]];
    $rows[] = ['k' => 'blank',    'c' => ['', '', '']];
    $rows[] = ['k' => 'label',    'c' => ['EXPENSES', '', '']];
    foreach ($expLines as $r) {
        $rows[] = ['k' => 'indent', 'c' => [(string) $r['label'], '4', rst_num((float) $r['amount'])]];
    }
    if (!$expLines) {
        $rows[] = ['k' => 'indent', 'c' => ['No expenses recorded in this period', '', rst_num(0)]];
    }
    $rows[] = ['k' => 'subtotal', 'c' => ['Total Expenses', '', rst_num($expense)]];
    $rows[] = ['k' => 'blank',    'c' => ['', '', '']];
    $rows[] = ['k' => 'total',    'c' => [$isLoss ? 'NET LOSS' : 'NET INCOME', '', rst_num($profit)]];

    $blocks[] = ['t' => 'h2', 'text' => 'Statement of Income and Expenses'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 6200, 'a' => 'l'], ['w' => 900, 'a' => 'c'], ['w' => 2260, 'a' => 'r']],
        'head' => ['Particulars', 'Note', "Amount (\u{20B1})"],
        'rows' => $rows];

    /* ---------------- Schedule 1 — Revenue by Accommodation ---------------- */
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = ['k' => 'body', 'c' => [$l['label'], rst_int($l['bookings']), rst_num($l['amount']), rst_pct($l['amount'], $revenue)]];
    }
    if ($hasGap) {
        $rows[] = ['k' => 'body', 'c' => ['Other bookings (discontinued / unlisted)', rst_int(max(0, $gapBookings)), rst_num($gapAmount), rst_pct($gapAmount, $revenue)]];
    }
    if (!$rows) {
        $rows[] = ['k' => 'body', 'c' => ['No revenue recorded in this period', '0', rst_num(0), "\u{2014}"]];
    }
    $rows[] = ['k' => 'total', 'c' => ['Total Revenue', rst_int($count), rst_num($revenue), $revenue > 0 ? '100.0%' : "\u{2014}"]];
    $blocks[] = ['t' => 'h2', 'text' => 'Schedule 1 – Revenue by Accommodation'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 4260, 'a' => 'l'], ['w' => 1300, 'a' => 'r'], ['w' => 1900, 'a' => 'r'], ['w' => 1900, 'a' => 'r']],
        'head' => ['Accommodation', 'Bookings', "Revenue (\u{20B1})", '% of Revenue'],
        'rows' => $rows];

    /* ---------------- Schedule 2 — Collections and Receivables ---------------- */
    $collRate = $revenue > 0 ? $collected / $revenue * 100 : null;
    $blocks[] = ['t' => 'h2', 'text' => 'Schedule 2 – Collections and Receivables'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 5560, 'a' => 'l'], ['w' => 1500, 'a' => 'r'], ['w' => 2300, 'a' => 'r']],
        'head' => ['Particulars', 'Bookings', "Amount (\u{20B1})"],
        'rows' => [
            ['k' => 'body',  'c' => ['Total billed', rst_int($count), rst_num($revenue)]],
            ['k' => 'body',  'c' => ['Less: Collected (payments received)', rst_int($d['paidCount']), '(' . number_format($collected, 2) . ')']],
            ['k' => 'total', 'c' => ['Outstanding balance (receivable)', rst_int($d['pendingCount']), rst_num($outstanding)]],
        ]];
    $status = 'Booking payment status: ' . rst_int($d['fullyPaidCount']) . ' fully paid · '
        . rst_int($d['partialCount']) . ' partially paid · ' . rst_int($d['unpaidCount']) . ' unpaid.';
    if ($collRate !== null) {
        $status .= ' Collection rate: ' . number_format($collRate, 1) . '% (' . rst_peso($collected) . ' of ' . rst_peso($revenue) . ' billed).';
    }
    $blocks[] = ['t' => 'italic', 'text' => $status];

    /* ---------------- Schedule 3 — Expenses by Category ---------------- */
    $rows = [];
    foreach ($expLines as $r) {
        $rows[] = ['k' => 'body', 'c' => [(string) $r['label'], rst_int($r['entries']), rst_num((float) $r['amount']), rst_pct((float) $r['amount'], $expense)]];
    }
    if (!$rows) {
        $rows[] = ['k' => 'body', 'c' => ['No expenses recorded in this period', '0', rst_num(0), "\u{2014}"]];
    }
    $rows[] = ['k' => 'total', 'c' => ['Total Expenses', rst_int($expEntries), rst_num($expense), $expense > 0 ? '100.0%' : "\u{2014}"]];
    $blocks[] = ['t' => 'h2', 'text' => 'Schedule 3 – Expenses by Category'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 4260, 'a' => 'l'], ['w' => 1300, 'a' => 'r'], ['w' => 1900, 'a' => 'r'], ['w' => 1900, 'a' => 'r']],
        'head' => ['Category', 'Entries', "Amount (\u{20B1})", '% of Expenses'],
        'rows' => $rows];

    /* ---------------- Key Indicators ---------------- */
    $blocks[] = ['t' => 'h2', 'text' => 'Key Indicators'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 6560, 'a' => 'l'], ['w' => 2800, 'a' => 'r']],
        'head' => ['Indicator', 'Result'],
        'rows' => [
            ['k' => 'body', 'c' => ['Net margin (' . ($isLoss ? 'Net loss' : 'Net income') . " \u{00F7} Revenue)", rst_pct($profit, $revenue)]],
            ['k' => 'body', 'c' => ['Expenses as a percentage of revenue', $revenue > 0 ? number_format($expense / $revenue * 100, 1) . '%' : "\u{2014}"]],
            ['k' => 'body', 'c' => ["Collection rate (Collected \u{00F7} Billed)", $collRate !== null ? number_format($collRate, 1) . '%' : "\u{2014}"]],
            ['k' => 'body', 'c' => ['Average revenue per booking', $count > 0 ? "\u{20B1}" . number_format($revenue / $count, 2) : "\u{2014}"]],
        ]];

    /* ---------------- Notes ---------------- */
    $blocks[] = ['t' => 'h2', 'text' => 'Notes to the Report'];
    $blocks[] = ['t' => 'notes', 'items' => [
        rst_seg_bold('Basis of preparation.', 'Revenue is presented on an accrual basis (amounts billed), while collections are presented on a cash basis (payments received). See Schedule 2.'),
        rst_seg_bold('Revenue.', "Revenue is the billed amount, after discounts and extra charges, of Confirmed and Completed bookings whose check-in date falls within the period. Cancelled and Pending bookings are excluded. \u{201C}Other bookings\u{201D} are bookings whose accommodation is no longer listed in the system."),
        rst_seg_bold('Collections.', 'Collected amounts are payments recorded as received on the same bookings, including partial payments. The outstanding balance is the amount still owed on them.'),
        rst_seg_bold('Expenses.', 'Expenses are those recorded with a date inside the period. Net income (loss) equals Revenue less Expenses.'),
    ]];
    $blocks[] = ['t' => 'signatures'];

    return [
        'report'      => 'financial',
        'reportTitle' => 'Financial Report',
        'title'       => 'STATEMENT OF INCOME AND EXPENSES',
        'footerTitle' => 'Statement of Income and Expenses',
        'subLines'    => [[$info['phrase'], 11, 'r'], ['(Amounts in Philippine Pesos)', 10, 'i']],
        'meta'        => [],
        'blocks'      => $blocks,
    ];
}

/* ======================================================================
   MODEL: Performance — Seasonal Trends and Peak Performance
====================================================================== */
const RST_SERIES_STYLE = [
    'Room'          => ['color' => '#2f6fdb', 'shape' => 'circle'],
    'Event Hall'    => ['color' => '#d6336c', 'shape' => 'square'],
    'Cottage'       => ['color' => '#2f9e44', 'shape' => 'triangle'],
    'Swimming Pool' => ['color' => '#e8590c', 'shape' => 'diamond'],
];

/** Points of a non-circle marker, centred on (cx, cy). */
function rst_marker_pts(string $shape, float $cx, float $cy, float $r): array
{
    switch ($shape) {
        case 'square':
            return [[$cx - $r * 0.9, $cy - $r * 0.9], [$cx + $r * 0.9, $cy - $r * 0.9], [$cx + $r * 0.9, $cy + $r * 0.9], [$cx - $r * 0.9, $cy + $r * 0.9]];
        case 'triangle':
            return [[$cx, $cy - $r * 1.15], [$cx + $r * 1.1, $cy + $r * 0.8], [$cx - $r * 1.1, $cy + $r * 0.8]];
        case 'diamond':
            return [[$cx, $cy - $r * 1.25], [$cx + $r * 1.05, $cy], [$cx, $cy + $r * 1.25], [$cx - $r * 1.05, $cy]];
    }
    return [];
}

/**
 * Line chart as a list of drawing primitives in a 450 x 212.5 pt canvas
 * (6.25 in x 2.95 in, like the sample). Both renderers replay the list:
 *   ['line', x1, y1, x2, y2, lw, color]      ['poly', pts, lw, color]
 *   ['fill', pts, color]                     ['circle', cx, cy, r, color]
 *   ['text', x, baseline, str, size, style, anchor(start|middle|end), color, rotateDeg]
 * $series: [['name' =>, 'color' =>, 'shape' =>, 'vals' => [12 numbers]], ...]
 */
function rst_chart(array $series, int $year, callable $measure): array
{
    $W = 450.0; $H = 212.5;
    $pl = 40.0; $pr = 440.0; $pt = 12.5; $pb = 151.5;
    $pw = $pr - $pl; $ph = $pb - $pt;
    $ink = '#000000'; $grid = '#d4d4d4';
    $P = [];

    /* y scale: 0-100 normally; the shared Pool can exceed 100% */
    $maxV = 100.0;
    foreach ($series as $s) { foreach ($s['vals'] as $v) { $maxV = max($maxV, (float) $v); } }
    $step = max(25.0, ceil($maxV / 4 / 25) * 25);
    $yMax = ceil($maxV / $step) * $step;
    $yOf  = function (float $v) use ($pb, $ph, $yMax) { return $pb - $v / $yMax * $ph; };
    $xOf  = function (int $i) use ($pl, $pw) { return $pl + ($i + 0.5) * ($pw / 12); };

    /* grid + y ticks */
    for ($v = 0.0; $v <= $yMax + 0.001; $v += $step) {
        $y = $yOf($v);
        if ($v > 0) { $P[] = ['line', $pl, $y, $pr, $y, 0.6, $grid]; }
        $P[] = ['line', $pl - 3, $y, $pl, $y, 0.8, $ink];
        $P[] = ['text', $pl - 6, $y + 3.1, (string) (int) round($v), 9, 'r', 'end', $ink, 0];
    }
    /* axes */
    $P[] = ['line', $pl, $pt, $pl, $pb, 0.8, $ink];
    $P[] = ['line', $pl, $pb, $pr, $pb, 0.8, $ink];
    /* x ticks + month labels */
    for ($i = 0; $i < 12; $i++) {
        $x = $xOf($i);
        $P[] = ['line', $x, $pb, $x, $pb + 3, 0.8, $ink];
        $P[] = ['text', $x, $pb + 12.5, RST_MONTHS_ABBR[$i], 9, 'r', 'middle', $ink, 0];
    }
    $P[] = ['text', ($pl + $pr) / 2, $pb + 25.5, 'Month, ' . $year, 9.5, 'r', 'middle', $ink, 0];
    $P[] = ['text', 11.5, ($pt + $pb) / 2, 'Occupancy (%)', 9.5, 'r', 'middle', $ink, 90];

    /* series (later series are drawn on top, like the sample) */
    foreach ($series as $s) {
        $pts = [];
        foreach ($s['vals'] as $i => $v) { $pts[] = [$xOf((int) $i), $yOf((float) $v)]; }
        $P[] = ['poly', $pts, 1.8, $s['color']];
    }
    foreach ($series as $s) {
        foreach ($s['vals'] as $i => $v) {
            $cx = $xOf((int) $i); $cy = $yOf((float) $v);
            if ($s['shape'] === 'circle') { $P[] = ['circle', $cx, $cy, 2.4, $s['color']]; }
            else { $P[] = ['fill', rst_marker_pts($s['shape'], $cx, $cy, 2.4), $s['color']]; }
        }
    }

    /* peak annotation: the month with the highest single-category occupancy */
    $bestI = -1; $bestKey = [0, 0];
    for ($i = 0; $i < 12; $i++) {
        $mx = 0; $sum = 0;
        foreach ($series as $s) { $mx = max($mx, $s['vals'][$i]); $sum += $s['vals'][$i]; }
        if ($mx > 0 && [$mx, $sum] > $bestKey) { $bestKey = [$mx, $sum]; $bestI = $i; }
    }
    if ($bestI >= 0) {
        $at = [];
        foreach ($series as $s) { if ($s['vals'][$bestI] > 0) { $at[] = [$s['name'], $s['vals'][$bestI]]; } }
        usort($at, function ($a, $b) { return $b[1] <=> $a[1]; });
        $l1 = 'Peak: ' . RST_MONTHS_ABBR[$bestI];
        $l2 = implode(', ', array_map(function ($a) { return $a[0] . ' ' . (int) $a[1] . '%'; }, $at));
        $tw = max($measure($l1, 8.5, 'r'), $measure($l2, 8.5, 'r'));
        $px = $xOf($bestI); $py = $yOf((float) $bestKey[0]);
        if ($py - $pt >= 70) {                       /* room above: text above-left, arrow down to the point */
            $tx = min(max($px - 60, $pl + 6), $pr - $tw - 4);
            $by = $py - 55;
            $P[] = ['text', $tx, $by, $l1, 8.5, 'r', 'start', $ink, 0];
            $P[] = ['text', $tx, $by + 9.5, $l2, 8.5, 'r', 'start', $ink, 0];
            $ax = $tx + min($tw, 60) * 0.9; $ay = $by + 17;
            $dx = $px - $ax; $dy = ($py - 4) - $ay; $len = max(1.0, sqrt($dx * $dx + $dy * $dy));
            $ux = $dx / $len; $uy = $dy / $len;
            $ex = $px - $ux * 4; $ey = $py - $uy * 4;
            $P[] = ['line', $ax, $ay, $ex - $ux * 3, $ey - $uy * 3, 1.0, $ink];
            $P[] = ['fill', [[$ex, $ey], [$ex - $ux * 6 - $uy * 2.6, $ey - $uy * 6 + $ux * 2.6], [$ex - $ux * 6 + $uy * 2.6, $ey - $uy * 6 - $ux * 2.6]], $ink];
        } else {                                     /* busy near the top: label to the left of the point */
            $tx = max($pl + 6, $px - $tw - 14);
            $by = $py + 3;
            $P[] = ['text', $tx, $by - 4.5, $l1, 8.5, 'r', 'start', $ink, 0];
            $P[] = ['text', $tx, $by + 5, $l2, 8.5, 'r', 'start', $ink, 0];
        }
    }

    /* legend, centred under the axis title */
    $items = []; $total = 0.0;
    foreach ($series as $s) {
        $w = 20 + 4 + $measure($s['name'], 9, 'r');
        $items[] = [$s, $w]; $total += $w;
    }
    $gap = 16.0; $total += $gap * max(0, count($items) - 1);
    $x = ($W - $total) / 2; $ly = 197.0;
    foreach ($items as [$s, $w]) {
        $P[] = ['line', $x, $ly - 3, $x + 20, $ly - 3, 1.8, $s['color']];
        if ($s['shape'] === 'circle') { $P[] = ['circle', $x + 10, $ly - 3, 2.4, $s['color']]; }
        else { $P[] = ['fill', rst_marker_pts($s['shape'], $x + 10, $ly - 3, 2.4), $s['color']]; }
        $P[] = ['text', $x + 24, $ly, $s['name'], 9, 'r', 'start', $ink, 0];
        $x += $w + $gap;
    }
    return ['w' => $W, 'h' => $H, 'prims' => $P];
}

function rst_model_performance(PDO $pdo, array $period): array
{
    $d     = reports_performance_data($pdo, $period['start'], $period['end']);
    $fin   = reports_financial_data($pdo, $period['start'], $period['end']);
    $info  = rst_period_info($period);
    $year  = (int) $d['year'];
    $units = $d['unitCounts'];
    $types = array_values(array_filter($d['types'], function ($t) use ($units) { return ($units[$t] ?? 0) > 0; }));

    /* Rankings: accommodation types that had bookings in the period, in data-layer order
       (bookings, then revenue). Types without bookings are covered by "No data" above. */
    $ranked = [];
    foreach ($d['rankings'] as $r) {
        if ((int) $r['bookings'] > 0) {
            $ranked[] = ['type' => $r['label'], 'bookings' => (int) $r['bookings'], 'revenue' => (float) $r['revenue']];
        }
    }
    $totBookings = (int) array_sum(array_column($ranked, 'bookings'));
    $totRevenue  = (float) array_sum(array_column($ranked, 'revenue'));

    /* ---------------- Summary ---------------- */
    $activeMonths = 0; $peakI = -1; $peakKey = [0, 0];
    $typeHasData = [];
    for ($m = 1; $m <= 12; $m++) {
        $mx = 0; $sum = 0;
        foreach ($types as $t) {
            $v = (int) $d['occupancy'][$t][$m];
            $mx = max($mx, $v); $sum += $v;
            if ($v > 0) { $typeHasData[$t] = true; }
        }
        if ($mx > 0) { $activeMonths++; }
        if ($mx > 0 && [$mx, $sum] > $peakKey) { $peakKey = [$mx, $sum]; $peakI = $m - 1; }
    }
    $s2name = ['Room' => 'the Room category', 'Event Hall' => 'the Event Hall', 'Cottage' => 'the Cottage', 'Swimming Pool' => 'the Pool'];
    $rankName = function (string $t) { $l = rpt_type_label($t); return $t === 'Room' ? $l : 'the ' . $l; };

    $sent = [];
    if (!$types) {
        $sent[] = 'No accommodations are set up yet, so there is no occupancy to report for ' . $year . '.';
    } else {
        if ($activeMonths === 0)      { $sent[] = 'In ' . $year . ', no bookings were recorded.'; }
        elseif ($activeMonths < 12)   { $sent[] = 'In ' . $year . ', bookings were recorded only in the months shown in the trend below.'; }
        else                          { $sent[] = 'In ' . $year . ', bookings were recorded in every month, as shown in the trend below.'; }

        if ($peakI >= 0) {
            $at = [];
            foreach ($types as $t) {
                $v = (int) $d['occupancy'][$t][$peakI + 1];
                if ($v > 0) { $at[] = [$t, $v]; }
            }
            usort($at, function ($a, $b) { return $b[1] <=> $a[1]; });
            $parts = [];
            foreach ($at as $i => [$t, $v]) {
                $n = $s2name[$t] ?? 'the ' . $t;
                $parts[] = $n . ($i === 0 ? ' reached ' : ' ') . $v . '%';
            }
            $sent[] = 'Occupancy peaked in ' . RST_MONTHS_LONG[$peakI] . ', when ' . rst_join_and($parts) . '.';
        }
        $withBookings = $ranked;
        if ($withBookings) {
            $one = function (array $r) { return rst_plural($r['bookings'], 'booking') . ', ' . rst_peso($r['revenue']); };
            $t = ucfirst($rankName($withBookings[0]['type'])) . ' ranked first by bookings among the listed accommodations (' . $one($withBookings[0]) . ')';
            if (isset($withBookings[1])) { $t .= ', followed by ' . $rankName($withBookings[1]['type']) . ' (' . $one($withBookings[1]) . ')'; }
            $sent[] = $t . '.';
        } elseif ($activeMonths > 0) {
            /* the year has activity, but none inside the selected period */
            $sent[] = 'No bookings were recorded for ' . $period['label'] . '.';
        }
        if ($activeMonths > 0) {
            $none = [];
            foreach ($types as $t) { if (empty($typeHasData[$t])) { $none[] = rpt_type_short($t); } }
            if ($none) { $sent[] = 'The ' . rst_join_and($none) . ' had no recorded data.'; }
        }
    }

    $blocks = [];
    $blocks[] = ['t' => 'summary', 'segs' => [['Summary. ', 'b'], [implode(' ', $sent), 'r']], 'before' => 8];

    if ($types) {
        /* ---------------- Peak Season by Category ---------------- */
        $rows = [];
        foreach ($types as $t) {
            $p = $d['peak'][$t];
            $noData = ($p['label'] === 'No data');
            $rows[] = ['k' => 'body', 'c' => [
                rpt_type_short($t),
                $noData ? "\u{2013}" : rst_expand_months((string) $p['label']),
                $noData ? '0%' : (int) $p['pct'] . '%',
                $noData ? 'No data' : 'Peak season',
            ]];
        }
        $blocks[] = ['t' => 'h2', 'text' => 'Peak Season by Category'];
        $blocks[] = ['t' => 'table',
            'cols' => [['w' => 2600, 'a' => 'l'], ['w' => 2000, 'a' => 'c'], ['w' => 2300, 'a' => 'r'], ['w' => 2460, 'a' => 'l']],
            'head' => ['Category', 'Peak Month', 'Peak Occupancy', 'Remarks'],
            'rows' => $rows];

        /* ---------------- Figure 1 ---------------- */
        $series = [];
        foreach ($types as $t) {
            $vals = [];
            for ($m = 1; $m <= 12; $m++) { $vals[] = (int) $d['occupancy'][$t][$m]; }
            $style = RST_SERIES_STYLE[$t] ?? ['color' => '#555555', 'shape' => 'circle'];
            $series[] = ['name' => rpt_type_short($t), 'color' => $style['color'], 'shape' => $style['shape'], 'vals' => $vals];
        }
        $measurer = new SimplePDF('', ['family' => 'serif']);
        $chart = rst_chart($series, $year, function ($s, $z, $st) use ($measurer) { return $measurer->textW($s, $z, $st); });
        $cap = 'Monthly occupancy across all categories, ' . $year . '.';
        if ($activeMonths === 0)      { $cap .= ' No activity was recorded this year.'; }
        elseif ($activeMonths === 1)  { $cap .= ' The only activity appears in ' . RST_MONTHS_LONG[$peakI] . '.'; }
        else                          { $cap .= ' The busiest month was ' . RST_MONTHS_LONG[$peakI] . '.'; }
        $blocks[] = ['t' => 'h2', 'text' => 'Figure 1 – Seasonal Performance Trend (Monthly Occupancy, ' . $year . ')'];
        $blocks[] = ['t' => 'chart', 'chart' => $chart, 'caption' => $cap,
                     'alt' => 'Line chart of monthly occupancy percentage by accommodation category for ' . $year . '.'];
    }

    /* ---------------- Schedule 1 — Performance Rankings ---------------- */
    $rows = [];
    foreach ($ranked as $i => $r) {
        $rows[] = ['k' => 'body', 'c' => [
            (string) ($i + 1),
            rpt_type_label($r['type']),
            rst_int($r['bookings']),
            rst_num($r['revenue']),
            rst_pct($r['revenue'], $totRevenue),
        ]];
    }
    if (!$rows) {
        $rows[] = ['k' => 'body', 'c' => ["\u{2013}", 'No bookings recorded in this period', '0', rst_num(0), "\u{2014}"]];
    }
    $rows[] = ['k' => 'total', 'c' => ['', 'Total (listed accommodations)', rst_int($totBookings), rst_num($totRevenue), $totRevenue > 0 ? '100.0%' : "\u{2014}"]];
    $blocks[] = ['t' => 'h2', 'text' => 'Schedule 1 – Performance Rankings'];
    $blocks[] = ['t' => 'table',
        'cols' => [['w' => 900, 'a' => 'c'], ['w' => 3700, 'a' => 'l'], ['w' => 1500, 'a' => 'r'], ['w' => 1600, 'a' => 'r'], ['w' => 1660, 'a' => 'r']],
        'head' => ['Rank', 'Accommodation', 'Bookings', "Revenue (\u{20B1})", '% of Listed'],
        'rows' => $rows];
    $blocks[] = ['t' => 'italic', 'text' => 'Rankings are based on total bookings for the period.'];

    /* ---------------- Notes ---------------- */
    $catNames = array_map('rpt_type_short', $types);
    $occ = 'Occupancy is the monthly occupancy percentage across all categories (' . ($catNames ? rst_join_and($catNames) : 'none set up yet')
        . '), and it always covers the whole calendar year ' . $year . ', whatever period is selected. Peak season is the longest run of consecutive months in which a category was busier than its own yearly average; Peak Occupancy is its average over those months.';
    if (in_array('Swimming Pool', $types, true)) {
        $occ .= ' The Pool is shared by many guests at once, so its figure counts booking-days and can exceed 100% when bookings overlap.';
    }
    $gapB = (int) $fin['bookingCount'] - $totBookings;
    $gapA = round((float) $fin['revenue'] - $totRevenue, 2);
    $scope = 'The rankings cover accommodations currently listed in the system.';
    if ($gapB > 0 || abs($gapA) >= 0.01) {
        $scope .= ' The Financial Report for the same period includes ' . max(0, $gapB) . ' additional ' . ($gapB === 1 ? 'booking' : 'bookings')
            . ' (' . rst_peso($gapA) . ') under accommodations no longer listed, which are not ranked here.';
    }
    $blocks[] = ['t' => 'h2', 'text' => 'Notes to the Report'];
    $blocks[] = ['t' => 'notes', 'items' => [
        rst_seg_bold('Basis of ranking.', "Accommodations are ranked by total bookings for the period, as shown in the system\u{2019}s Performance Rankings; ties are broken by revenue. Only Confirmed and Completed bookings, by check-in date, are counted."),
        rst_seg_bold('Occupancy.', $occ),
        rst_seg_bold('Scope.', $scope),
        rst_seg_bold('No data.', "\u{201C}No data\u{201D} means no bookings were recorded for that category in " . $year . '.'),
    ]];
    $blocks[] = ['t' => 'signatures'];

    return [
        'report'      => 'performance',
        'reportTitle' => 'Performance Report',
        'title'       => 'PERFORMANCE REPORT',
        'footerTitle' => 'Performance Report',
        'subLines'    => [['Seasonal Trends and Peak Performance', 11, 'r'], [$info['phrase'], 11, 'r']],
        'meta'        => [
            ['Period:', $period['label'] . (($period['label'] !== $info['range']) ? ' (' . $info['range'] . ')' : '')],
        ],
        'blocks'      => $blocks,
    ];
}

/**
 * Full model for either report: content blocks + letterhead + footer text.
 * $report is 'financial' or 'performance'.
 */
function rst_build_model(PDO $pdo, string $report, array $period): array
{
    $m = ($report === 'performance') ? rst_model_performance($pdo, $period) : rst_model_financial($pdo, $period);
    $m['property']   = rst_property($pdo);
    $m['preparedBy'] = rst_prepared_by($pdo);
    $m['period']     = $period;
    $m['printed']    = date('F j, Y');
    $m['footerText'] = $m['property']['name'] . ' · ' . $m['footerTitle'] . ' · ' . $period['label'];
    $m['fileStem']   = rst_file_stem($m['reportTitle'], $period, $m['property']);
    if ($report === 'performance') {
        $m['meta'][] = ['Printed:', $m['printed']];
        if ($m['preparedBy'] !== '') { $m['meta'][] = ['Prepared by:', $m['preparedBy']]; }
    }
    /* Letterhead lines: address, then "Tel: … · Email: …" */
    $contact = [];
    if ($m['property']['phone'] !== '') { $contact[] = 'Tel: ' . $m['property']['phone']; }
    if ($m['property']['email'] !== '') { $contact[] = 'Email: ' . $m['property']['email']; }
    $m['headLines'] = array_values(array_filter([$m['property']['address'], implode('  ·  ', $contact)], function ($v) { return $v !== ''; }));
    return $m;
}

/* ======================================================================
   HTML RENDERER (browser print + on-screen preview)
====================================================================== */
function rst_segs_html(array $segs): string
{
    $o = '';
    foreach ($segs as $s) {
        $t = rst_h($s[0]);
        $st = $s[1] ?? 'r';
        $o .= $st === 'b' ? '<strong>' . $t . '</strong>' : ($st === 'i' ? '<em>' . $t . '</em>' : $t);
    }
    return $o;
}

/** Escapes a string for use inside a CSS "…" string (the @page footer text). */
function rst_css_str(string $s): string
{
    return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\A '], $s);
}

function rst_chart_svg(array $chart, string $alt): string
{
    $o = '<svg class="chart" viewBox="0 0 ' . $chart['w'] . ' ' . $chart['h'] . '" role="img" aria-label="' . rst_h($alt) . '" xmlns="http://www.w3.org/2000/svg">';
    $f = function ($v) { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); };
    foreach ($chart['prims'] as $p) {
        switch ($p[0]) {
            case 'line':
                $o .= '<line x1="' . $f($p[1]) . '" y1="' . $f($p[2]) . '" x2="' . $f($p[3]) . '" y2="' . $f($p[4]) . '" stroke="' . $p[6] . '" stroke-width="' . $f($p[5]) . '"/>';
                break;
            case 'poly':
                $pts = implode(' ', array_map(function ($q) use ($f) { return $f($q[0]) . ',' . $f($q[1]); }, $p[1]));
                $o .= '<polyline points="' . $pts . '" fill="none" stroke="' . $p[3] . '" stroke-width="' . $f($p[2]) . '" stroke-linejoin="round" stroke-linecap="round"/>';
                break;
            case 'fill':
                $pts = implode(' ', array_map(function ($q) use ($f) { return $f($q[0]) . ',' . $f($q[1]); }, $p[1]));
                $o .= '<polygon points="' . $pts . '" fill="' . $p[2] . '"/>';
                break;
            case 'circle':
                $o .= '<circle cx="' . $f($p[1]) . '" cy="' . $f($p[2]) . '" r="' . $f($p[3]) . '" fill="' . $p[4] . '"/>';
                break;
            case 'text':
                $rot = ((float) $p[8]) != 0.0 ? ' transform="rotate(-' . $f($p[8]) . ' ' . $f($p[1]) . ' ' . $f($p[2]) . ')"' : '';
                $o .= '<text x="' . $f($p[1]) . '" y="' . $f($p[2]) . '" font-size="' . $f($p[4]) . '" text-anchor="' . $p[6] . '" fill="' . $p[7] . '"'
                    . ($p[5] === 'b' ? ' font-weight="bold"' : '') . ($p[5] === 'i' ? ' font-style="italic"' : '') . $rot . '>' . rst_h($p[3]) . '</text>';
                break;
        }
    }
    return $o . '</svg>';
}

function rst_table_html(array $b): string
{
    $total = array_sum(array_column($b['cols'], 'w'));
    $al    = ['l' => 'a-l', 'c' => 'a-c', 'r' => 'a-r'];
    $o  = '<table class="st"><colgroup>';
    foreach ($b['cols'] as $c) { $o .= '<col style="width:' . round($c['w'] / $total * 100, 3) . '%">'; }
    $o .= '</colgroup><thead><tr>';
    foreach ($b['head'] as $i => $h) { $o .= '<th class="' . $al[$b['cols'][$i]['a']] . '">' . rst_h($h) . '</th>'; }
    $o .= '</tr></thead><tbody>';
    foreach ($b['rows'] as $r) {
        if ($r['k'] === 'blank') {
            $o .= '<tr class="blank"><td colspan="' . count($b['cols']) . '">&nbsp;</td></tr>';
            continue;
        }
        $o .= '<tr class="' . $r['k'] . '">';
        foreach ($r['c'] as $i => $v) { $o .= '<td class="' . $al[$b['cols'][$i]['a']] . '">' . rst_h($v) . '</td>'; }
        $o .= '</tr>';
    }
    return $o . '</tbody></table>';
}

/** Renders a single block; $kids tells h2 whether it shares a no-break group. */
function rst_block_html(array $b, array $m): string
{
    switch ($b['t']) {
        case 'summary':
            return '<p class="sum" style="margin-top:' . (int) $b['before'] . 'pt">' . rst_segs_html($b['segs']) . '</p>';
        case 'h2':
            return '<h2>' . rst_h($b['text']) . '</h2>';
        case 'table':
            return rst_table_html($b);
        case 'italic':
            return '<p class="it">' . rst_h($b['text']) . '</p>';
        case 'chart':
            return '<figure class="fig">' . rst_chart_svg($b['chart'], $b['alt']) . '<figcaption class="it cap">' . rst_h($b['caption']) . '</figcaption></figure>';
        case 'notes':
            $o = '<div class="notes">';
            foreach ($b['items'] as $i => $segs) {
                $o .= '<div class="note"><span class="n">' . ($i + 1) . '.</span><span class="tx">' . rst_segs_html($segs) . '</span></div>';
            }
            return $o . '</div>';
        case 'signatures':
            $name = 'Name';
            return '<table class="sig keep"><colgroup><col style="width:32.05%"><col style="width:3.53%"><col style="width:32.05%"><col style="width:3.53%"><col style="width:28.84%"></colgroup>'
                . '<tr class="r0"><td><strong>Prepared by:</strong></td><td></td><td><strong>Reviewed by:</strong></td><td></td><td><strong>Approved by:</strong></td></tr>'
                . '<tr class="r1"><td></td><td></td><td></td><td></td><td></td></tr>'
                . '<tr class="r2"><td class="ln">' . rst_h($name) . '</td><td></td><td class="ln">Name / Signature</td><td></td><td class="ln">Owner / Manager</td></tr>'
                . '<tr class="r3"><td>Date: ______________</td><td></td><td>Date: ______________</td><td></td><td>Date: ______________</td></tr></table>';
    }
    return '';
}

/**
 * Emits the whole statement page. $backHref / $autoPrint come from report-print.php.
 * Headings stay with the table/figure under them (and a table's trailing
 * italic remark); small groups are kept on one page.
 */
function rst_render_html(array $m, string $backHref, bool $autoPrint): void
{
    $blocks = $m['blocks'];
    $body = '';
    for ($i = 0, $n = count($blocks); $i < $n; $i++) {
        $b = $blocks[$i];
        if ($b['t'] === 'h2') {
            $group = [$b];
            $rowsInGroup = 0;
            if (isset($blocks[$i + 1]) && !in_array($blocks[$i + 1]['t'], ['h2', 'signatures'], true)) {
                $group[] = $blocks[++$i];
                if (($group[1]['t'] ?? '') === 'table') { $rowsInGroup = count($group[1]['rows']); }
                if (($group[1]['t'] ?? '') === 'table' && isset($blocks[$i + 1]) && $blocks[$i + 1]['t'] === 'italic') {
                    $group[] = $blocks[++$i];
                }
            }
            $html = '';
            foreach ($group as $g) { $html .= rst_block_html($g, $m); }
            $body .= '<div class="grp' . ($rowsInGroup <= 24 ? ' keep' : '') . '">' . $html . '</div>';
        } else {
            $body .= rst_block_html($b, $m);
        }
    }

    $p = $m['property'];
    $title = $m['fileStem'];
    $footerCss = rst_css_str($m['footerText']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo rst_h($title); ?></title>
<style>
/* =====================================================================
   Statement stylesheet — Times, black on white, US Letter, 1" margins.
   Self-contained on purpose: it must print the same whatever the
   dashboard theme is. Sizes are the ones used in the Word originals.
===================================================================== */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0; background: #e9ecef; color: #000;
  font-family: <?php echo RST_FONT_STACK; ?>;
  font-size: 11pt; line-height: 1.15;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}

/* ---------- Screen-only preview chrome ---------- */
.screen-bar {
  position: sticky; top: 0; z-index: 10;
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 10px 16px; background: #1f2933; color: #fff;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
}
.screen-bar .hint { font-size: 0.8rem; opacity: 0.8; }
.screen-bar .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.screen-bar a, .screen-bar button {
  font: inherit; font-size: 0.875rem; font-weight: 600; cursor: pointer;
  padding: 9px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35);
  background: transparent; color: #fff; text-decoration: none; min-height: 40px;
  display: inline-flex; align-items: center;
}
.screen-bar button.primary { background: #fff; color: #1f2933; border-color: #fff; }

.sheet { width: 8.5in; max-width: 100%; margin: 16px auto; padding: 1in; background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,0.18); }

/* ---------- Letterhead ---------- */
.lh { text-align: center; }
.lh .nm { font-size: 16pt; font-weight: bold; margin: 0 0 2pt; }
.lh .ln { font-size: 10pt; margin: 0; }
.lh .last { padding-bottom: 6pt; border-bottom: 1.5pt solid #000; margin-bottom: 6pt; }
.ttl { font-size: 14pt; font-weight: bold; text-align: center; margin: 10pt 0 1pt; }
.sub { text-align: center; margin: 0 0 1pt; }
.sub.i { font-style: italic; font-size: 10pt; }
.sub.end { margin-bottom: 10pt; }
table.meta { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10.5pt; }
table.meta td { padding: 2.5pt 5pt; vertical-align: top; }
table.meta td:first-child { font-weight: bold; }

/* ---------- Text ---------- */
p.sum { text-align: justify; margin: 0 0 6pt; }
h2 { font-size: 12pt; font-weight: bold; margin: 13pt 0 5pt; padding-bottom: 2pt; border-bottom: 0.75pt solid #000; break-after: avoid; page-break-after: avoid; }
p.it, figcaption.it { font-style: italic; font-size: 10pt; margin: 4pt 0; }
.notes .note { display: flex; font-size: 10.5pt; margin: 0 0 4pt; break-inside: avoid; page-break-inside: avoid; }
.notes .n { flex: 0 0 18pt; }
.notes .tx { flex: 1 1 0; min-width: 0; text-align: justify; }

/* ---------- Tables ---------- */
table.st { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10.5pt; }
table.st th, table.st td { padding: 2.5pt 5pt; vertical-align: middle; overflow-wrap: anywhere; }
table.st thead { display: table-header-group; }
table.st tr { break-inside: avoid; page-break-inside: avoid; }
table.st th { background: #e7e6e6; font-weight: bold; border-top: 0.5pt solid #000; border-bottom: 0.5pt solid #000; }
table.st .a-l { text-align: left; } table.st .a-c { text-align: center; } table.st .a-r { text-align: right; }
table.st td.a-r { white-space: nowrap; font-variant-numeric: tabular-nums; }
table.st tr.label td { font-weight: bold; }
table.st tr.indent td:first-child { padding-left: calc(5pt + 18pt); }
table.st tr.subtotal td { font-weight: bold; border-top: 0.5pt solid #000; }
table.st tr.total td { font-weight: bold; border-top: 0.5pt solid #000; border-bottom: 3px double #000; }

/* ---------- Figure ---------- */
figure.fig { margin: 3pt 0 0; text-align: center; break-inside: avoid; page-break-inside: avoid; }
svg.chart { display: block; width: 100%; max-width: 6.25in; height: auto; margin: 0 auto 3pt; font-family: <?php echo RST_FONT_STACK; ?>; }
figcaption.cap { margin: 0 0 4pt; }

/* ---------- Signatures ---------- */
table.sig { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10pt; margin-top: 24pt; break-inside: avoid; page-break-inside: avoid; }
table.sig td { text-align: center; vertical-align: top; padding: 2pt 0; }
table.sig tr.r0 { height: 35pt; } table.sig tr.r1 { height: 30pt; } table.sig tr.r3 { height: 25pt; }
table.sig td.ln { border-top: 0.5pt solid #000; }

.keep { break-inside: avoid; page-break-inside: avoid; }

/* Closing line: always on screen; on paper only where the browser cannot
   print the running footer below (see html.mb). */
.end-foot { margin-top: 18pt; padding-top: 4pt; border-top: 0.5pt solid #000; font-size: 9pt; }

/* ---------- Phones (preview only) ---------- */
@media screen and (max-width: 8.6in) {
  .sheet { padding: 0.5in 0.4in; }
}
@media screen and (max-width: 640px) {
  .sheet { margin: 0; padding: 18px 14px; box-shadow: none; }
  .screen-bar { padding: 8px 12px; }
  .screen-bar .actions { width: 100%; }
  .screen-bar .actions > * { flex: 1; justify-content: center; }
}

/* ---------- Paper: US Letter, 1-inch margins, running footer with Page X of Y ---------- */
@page {
  size: 8.5in 11in;
  margin: 1in;
  @bottom-left {
    content: "<?php echo $footerCss; ?>";
    width: 78%;
    font-family: <?php echo RST_FONT_STACK; ?>; font-size: 9pt; color: #000; text-align: left;
    vertical-align: bottom; padding-bottom: 30pt;
    background: linear-gradient(#000, #000) 0 27pt / 100% 0.5pt no-repeat;
  }
  @bottom-right {
    content: "Page " counter(page) " of " counter(pages);
    width: 22%;
    font-family: <?php echo RST_FONT_STACK; ?>; font-size: 9pt; color: #000; text-align: right;
    vertical-align: bottom; padding-bottom: 30pt;
    background: linear-gradient(#000, #000) 0 27pt / 100% 0.5pt no-repeat;
  }
}
@media print {
  body { background: #fff; }
  .screen-bar { display: none !important; }
  .sheet { width: auto; max-width: none; margin: 0; padding: 0; box-shadow: none; background: none; }
  html.mb .end-foot { display: none; }
  a { color: inherit; text-decoration: none; }
}
</style>
<script>
/* Chrome/Edge 131+ print the running "Page X of Y" footer from @page above;
   everywhere else the closing line at the end of the document is used. */
(function () {
  var b = navigator.userAgentData && navigator.userAgentData.brands, ok = false;
  if (b) { for (var i = 0; i < b.length; i++) { if (/Chromium/.test(b[i].brand) && parseInt(b[i].version, 10) >= 131) { ok = true; } } }
  if (ok) { document.documentElement.className += ' mb'; }
})();
</script>
</head>
<body>

<div class="screen-bar">
  <span class="hint">Print preview — this is what will be printed. Tip: turn off “Headers and footers” in the print dialog.</span>
  <div class="actions">
    <a href="<?php echo rst_h($backHref); ?>">← Back</a>
    <button type="button" class="primary" onclick="window.print()">Print</button>
  </div>
</div>

<main class="sheet">
  <header class="lh">
    <?php
    $lines = $m['headLines'];
    $nmClass = $lines ? 'nm' : 'nm last';
    ?>
    <p class="<?php echo $nmClass; ?>"><?php echo rst_h(rst_upper($p['name'])); ?></p>
    <?php foreach ($lines as $i => $ln): ?>
      <p class="ln<?php echo $i === count($lines) - 1 ? ' last' : ''; ?>"><span style="white-space:pre-wrap"><?php echo rst_h($ln); ?></span></p>
    <?php endforeach; ?>
  </header>

  <h1 class="ttl"><?php echo rst_h($m['title']); ?></h1>
  <?php foreach ($m['subLines'] as $i => $sl): ?>
    <p class="sub<?php echo $sl[2] === 'i' ? ' i' : ''; ?><?php echo $i === count($m['subLines']) - 1 ? ' end' : ''; ?>"><?php echo rst_h($sl[0]); ?></p>
  <?php endforeach; ?>

  <?php if ($m['meta']): ?>
    <table class="meta"><colgroup><col style="width:21.37%"><col style="width:78.63%"></colgroup>
      <?php foreach ($m['meta'] as $row): ?>
        <tr><td><?php echo rst_h($row[0]); ?></td><td><?php echo rst_h($row[1]); ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php echo $body; ?>

  <div class="end-foot"><?php echo rst_h($m['footerText']); ?></div>
</main>

<?php if ($autoPrint): ?>
<script>
/* Opened from a report's Print button: bring up the print dialog once the
   page has finished laying out. The preview stays open behind it. */
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
</script>
<?php endif; ?>
</body>
</html>
    <?php
}
