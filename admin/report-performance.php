<?php
/**
 * admin/report-performance.php
 * -----------------------------------------------------------------------
 * Performance Reports for the selected period:
 *   - Busiest stretch (week / day / month) per accommodation type
 *   - Occupancy chart: booked nights ÷ available nights, 0–100%, one line
 *     per type, split into days (short periods), weeks or months (a year)
 *   - Rankings: bookings per type, with the individual units inside each
 *
 * Occupancy counts every booking that is not cancelled (so upcoming ones
 * are included) and leaves units under maintenance out of the available
 * nights. Rooms count nights; cottages, halls and the pool count booked
 * days. Everything is calculated here, so reports-data.php and the
 * PDF/XLSX export are untouched.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/reports-data.php';
require_once __DIR__ . '/includes/reports-toolbar.php';

$pdo = bienmarko_db();
reports_ensure_core_tables($pdo);

$period = reports_resolve_period($_GET);

$active_page         = 'report';
$page_title          = 'Performance';
$breadcrumb          = 'Performance';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('performance', $period);

/* ------------------------------------------------------------------
   Types, labels, colours
------------------------------------------------------------------ */
$types      = ['Room', 'Event Hall', 'Cottage', 'Swimming Pool'];
$typeShort  = ['Room' => 'Room', 'Event Hall' => 'Event hall', 'Cottage' => 'Cottage', 'Swimming Pool' => 'Pool'];
$typeColor  = ['Room' => '#f5a623', 'Event Hall' => '#4c8df6', 'Cottage' => '#2fd08f', 'Swimming Pool' => '#a78bfa'];
$unitNoun   = ['Room' => 'rooms', 'Event Hall' => 'halls', 'Cottage' => 'cottages', 'Swimming Pool' => 'pool units'];

/* ------------------------------------------------------------------
   Units: how many exist, and how many can be booked (not in maintenance)
------------------------------------------------------------------ */
$units = [];        // id => [name, type, status]
$avail = array_fill_keys($types, 0);
foreach ($pdo->query('SELECT id, name, type, status FROM accommodations ORDER BY name ASC')->fetchAll() as $u) {
    $t = trim((string) $u['type']);
    if (!in_array($t, $types, true)) { continue; }
    $units[(int) $u['id']] = ['name' => $u['name'], 'type' => $t, 'status' => $u['status']];
    if ($u['status'] !== 'maintenance') { $avail[$t]++; }
}

/* ------------------------------------------------------------------
   Booked nights per type per calendar day (only days inside the period)
------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT b.accommodation_id, a.type, b.check_in, b.check_out
     FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
     WHERE b.status <> 'cancelled' AND b.check_in <= :e AND b.check_out >= :s"
);
$stmt->execute(['s' => $period['start'], 'e' => $period['end']]);

$nightsByDate = [];
$nightsTotal  = array_fill_keys($types, 0);
$pStartTs = strtotime($period['start']);
$pEndTs   = strtotime($period['end']);
foreach ($stmt->fetchAll() as $b) {
    $t = trim((string) $b['type']);
    if (!in_array($t, $types, true)) { continue; }
    $ciTs = strtotime($b['check_in']);
    $coTs = strtotime($b['check_out']);
    $lastTs = max($ciTs, $coTs - 86400);          // a same-day booking still counts 1 day
    for ($ts = max($ciTs, $pStartTs); $ts <= min($lastTs, $pEndTs); $ts += 86400) {
        $d = date('Y-m-d', $ts);
        $nightsByDate[$t][$d] = ($nightsByDate[$t][$d] ?? 0) + 1;
        $nightsTotal[$t]++;
    }
}

/* ------------------------------------------------------------------
   Buckets for the chart: days, weeks (Mon–Sun) or months
------------------------------------------------------------------ */
$pStart   = new DateTime($period['start']);
$pEnd     = new DateTime($period['end']);
$spanDays = (int) $pStart->diff($pEnd)->days + 1;
$mode     = ($period['period'] === 'week' || $spanDays <= 10) ? 'day' : ($spanDays <= 130 ? 'week' : 'month');

$buckets = [];
if ($mode === 'day') {
    for ($c = clone $pStart; $c <= $pEnd; $c->modify('+1 day')) {
        $buckets[] = ['label' => $c->format('M j'), 'start' => $c->format('Y-m-d'), 'end' => $c->format('Y-m-d')];
    }
} elseif ($mode === 'week') {
    $c = clone $pStart;
    while ($c <= $pEnd) {
        $e = (clone $c)->modify('+' . (7 - (int) $c->format('N')) . ' days');   // up to Sunday
        if ($e > $pEnd) { $e = clone $pEnd; }
        if ($c->format('Y-m-d') === $e->format('Y-m-d'))      { $label = $c->format('M j'); }
        elseif ($c->format('m') === $e->format('m'))          { $label = $c->format('M j') . '–' . $e->format('j'); }
        else                                                  { $label = $c->format('M j') . '–' . $e->format('M j'); }
        $buckets[] = ['label' => $label, 'start' => $c->format('Y-m-d'), 'end' => $e->format('Y-m-d')];
        $c = (clone $e)->modify('+1 day');
    }
} else {
    $multiYear = $pStart->format('Y') !== $pEnd->format('Y');
    $c = new DateTime($pStart->format('Y-m-01'));
    while ($c <= $pEnd) {
        $mEnd = (clone $c)->modify('last day of this month');
        $bs = $c < $pStart ? $pStart : $c;
        $be = $mEnd > $pEnd ? $pEnd : $mEnd;
        $buckets[] = ['label' => $c->format($multiYear ? 'M y' : 'M'), 'start' => $bs->format('Y-m-d'), 'end' => $be->format('Y-m-d')];
        $c = (clone $c)->modify('first day of next month');
    }
}
$nB = count($buckets);

