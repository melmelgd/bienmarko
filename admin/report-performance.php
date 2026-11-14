<?php
/**
 * admin/report-performance.php
 * -----------------------------------------------------------------------
 * Performance Reports. Every figure is a percentage of available capacity:
 *
 *   Occupancy % = booked unit-nights ÷ available unit-nights × 100
 *   Available unit-nights = (units of that type − units under maintenance)
 *                           × days in the period
 *   Rounded to 1 decimal, capped at 100%.
 *
 * Rules
 *   - Count nights, not bookings. A night is each date from check-in up to
 *     the day before check-out (a same-day booking counts as 1).
 *   - Cancelled bookings are excluded; upcoming bookings are included.
 *   - Bookings that cross a week / month / year boundary are split by night.
 *   - Partial weeks (Nov 1, Nov 30 …) use their real day count.
 *   - Each unit type is calculated on its own; types are never combined.
 *   - A type with no bookings shows "No data", never 0%.
 *   - Ties for "busiest" go to the earlier period.
 *
 * Toggle
 *   Week  -> cards show the busiest week  (all data); chart = weeks of the month
 *   Month -> cards show the busiest month (all data); chart = weeks of the month
 *   Year  -> cards show the busiest year  (all data); chart = months of the year
 *
 * Peak season: per type, for each calendar month the average occupancy
 * across all years of data; Seasonality Index = month average ÷ yearly
 * average (the 12 monthly averages summed ÷ 12). Only shown once all 12
 * months have data.
 *
 * Maintenance: the accommodations table only stores a current status (no
 * dates), so a unit whose status is "maintenance" is left out of the
 * available nights for every period.
 *
 * Everything is calculated here, so reports-data.php and the PDF/XLSX
 * export are untouched.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/reports-data.php';
require_once __DIR__ . '/includes/reports-toolbar.php';

/* ==================================================================
   PURE CALCULATION HELPERS  (no database, no output)
================================================================== */
// >>> PERF-CALC-BEGIN

/** 'Y-m-d' -> timestamp at 00:00 UTC (no daylight-saving surprises). */
function perf_ts(string $d): int { return (int) strtotime($d . ' 00:00:00 UTC'); }
function perf_date(int $ts): string { return gmdate('Y-m-d', $ts); }

/**
 * The nights a booking occupies: every date from check-in up to the day
 * before check-out. A same-day (or bad) booking counts as 1 night.
 * @return string[] list of 'Y-m-d'
 */
function perf_booking_nights(string $checkIn, string $checkOut): array
{
    $ci = perf_ts($checkIn);
    $co = perf_ts($checkOut);
    $last = max($ci, $co - 86400);
    if ($last - $ci > 366 * 86400 * 2) { $last = $ci + 366 * 86400 * 2; }   // safety net
    $out = [];
    for ($ts = $ci; $ts <= $last; $ts += 86400) { $out[] = perf_date($ts); }
    return $out;
}

/**
 * The week / month / year bucket a date belongs to.
 * Weeks run Mon–Sun but are cut at month ends, so partial weeks keep their
 * real day count (e.g. "Nov 1" = 1 day, "Nov 30" = 1 day).
 * @return array{key:string,start:string,end:string,days:int}
 */
function perf_bucket_of(string $date, string $gran): array
{
    $ts = perf_ts($date);
    $y  = (int) gmdate('Y', $ts);
    $m  = (int) gmdate('n', $ts);

    if ($gran === 'year') {
        $s = sprintf('%04d-01-01', $y);
        $e = sprintf('%04d-12-31', $y);
    } elseif ($gran === 'month') {
        $s = sprintf('%04d-%02d-01', $y, $m);
        $e = sprintf('%04d-%02d-%02d', $y, $m, (int) gmdate('t', perf_ts(sprintf('%04d-%02d-01', $y, $m))));
    } else { // week
        $monday = $ts - (((int) gmdate('N', $ts)) - 1) * 86400;
        $sunday = $monday + 6 * 86400;
        $mStart = perf_ts(sprintf('%04d-%02d-01', $y, $m));
        $mEnd   = perf_ts(sprintf('%04d-%02d-%02d', $y, $m, (int) gmdate('t', perf_ts(sprintf('%04d-%02d-01', $y, $m)))));
        $s = perf_date(max($monday, $mStart));
        $e = perf_date(min($sunday, $mEnd));
    }
    $days = (int) ((perf_ts($e) - perf_ts($s)) / 86400) + 1;
    return ['key' => $s, 'start' => $s, 'end' => $e, 'days' => $days];
}

