<?php
/**
 * admin/report-performance.php
 * -----------------------------------------------------------------------
 * Performance Reports: peak-season summary per accommodation type, a
 * year-long occupancy trend chart (hand-drawn SVG, hover for a per-month
 * tooltip — no chart JS library, consistent with dashboard.php's own
 * pie chart), and a Performance Rankings list scoped to the selected
 * period.
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
$data   = reports_performance_data($pdo, $period['start'], $period['end']);

$active_page         = 'report';
$page_title          = 'Performance';
$breadcrumb          = 'Performance';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('performance', $period);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';

/* Peak-season card tint per type, matching the mockup's four pastel colors */
$cardTint = [
    'Room'          => 'green',
    'Event Hall'    => 'rose',
    'Cottage'       => 'blue',
    'Swimming Pool' => 'lime',
];
/* Trend line color per type (used by both the inline <style> below and the JS tooltip).
   Chosen for maximum contrast against each other and the dark panel background —
   the original blue/cyan pairing for Room/Pool was too close to tell apart at a glance. */
$lineColor = [
    'Room'          => '#3b82f6',
    'Event Hall'    => '#ec4899',
    'Cottage'       => '#22c55e',
    'Swimming Pool' => '#f97316',
];

/* ---- Build the SVG trend chart geometry (server-side, like dashboard.php's pie chart) ---- */
$svgW = 700; $svgH = 260;
$mL = 34; $mR = 10; $mT = 10; $mB = 26;
$plotW = $svgW - $mL - $mR;
$plotH = $svgH - $mT - $mB;

$xForMonth = fn(int $m) => $mL + (($m - 1) / 11) * $plotW; // m: 1-12
$yForVal   = fn(float $v) => $mT + (1 - max(0, min(100, $v)) / 100) * $plotH;

$monthAbbr = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$linePaths = [];
foreach ($data['types'] as $type) {
    $d = '';
    foreach (range(1, 12) as $m) {
        $x = $xForMonth($m);
        $y = $yForVal((float) $data['occupancy'][$type][$m]);
        $d .= ($m === 1 ? 'M' : ' L') . sprintf('%.2F,%.2F', $x, $y);
    }
    $linePaths[$type] = $d;
}

/* Per-month tooltip data for the JS hover layer */
$trendJson = [];
foreach (range(1, 12) as $m) {
    $row = ['month' => $monthAbbr[$m - 1], 'x' => round($xForMonth($m), 2)];
    foreach ($data['types'] as $type) {
        $row[$type] = (int) $data['occupancy'][$type][$m];
    }
    $trendJson[] = $row;
}