/* Occupancy % per type per bucket */
$series = [];
foreach ($types as $t) {
    foreach ($buckets as $i => $bk) {
        $days = (int) (new DateTime($bk['start']))->diff(new DateTime($bk['end']))->days + 1;
        $sum = 0;
        for ($ts = strtotime($bk['start']); $ts <= strtotime($bk['end']); $ts += 86400) {
            $sum += $nightsByDate[$t][date('Y-m-d', $ts)] ?? 0;
        }
        $cap = $avail[$t] * $days;
        $series[$t][$i] = $cap > 0 ? min(100, round($sum / $cap * 100, 1)) : 0.0;
    }
}

/* Busiest bucket per type + overall (for the bold axis label) */
$hasData = [];
$busiest = [];
$overallIdx = -1; $overallVal = 0;
foreach ($types as $t) {
    $hasData[$t] = $nightsTotal[$t] > 0;
    $bi = -1; $bv = 0;
    foreach ($series[$t] as $i => $v) {
        if ($v > $bv) { $bv = $v; $bi = $i; }
    }
    $busiest[$t] = $bi >= 0 ? ['label' => $buckets[$bi]['label'], 'pct' => $bv] : null;
    if ($bv > $overallVal) { $overallVal = $bv; $overallIdx = $bi; }
}
$modeWord = ['day' => 'day', 'week' => 'week', 'month' => 'month'][$mode];

/* ------------------------------------------------------------------
   Rankings: bookings per unit in the period
------------------------------------------------------------------ */
$stmt = $pdo->prepare(
    "SELECT accommodation_id, COUNT(*) AS c FROM bookings
     WHERE status <> 'cancelled' AND check_in BETWEEN :s AND :e
     GROUP BY accommodation_id"
);
$stmt->execute(['s' => $period['start'], 'e' => $period['end']]);
$unitBookings = [];
foreach ($stmt->fetchAll() as $r) { $unitBookings[(int) $r['accommodation_id']] = (int) $r['c']; }

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

/* Period line (same as the Financial page) */
$rangeText = '';
if (in_array($period['period'], ['month', 'year'], true)) {
    $rangeText = ($pStart->format('Y-m') === $pEnd->format('Y-m'))
        ? $pStart->format('M j') . ' – ' . $pEnd->format('j, Y')
        : $pStart->format('M j') . ' – ' . $pEnd->format('M j, Y');
}
function perf_pct(float $v): string { return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; }

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
</style>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="perf-period">
    <strong><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($rangeText !== ''): ?> · <?php echo htmlspecialchars($rangeText, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
  </div>

  <!-- ===== Busiest stretch per type ===== -->
  <div class="perf-kpi-grid">
    <?php foreach ($types as $t): ?>
      <?php $b = $busiest[$t]; ?>
      <div class="perf-kpi">
        <div class="perf-kpi-label"><span class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></span><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($hasData[$t] && $b): ?>
          <span class="perf-kpi-value"><?php echo htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8'); ?></span>
          <div class="perf-kpi-sub">Busiest <?php echo $modeWord; ?> · <?php echo perf_pct((float) $b['pct']); ?> occupied</div>
        <?php else: ?>
          <span class="perf-kpi-value perf-kpi-value--muted">No data</span>
          <div class="perf-kpi-sub">No bookings in this period</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="perf-grid">
    <!-- ===== Occupancy chart ===== -->
    <div class="perf-panel">
      <div class="perf-panel-head">
        <h2 class="perf-panel-title">Occupancy by <?php echo $modeWord; ?></h2>
        <span class="perf-pill"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <p class="perf-sub">Booked nights ÷ available nights, 0–100% for each type</p>

      <div class="perf-legend">
        <?php foreach ($types as $t): ?>
          <span class="<?php echo $hasData[$t] ? '' : 'is-off'; ?>"><i class="perf-dot" style="background: <?php echo $typeColor[$t]; ?>"></i><?php echo htmlspecialchars($typeShort[$t], ENT_QUOTES, 'UTF-8'); ?><?php echo $hasData[$t] ? '' : ' · no data'; ?></span>
        <?php endforeach; ?>
      </div>

      <svg class="perf-svg" viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>" role="img" aria-label="Occupancy by <?php echo $modeWord; ?>">
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
            $tip = $bk['label'];
            foreach ($types as $t) { $tip .= "\n" . $typeShort[$t] . ': ' . perf_pct((float) $series[$t][$i]); }
            $slot = $nB > 1 ? ($plotW - 2 * $padX) / ($nB - 1) : $plotW;
          ?>
          <rect x="<?php echo round($xFor($i) - $slot / 2, 2); ?>" y="<?php echo $mT; ?>" width="<?php echo round($slot, 2); ?>" height="<?php echo $plotH; ?>" fill="transparent">
            <title><?php echo htmlspecialchars($tip, ENT_QUOTES, 'UTF-8'); ?></title>
          </rect>
        <?php endforeach; ?>
      </svg>

      <p class="perf-note">Includes upcoming bookings. Cancelled bookings and units under maintenance are excluded. Cottages, halls and the pool count booked days.</p>
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
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>