/** Occupancy %: booked ÷ ((units − maintenance) × days) × 100, 1 decimal, max 100. null = nothing to measure. */
function perf_occupancy(int $booked, int $bookableUnits, int $days): ?float
{
    $cap = $bookableUnits * $days;
    if ($cap <= 0) { return null; }
    return min(100.0, round($booked / $cap * 100, 1));
}

/** Total booked nights between two dates (inclusive) from a date => count map. */
function perf_sum_nights(array $byDate, string $start, string $end): int
{
    $sum = 0;
    for ($ts = perf_ts($start); $ts <= perf_ts($end); $ts += 86400) {
        $sum += $byDate[perf_date($ts)] ?? 0;
    }
    return $sum;
}

/**
 * Sum booked nights into week / month / year buckets for ONE type.
 * @return array<string,array{start:string,end:string,days:int,booked:int}> sorted oldest first
 */
function perf_group_nights(array $byDate, string $gran): array
{
    $out = [];
    foreach ($byDate as $d => $n) {
        $b = perf_bucket_of((string) $d, $gran);
        if (!isset($out[$b['key']])) { $out[$b['key']] = $b + ['booked' => 0]; }
        $out[$b['key']]['booked'] += $n;
    }
    ksort($out);
    return $out;
}

/** Busiest bucket (ties -> earlier). Returns bucket + 'pct', or null if no bookings. */
function perf_busiest(array $grouped, int $bookableUnits): ?array
{
    $best = null;
    foreach ($grouped as $b) {          // oldest first, so a tie never replaces the earlier one
        $pct = perf_occupancy($b['booked'], $bookableUnits, $b['days']);
        if ($pct === null) { continue; }
        if ($best === null || $pct > $best['pct']) { $best = $b + ['pct' => $pct]; }
    }
    return $best;
}

/**
 * Peak season for ONE type.
 * A calendar month "has data" when it has at least one booked night in some year.
 * Month average = mean of that month's occupancy over the years that have bookings in it.
 * @return array{months:int,complete:bool,yearly:?float,rows:array}
 */
function perf_peak_season(array $byDate, int $bookableUnits): array
{
    $perMonth = [];                                     // 1..12 => list of yearly occupancy %
    foreach (perf_group_nights($byDate, 'month') as $b) {
        $pct = perf_occupancy($b['booked'], $bookableUnits, $b['days']);
        if ($pct === null) { continue; }
        $perMonth[(int) substr($b['start'], 5, 2)][] = $pct;
    }
    $have = count($perMonth);
    if ($have < 12) { return ['months' => $have, 'complete' => false, 'yearly' => null, 'rows' => []]; }

    $avg = [];
    for ($m = 1; $m <= 12; $m++) { $avg[$m] = array_sum($perMonth[$m]) / count($perMonth[$m]); }
    $yearly = array_sum($avg) / 12;

    $rows = [];
    for ($m = 1; $m <= 12; $m++) {
        $idx = $yearly > 0 ? round($avg[$m] / $yearly, 2) : 0.0;
        $rows[] = [
            'month' => $m,
            'avg'   => round($avg[$m], 1),
            'index' => $idx,
            'class' => $idx >= 1.20 ? 'Peak' : ($idx >= 0.80 ? 'Regular' : 'Low'),
        ];
    }
    return ['months' => 12, 'complete' => true, 'yearly' => round($yearly, 1), 'rows' => $rows];
}

function perf_pct_str(float $v): string { return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; }

// <<< PERF-CALC-END

/* ==================================================================
   PAGE SETUP
================================================================== */
$pdo = bienmarko_db();
reports_ensure_core_tables($pdo);

$period = reports_resolve_period($_GET);

$active_page         = 'report';
$page_title          = 'Performance';
$breadcrumb          = 'Performance';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('performance', $period);