$maxRankBookings = 0;
foreach ($data['rankings'] as $r) { $maxRankBookings = max($maxRankBookings, (int) $r['bookings']); }
?>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="rpt-stat-grid">
    <?php foreach ($data['types'] as $type): ?>
      <?php $p = $data['peak'][$type]; ?>
      <div class="rpt-stat-card rpt-stat--<?php echo $cardTint[$type]; ?>">
        <div class="rpt-stat-card-top"><?php echo icon($type === 'Room' ? 'bed' : ($type === 'Event Hall' ? 'grid' : ($type === 'Cottage' ? 'moon' : 'calendar')), 'icon-sm'); ?> <?php echo htmlspecialchars(rpt_type_short($type), ENT_QUOTES, 'UTF-8'); ?></div>
        <span class="rpt-stat-value"><?php echo htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="rpt-stat-sub">Peak Season · <?php echo (int) $p['pct']; ?>%</span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="rpt-chart-panel-grid">
    <div class="panel panel-pad">
      <div class="panel-header">
        <h2 class="panel-title">Seasonal Performance Trends</h2>
      </div>
      <p class="panel-subtext">Monthly occupancy across all categories · <?php echo (int) $data['year']; ?></p>

      <div class="rpt-trend-svg-wrap" data-rpt-trend data-trend='<?php echo htmlspecialchars(json_encode($trendJson), ENT_QUOTES, "UTF-8"); ?>'>
        <svg viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>" class="rpt-trend-svg" data-rpt-trend-svg>
          <?php foreach ([0, 25, 50, 75, 100] as $g): ?>
            <line x1="<?php echo $mL; ?>" y1="<?php echo $yForVal($g); ?>" x2="<?php echo $svgW - $mR; ?>" y2="<?php echo $yForVal($g); ?>" stroke="var(--border)" stroke-width="1"></line>
            <text x="<?php echo $mL - 8; ?>" y="<?php echo $yForVal($g) + 3; ?>" text-anchor="end" class="rpt-trend-axis-label"><?php echo $g; ?></text>
          <?php endforeach; ?>

          <?php foreach ($monthAbbr as $i => $mn): ?>
            <text x="<?php echo $xForMonth($i + 1); ?>" y="<?php echo $svgH - 6; ?>" text-anchor="middle" class="rpt-trend-axis-label"><?php echo $mn; ?></text>
          <?php endforeach; ?>

          <?php foreach ($data['types'] as $type): ?>
            <path d="<?php echo $linePaths[$type]; ?>" fill="none" stroke="<?php echo $lineColor[$type]; ?>" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round"></path>
          <?php endforeach; ?>

          <line data-rpt-trend-guide x1="0" y1="<?php echo $mT; ?>" x2="0" y2="<?php echo $svgH - $mB; ?>" stroke="var(--border-strong)" stroke-width="1" opacity="0"></line>
          <?php foreach ($data['types'] as $type): ?>
            <circle data-rpt-trend-dot="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>" r="3.5" fill="<?php echo $lineColor[$type]; ?>" stroke="var(--panel)" stroke-width="1.5" opacity="0"></circle>
          <?php endforeach; ?>

          <rect data-rpt-trend-hitbox x="<?php echo $mL; ?>" y="<?php echo $mT; ?>" width="<?php echo $plotW; ?>" height="<?php echo $plotH; ?>" fill="transparent"></rect>
        </svg>
        <div class="rpt-trend-tooltip" data-rpt-trend-tooltip></div>
      </div>

      <div class="rpt-trend-legend">
        <?php foreach ($data['types'] as $type): ?>
          <span><i style="background: <?php echo $lineColor[$type]; ?>"></i> <?php echo htmlspecialchars(rpt_type_short($type), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel panel-pad">
      <div class="panel-header">
        <h2 class="panel-title">Performance Rankings</h2>
      </div>
      <p class="panel-subtext">Based on total bookings · <?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></p>

      <?php if (empty($data['rankings'])): ?>
        <?php render_empty_state('trend-up', 'No bookings yet', 'Rankings will appear here once bookings come in for this period.'); ?>
      <?php else: ?>
        <div class="rpt-rank-list">
          <?php foreach ($data['rankings'] as $i => $r): ?>
            <?php $pct = $maxRankBookings > 0 ? round(((int) $r['bookings'] / $maxRankBookings) * 100) : 0; ?>
            <div class="rpt-rank-row">
              <span class="rpt-rank-badge"><?php echo $i + 1; ?></span>
              <div class="rpt-rank-body">
                <div class="rpt-rank-top">
                  <span class="rpt-rank-name"><?php echo htmlspecialchars(rpt_type_label($r['label']), ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="rpt-rank-revenue">₱<?php echo number_format((float) $r['revenue'], 0); ?></span>
                </div>
                <div class="rpt-rank-sub"><?php echo (int) $r['bookings']; ?> booking<?php echo ((int) $r['bookings'] === 1 ? '' : 's'); ?></div>
                <div class="rpt-bar-track"><div class="rpt-bar-fill" style="width: <?php echo $pct; ?>%"></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Page-specific: SVG chart axis labels + responsive sizing (small enough to keep inline, same convention as dashboard.php's page-scoped styles) */
.rpt-trend-svg { width: 100%; height: auto; display: block; }
.rpt-trend-axis-label { font-size: 9px; fill: var(--text-tertiary); font-family: var(--font-body); }
.panel-subtext { color: var(--text-tertiary); font-size: 0.8rem; margin-top: -8px; margin-bottom: 16px; }
</style>

<script>
(function () {
    var wrap = document.querySelector('[data-rpt-trend]');
    if (!wrap) return;
    var svg = wrap.querySelector('[data-rpt-trend-svg]');
    var hitbox = wrap.querySelector('[data-rpt-trend-hitbox]');
    var guide = wrap.querySelector('[data-rpt-trend-guide]');
    var tooltip = wrap.querySelector('[data-rpt-trend-tooltip]');
    var dots = {};
    svg.querySelectorAll('[data-rpt-trend-dot]').forEach(function (d) {
        dots[d.getAttribute('data-rpt-trend-dot')] = d;
    });

    var months = JSON.parse(wrap.getAttribute('data-trend'));
    var types = <?php echo json_encode($data['types']); ?>;
    var typeLabels = <?php echo json_encode(array_map('rpt_type_short', $data['types'])); ?>;

    function svgPoint(evt) {
        var pt = svg.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        var ctm = svg.getScreenCTM();
        if (!ctm) return null;
        return pt.matrixTransform(ctm.inverse());
    }

    function showMonth(idx) {
        var m = months[idx];
        if (!m) return;
        guide.setAttribute('x1', m.x);
        guide.setAttribute('x2', m.x);
        guide.setAttribute('opacity', '1');

        var yFor = function (v) {
            var mT = <?php echo $mT; ?>, plotH = <?php echo $plotH; ?>;
            return mT + (1 - Math.max(0, Math.min(100, v)) / 100) * plotH;
        };

        var html = '<strong>' + m.month + '</strong>';
        types.forEach(function (t, i) {
            var val = m[t];
            var dot = dots[t];
            if (dot) {
                dot.setAttribute('cx', m.x);
                dot.setAttribute('cy', yFor(val));
                dot.setAttribute('opacity', '1');
            }
            html += typeLabels[i] + ' : ' + val + '%<br>';
        });
        tooltip.innerHTML = html;
        tooltip.classList.add('is-visible');

        var wrapRect = wrap.getBoundingClientRect();
        var svgRect = svg.getBoundingClientRect();
        var scaleX = svgRect.width / <?php echo $svgW; ?>;
        var left = (m.x * scaleX) + (svgRect.left - wrapRect.left);
        left = Math.min(left, wrapRect.width - 140);
        tooltip.style.left = Math.max(0, left - 60) + 'px';
        tooltip.style.top = '4px';
    }

    function hide() {
        guide.setAttribute('opacity', '0');
        Object.keys(dots).forEach(function (k) { dots[k].setAttribute('opacity', '0'); });
        tooltip.classList.remove('is-visible');
    }

    hitbox.addEventListener('mousemove', function (evt) {
        var p = svgPoint(evt);
        if (!p) return;
        var mL = <?php echo $mL; ?>, plotW = <?php echo $plotW; ?>;
        var frac = (p.x - mL) / plotW;
        var idx = Math.round(frac * 11);
        idx = Math.max(0, Math.min(11, idx));
        showMonth(idx);
    });
    hitbox.addEventListener('mouseleave', hide);

    // Default to the current/peak month so the chart isn't empty on load
    showMonth(<?php echo (int) date('n', strtotime($period['end'])) - 1; ?>);
})();
</script>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>