$types      = ['Room', 'Event Hall', 'Cottage', 'Swimming Pool'];
$typeShort  = ['Room' => 'Room', 'Event Hall' => 'Event hall', 'Cottage' => 'Cottage', 'Swimming Pool' => 'Pool'];
$typeColor  = ['Room' => '#f5a623', 'Event Hall' => '#4c8df6', 'Cottage' => '#2fd08f', 'Swimming Pool' => '#a78bfa'];
$unitNoun   = ['Room' => 'rooms', 'Event Hall' => 'halls', 'Cottage' => 'cottages', 'Swimming Pool' => 'pool units'];

/* ------------------------------------------------------------------
   Units: total per type, and how many are bookable (not in maintenance)
------------------------------------------------------------------ */
$units     = [];                                    // id => [name, type, status]
$unitTotal = array_fill_keys($types, 0);
$unitMaint = array_fill_keys($types, 0);
foreach ($pdo->query('SELECT id, name, type, status FROM accommodations ORDER BY name ASC')->fetchAll() as $u) {
    $t = trim((string) $u['type']);
    if (!in_array($t, $types, true)) { continue; }
    $units[(int) $u['id']] = ['name' => $u['name'], 'type' => $t, 'status' => $u['status']];
    $unitTotal[$t]++;
    if ($u['status'] === 'maintenance') { $unitMaint[$t]++; }
}
$avail = [];                                        // bookable units per type
foreach ($types as $t) { $avail[$t] = $unitTotal[$t] - $unitMaint[$t]; }

/* ------------------------------------------------------------------
   Every non-cancelled booking, split into booked nights per type per date.
   Upcoming bookings are included. Also kept per booking for the rankings.
------------------------------------------------------------------ */
$rows = $pdo->query(
    "SELECT b.accommodation_id, a.type, b.check_in, b.check_out
     FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
     WHERE b.status <> 'cancelled'"
)->fetchAll();

$nightsByDate = array_fill_keys($types, []);        // type => [date => nights]
$bookingNights = [];                                // list of [accId, type, [dates]]
foreach ($rows as $r) {
    $t = trim((string) $r['type']);
    if (!in_array($t, $types, true)) { continue; }
    $dates = perf_booking_nights((string) $r['check_in'], (string) $r['check_out']);
    foreach ($dates as $d) { $nightsByDate[$t][$d] = ($nightsByDate[$t][$d] ?? 0) + 1; }
    $bookingNights[] = [(int) $r['accommodation_id'], $t, $dates];
}

/* ------------------------------------------------------------------
   Cards: busiest week / month / year across ALL data, per type
------------------------------------------------------------------ */
$gran     = $period['period'] === 'week' ? 'week' : ($period['period'] === 'year' ? 'year' : 'month');
$modeWord = $gran;                                  // "week" | "month" | "year"
$curYear  = (int) substr($period['start'], 0, 4);

$busiest = [];                                      // type => bucket|null
$hasData = [];
foreach ($types as $t) {
    $busiest[$t] = perf_busiest(perf_group_nights($nightsByDate[$t], $gran), $avail[$t]);
    $hasData[$t] = $busiest[$t] !== null;
}

$perf_label = function (array $b) use ($gran, $curYear): string {
    $s = new DateTime($b['start']);
    $e = new DateTime($b['end']);
    if ($gran === 'year')  { return $s->format('Y'); }
    if ($gran === 'month') { return $s->format('M Y'); }
    $label = $b['start'] === $b['end'] ? $s->format('M j') : $s->format('M j') . '–' . $e->format('j');
    return ((int) $s->format('Y') !== $curYear) ? $label . ', ' . $s->format('Y') : $label;
};

/* ------------------------------------------------------------------
   Chart: weeks (Week / Month, or a short custom range) or months (Year)
------------------------------------------------------------------ */
$todayDt = new DateTime('today');
if ($period['period'] === 'week') {
    $chartStart = $todayDt->format('Y-m-01');
    $chartEnd   = $todayDt->format('Y-m-t');
} else {
    $chartStart = $period['start'];
    $chartEnd   = $period['end'];
}
$cStart   = new DateTime($chartStart);
$cEnd     = new DateTime($chartEnd);
$spanDays = (int) $cStart->diff($cEnd)->days + 1;
$chartMode = ($period['period'] === 'year' || $spanDays > 130) ? 'month' : 'week';
$modeChart = $chartMode;                            // used in the headings
$multiYear = $cStart->format('Y') !== $cEnd->format('Y');

$buckets = [];
for ($ts = perf_ts($chartStart); $ts <= perf_ts($chartEnd); ) {
    $b = perf_bucket_of(perf_date($ts), $chartMode);
    $bs = max(perf_ts($b['start']), perf_ts($chartStart));
    $be = min(perf_ts($b['end']),   perf_ts($chartEnd));
    $s = new DateTime(perf_date($bs));
    $e = new DateTime(perf_date($be));
    if ($chartMode === 'month') {
        $label = $s->format($multiYear ? 'M y' : 'M');
    } else {
        $label = $bs === $be ? $s->format('M j') : $s->format('M j') . '–' . $e->format('j');
    }
    $buckets[] = ['label' => $label, 'start' => perf_date($bs), 'end' => perf_date($be),
                  'days' => (int) (($be - $bs) / 86400) + 1];
    $ts = $be + 86400;
}
$nB = count($buckets);

$series = [];                                       // type => [bucket index => %]
foreach ($types as $t) {
    foreach ($buckets as $i => $bk) {
        $booked = perf_sum_nights($nightsByDate[$t], $bk['start'], $bk['end']);
        $series[$t][$i] = perf_occupancy($booked, $avail[$t], $bk['days']) ?? 0.0;
    }
}
$overallIdx = -1; $overallVal = 0;
foreach ($types as $t) {
    foreach ($series[$t] as $i => $v) {
        if ($v > $overallVal) { $overallVal = $v; $overallIdx = $i; }
    }
}

/* ------------------------------------------------------------------
   "How is this calculated?" lines, with the real numbers
------------------------------------------------------------------ */
$calcLines = [];
foreach ($types as $t) {
    $b = $busiest[$t];
    if (!$b) { continue; }
    $unitsTxt = $unitMaint[$t] > 0 ? '(' . $unitTotal[$t] . ' − ' . $unitMaint[$t] . ')' : (string) $avail[$t];
    $calcLines[] = [
        'type'  => $t,
        'label' => $perf_label($b),
        'text'  => $b['booked'] . ' ÷ (' . $unitsTxt . ' × ' . $b['days'] . ') = ' . perf_pct_str((float) $b['pct']),
    ];
}
/* ------------------------------------------------------------------
   Peak season (per type, all data)
------------------------------------------------------------------ */
$peak = [];
foreach ($types as $t) { $peak[$t] = perf_peak_season($nightsByDate[$t], $avail[$t]); }

/* ------------------------------------------------------------------
   Rankings: bookings per unit with at least one night in the period
------------------------------------------------------------------ */
$unitBookings = [];
foreach ($bookingNights as [$accId, $t, $dates]) {
    foreach ($dates as $d) {
        if ($d >= $period['start'] && $d <= $period['end']) {
            $unitBookings[$accId] = ($unitBookings[$accId] ?? 0) + 1;   // one count per booking
            break;
        }
    }
}

$rank = [];
foreach ($types as $order => $t) {
    $rank[$t] = ['type' => $t, 'order' => $order, 'bookings' => 0, 'withBookings' => [], 'others' => 0];
}
foreach ($units as $id => $u) {
    $c = $unitBookings[$id] ?? 0;
    $rank[$u['type']]['bookings'] += $c;
    if ($c > 0) { $rank[$u['type']]['withBookings'][] = ['name' => $u['name'], 'bookings' => $c]; }
    else        { $rank[$u['type']]['others']++; }
}
foreach ($rank as &$rk) {
    usort($rk['withBookings'], function ($a, $b) { return [$b['bookings'], $a['name']] <=> [$a['bookings'], $b['name']]; });
}
unset($rk);
usort($rank, function ($a, $b) { return [$b['bookings'], $a['order']] <=> [$a['bookings'], $b['order']]; });
$maxTypeBookings = 0;
foreach ($rank as $rk) { $maxTypeBookings = max($maxTypeBookings, $rk['bookings']); }

/* ------------------------------------------------------------------
   Chart geometry (SVG, drawn server-side)
------------------------------------------------------------------ */
$svgW = 900; $svgH = 380;
$mL = 58; $mR = 14; $mT = 24; $mB = 46;
$padX  = 28;
$plotW = $svgW - $mL - $mR;
$plotH = $svgH - $mT - $mB;
$xFor = function (int $i) use ($nB, $mL, $plotW, $padX) {
    if ($nB <= 1) { return $mL + $plotW / 2; }
    return $mL + $padX + ($i / ($nB - 1)) * ($plotW - 2 * $padX);
};
$yFor = function (float $v) use ($mT, $plotH) { return $mT + (1 - max(0, min(100, $v)) / 100) * $plotH; };

$pStart = new DateTime($period['start']);
$pEnd   = new DateTime($period['end']);
$rangeText = '';
if (in_array($period['period'], ['month', 'year'], true)) {
    $rangeText = ($pStart->format('Y-m') === $pEnd->format('Y-m'))
        ? $pStart->format('M j') . ' – ' . $pEnd->format('j, Y')
        : $pStart->format('M j') . ' – ' . $pEnd->format('M j, Y');
}
function perf_pct(float $v): string { return perf_pct_str($v); }

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';
?>

<style>
/* ============ Performance report — page-scoped styles ============ */
.perf-period { font-size: 17px; color: var(--text-secondary); }
.perf-period strong { color: var(--text); font-weight: 600; }

.perf-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.perf-kpi { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 26px; }
.perf-kpi-label { display: flex; align-items: center; gap: 10px; font-size: 16px; color: var(--text-secondary); }
.perf-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.perf-kpi-value { display: block; font-family: var(--font-display); font-size: 36px; font-weight: 700; line-height: 1.1; color: var(--text); margin: 16px 0 10px; }
.perf-kpi-value--muted { color: var(--text-tertiary); }
.perf-kpi-sub { font-size: 14px; color: var(--text-secondary); }

.perf-grid { display: grid; grid-template-columns: 1.25fr 1fr; gap: 22px; align-items: start; }
.perf-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 28px; }
.perf-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 8px; }
.perf-panel-title { font-family: var(--font-display); font-size: 25px; font-weight: 700; margin: 0; }
.perf-pill { padding: 7px 16px; border: 1px solid var(--border); border-radius: var(--r-full); background: var(--panel-alt); font-size: 14px; color: var(--text-secondary); white-space: nowrap; }
.perf-sub { font-size: 16px; color: var(--text-secondary); margin: 0 0 22px; }

.perf-legend { display: flex; flex-wrap: wrap; gap: 8px 24px; margin-bottom: 14px; font-size: 16px; }
.perf-legend span { display: inline-flex; align-items: center; gap: 8px; color: var(--text); }
.perf-legend .is-off { color: var(--text-tertiary); opacity: .75; }
.perf-legend .perf-dot { width: 11px; height: 11px; }
.perf-legend .is-off .perf-dot { opacity: .55; }

.perf-svg { width: 100%; height: auto; display: block; overflow: visible; }
.perf-axis { font-size: 14px; fill: var(--text-secondary); font-family: var(--font-body); }
.perf-axis--strong { fill: var(--text); font-weight: 700; }
.perf-val { font-size: 14px; font-weight: 700; fill: var(--text); text-anchor: middle; font-family: var(--font-body); }
.perf-note { font-size: 14px; color: var(--text-secondary); line-height: 1.55; margin: 16px 0 0; }

.perf-rank { display: flex; flex-direction: column; }
.perf-rank-group { padding: 18px 0; border-top: 1px solid var(--border); }
.perf-rank-group:first-child { border-top: none; padding-top: 4px; }
.perf-rank-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.perf-rank-name { display: inline-flex; align-items: center; gap: 12px; font-size: 18px; color: var(--text-secondary); }
.perf-rank-count { font-size: 17px; font-weight: 700; color: var(--text-secondary); white-space: nowrap; }
.perf-rank-group.has .perf-rank-name, .perf-rank-group.has .perf-rank-count { color: var(--text); }
.perf-track { height: 8px; border-radius: var(--r-full); background: var(--panel-alt); overflow: hidden; }
.perf-fill { height: 100%; border-radius: var(--r-full); }
.perf-units { margin: 18px 0 0 6px; padding-left: 20px; border-left: 1px solid var(--border); display: flex; flex-direction: column; gap: 16px; }
.perf-units .perf-rank-name { font-size: 17px; color: var(--text); }
.perf-units .perf-rank-count { color: var(--text); }
.perf-others { font-size: 15px; color: var(--text-tertiary); }

@media (max-width: 1280px) { .perf-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 1000px) { .perf-grid { grid-template-columns: 1fr; } }
@media (max-width: 600px)  { .perf-kpi-grid { grid-template-columns: 1fr; } .perf-panel { padding: 20px; } }
@media print { .perf-kpi, .perf-panel { background: #fff !important; border: 1px solid #ddd !important; break-inside: avoid; } }

/* How is this calculated? */
.perf-calc { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 18px 26px; }
.perf-calc summary { cursor: pointer; font-size: 16px; color: var(--gold-400, #f5a623); list-style-position: inside; }
.perf-calc-body { margin-top: 14px; font-size: 16px; color: var(--text-secondary); display: flex; flex-direction: column; gap: 10px; }
.perf-calc-body code, .perf-calc-line code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--text); }
.perf-calc-line { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; }
.perf-calc-line .perf-dot { width: 10px; height: 10px; }

/* Peak season */
.perf-peak-type { padding: 18px 0; border-top: 1px solid var(--border); }
.perf-peak-type:first-of-type { border-top: none; padding-top: 6px; }
.perf-peak-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.perf-peak-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
.perf-peak-cell { background: var(--panel-alt); border: 1px solid var(--border); border-radius: var(--r-md, 10px); padding: 12px 14px; }
.perf-peak-cell b { display: block; font-size: 15px; color: var(--text); }
.perf-peak-cell span { display: block; font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
.perf-badge { display: inline-block; margin-top: 8px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.perf-badge--Peak    { background: rgba(245,166,35,.18); color: #f5a623; }
.perf-badge--Regular { background: rgba(148,163,184,.16); color: var(--text-secondary); }
.perf-badge--Low     { background: rgba(76,141,246,.16); color: #4c8df6; }
@media (max-width: 1000px) { .perf-peak-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 600px)  { .perf-peak-grid { grid-template-columns: repeat(2, 1fr); } .perf-calc { padding: 16px; } }
</style>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="perf-period">
    <strong><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($rangeText !== ''): ?> · <?php echo htmlspecialchars($rangeText, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
  </div>

  <!-- ===== Busiest week / month / year per type ===== -->
  <div class="perf-kpi-grid">
    <?php foreach ($types as $t): ?>
      <?php $b = $busiest[$t]; ?>
      <div class="perf-kpi">
        <div class="perf-kpi-label"><span class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></span><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($b): ?>
          <span class="perf-kpi-value"><?php echo htmlspecialchars($perf_label($b), ENT_QUOTES, 'UTF-8'); ?></span>
          <div class="perf-kpi-sub">Busiest <?php echo $modeWord; ?> · <?php echo perf_pct((float) $b['pct']); ?> occupied</div>
          <div class="perf-track" style="margin-top:14px;"><div class="perf-fill" style="width: <?php echo (float) $b['pct']; ?>%; background: <?php echo $typeColor[$t]; ?>"></div></div>
        <?php else: ?>
          <span class="perf-kpi-value perf-kpi-value--muted">No data</span>
          <div class="perf-kpi-sub">No bookings yet</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ===== How is this calculated? ===== -->
  <details class="perf-calc" open>
    <summary>How is this calculated?</summary>
    <div class="perf-calc-body">
      <div><?php echo ucfirst($modeWord); ?> occupancy = booked nights ÷ ((units − units under maintenance) × days in <?php echo $modeWord; ?>) × 100</div>
      <?php if (!$calcLines): ?>
        <div>No bookings yet, so there is nothing to calculate.</div>
      <?php endif; ?>
      <?php foreach ($calcLines as $cl): ?>
        <div class="perf-calc-line">
          <i class="perf-dot" style="background: <?php echo $typeColor[$cl['type']]; ?>"></i>
          <span><?php echo htmlspecialchars($typeShort[$cl['type']], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($cl['label'], ENT_QUOTES, 'UTF-8'); ?>:</span>
          <code><?php echo htmlspecialchars($cl['text'], ENT_QUOTES, 'UTF-8'); ?></code>
        </div>
      <?php endforeach; ?>
      <div class="perf-others">Rooms count nights. Halls, cottages and the pool count booked days. Each type is calculated on its own.</div>
    </div>
  </details>

  <div class="perf-grid">
    <!-- ===== Occupancy chart ===== -->
    <div class="perf-panel">
      <div class="perf-panel-head">
        <h2 class="perf-panel-title">Occupancy by <?php echo $modeChart; ?></h2>
        <span class="perf-pill"><?php echo htmlspecialchars($chartMode === 'week' ? $cStart->format('F Y') : $period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <p class="perf-sub">Booked nights ÷ available nights, 0–100% for each type</p>

      <div class="perf-legend">
        <?php foreach ($types as $t): ?>
          <span class="<?php echo $hasData[$t] ? '' : 'is-off'; ?>"><i class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></i><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?><?php echo $hasData[$t] ? '' : ' · no data'; ?></span>
        <?php endforeach; ?>
      </div>

      <svg class="perf-svg" viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>" role="img" aria-label="Occupancy by <?php echo $modeChart; ?>">
        <?php foreach ([0, 25, 50, 75, 100] as $g): ?>
          <line x1="<?php echo $mL; ?>" y1="<?php echo $yFor($g); ?>" x2="<?php echo $svgW - $mR; ?>" y2="<?php echo $yFor($g); ?>" stroke="var(--border)" stroke-width="1"></line>
          <text x="<?php echo $mL - 12; ?>" y="<?php echo $yFor($g) + 5; ?>" text-anchor="end" class="perf-axis"><?php echo $g; ?>%</text>
        <?php endforeach; ?>

        <?php foreach ($buckets as $i => $bk): ?>
          <text x="<?php echo round($xFor($i), 2); ?>" y="<?php echo $svgH - 12; ?>" text-anchor="middle" class="perf-axis <?php echo $i === $overallIdx ? 'perf-axis--strong' : ''; ?>"><?php echo htmlspecialchars($bk['label'], ENT_QUOTES, 'UTF-8'); ?></text>
        <?php endforeach; ?>

        <?php foreach ($types as $t): if (!$hasData[$t]) continue; ?>
          <?php
            $d = '';
            foreach ($series[$t] as $i => $v) {
                $d .= ($i === 0 ? 'M' : ' L') . sprintf('%.2F,%.2F', $xFor($i), $yFor((float) $v));
            }
          ?>
          <?php if ($nB > 1): ?>
            <path d="<?php echo $d; ?>" fill="none" stroke="<?php echo $typeColor[$t]; ?>" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"></path>
          <?php endif; ?>
          <?php foreach ($series[$t] as $i => $v): if ($v <= 0) continue; ?>
            <?php $cx = $xFor($i); $cy = $yFor((float) $v); ?>
            <circle cx="<?php echo round($cx, 2); ?>" cy="<?php echo round($cy, 2); ?>" r="5.5" fill="<?php echo $typeColor[$t]; ?>"></circle>
            <text x="<?php echo round($cx, 2); ?>" y="<?php echo round($cy < $mT + 16 ? $cy + 24 : $cy - 12, 2); ?>" class="perf-val"><?php echo perf_pct((float) $v); ?></text>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <?php /* invisible hover areas — the browser shows the <title> as a tooltip */ ?>
        <?php foreach ($buckets as $i => $bk): ?>
          <?php
            $tip = $bk['label'] . ' (' . $bk['days'] . ' day' . ($bk['days'] === 1 ? '' : 's') . ')';
            foreach ($types as $t) { $tip .= "\n" . $typeShort[$t] . ': ' . ($hasData[$t] ? perf_pct((float) $series[$t][$i]) : 'No data'); }
            $slot = $nB > 1 ? ($plotW - 2 * $padX) / ($nB - 1) : $plotW;
          ?>
          <rect x="<?php echo round($xFor($i) - $slot / 2, 2); ?>" y="<?php echo $mT; ?>" width="<?php echo round($slot, 2); ?>" height="<?php echo $plotH; ?>" fill="transparent">
            <title><?php echo htmlspecialchars($tip, ENT_QUOTES, 'UTF-8'); ?></title>
          </rect>
        <?php endforeach; ?>
      </svg>

      <p class="perf-note">Includes upcoming bookings. Excludes cancelled bookings and units under maintenance.</p>
    </div>

    <!-- ===== Rankings ===== -->
    <div class="perf-panel">
      <div class="perf-panel-head">
        <h2 class="perf-panel-title">Rankings</h2>
        <span class="perf-pill"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <p class="perf-sub">Ranked by number of bookings</p>

      <div class="perf-rank">
        <?php foreach ($rank as $rk): ?>
          <?php $t = $rk['type']; $c = $rk['bookings']; ?>
          <div class="perf-rank-group <?php echo $c > 0 ? 'has' : ''; ?>">
            <div class="perf-rank-head">
              <span class="perf-rank-name"><i class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></i><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="perf-rank-count"><?php echo $c; ?> booking<?php echo $c === 1 ? '' : 's'; ?></span>
            </div>
            <div class="perf-track"><div class="perf-fill" style="width: <?php echo $maxTypeBookings > 0 ? round($c / $maxTypeBookings * 100, 2) : 0; ?>%; background: <?php echo $typeColor[$t]; ?>"></div></div>

            <?php if ($c > 0): ?>
              <div class="perf-units">
                <?php foreach ($rk['withBookings'] as $u): ?>
                  <div>
                    <div class="perf-rank-head" style="margin-bottom:8px;">
                      <span class="perf-rank-name"><?php echo htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <span class="perf-rank-count"><?php echo (int) $u['bookings']; ?> booking<?php echo (int) $u['bookings'] === 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="perf-track"><div class="perf-fill" style="width: <?php echo round($u['bookings'] / $c * 100, 2); ?>%; background: <?php echo $typeColor[$t]; ?>"></div></div>
                  </div>
                <?php endforeach; ?>
                <?php if ($rk['others'] > 0): ?>
                  <div class="perf-others"><?php echo (int) $rk['others']; ?> other <?php echo $rk['others'] === 1 ? rtrim($unitNoun[$t], 's') : $unitNoun[$t]; ?> · no bookings</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ===== Peak season ===== -->
  <div class="perf-panel">
    <div class="perf-panel-head">
      <h2 class="perf-panel-title">Peak season</h2>
    </div>
    <p class="perf-sub">Seasonality Index = month average ÷ yearly average. 1.20 or higher is Peak, 0.80 to 1.19 is Regular, below 0.80 is Low.</p>

    <?php $monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; ?>
    <?php foreach ($types as $t): $pk = $peak[$t]; ?>
      <div class="perf-peak-type">
        <div class="perf-peak-head">
          <span class="perf-rank-name"><i class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></i><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php if ($pk['complete']): ?>
            <span class="perf-pill">Yearly average <?php echo perf_pct((float) $pk['yearly']); ?></span>
          <?php else: ?>
            <span class="perf-pill">Needs 12 months of data (<?php echo (int) $pk['months']; ?> of 12)</span>
          <?php endif; ?>
        </div>
        <?php if ($pk['complete']): ?>
          <div class="perf-peak-grid">
            <?php foreach ($pk['rows'] as $r): ?>
              <div class="perf-peak-cell">
                <b><?php echo $monthNames[$r['month']]; ?></b>
                <span><?php echo perf_pct((float) $r['avg']); ?> avg · index <?php echo number_format($r['index'], 2); ?></span>
                <i class="perf-badge perf-badge--<?php echo $r['class']; ?>"><?php echo $r['class']; ?></i>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <p class="perf-note">Includes upcoming bookings. Excludes cancelled bookings and units under maintenance.</p>
  </div>
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>