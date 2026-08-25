<?php
/**
 * admin/report-financial.php
 * -----------------------------------------------------------------------
 * Financial Reports: Total Revenue / Expenses / Net Profit for the
 * selected period, revenue broken down by accommodation type and by
 * individual accommodation, and a Payment Collection Status summary.
 * Data comes entirely from includes/reports-data.php so the on-screen
 * numbers and the PDF/XLSX export (reports-export.php) can never drift
 * apart.
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
$data   = reports_financial_data($pdo, $period['start'], $period['end']);

$active_page         = 'report';
$page_title          = 'Financial Reports';
$breadcrumb          = 'Financial Reports';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('financial', $period);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';

/* Widest bar in each breakdown list defines the 100% baseline so bars
   are relative to each other within their own panel. */
$maxType = 0;
foreach ($data['byType'] as $r) { $maxType = max($maxType, (float) $r['amount']); }
$maxAcc = 0;
foreach ($data['byAccommodation'] as $r) { $maxAcc = max($maxAcc, (float) $r['amount']); }

$accShown = array_slice($data['byAccommodation'], 0, 4);
$accRest  = array_slice($data['byAccommodation'], 4);
?>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="rpt-stat-grid">
    <div class="rpt-stat-card rpt-stat--green">
      <div class="rpt-stat-card-top"><?php echo icon('trend-up', 'icon-sm'); ?> Total Revenue</div>
      <span class="rpt-stat-value">₱<?php echo number_format($data['revenue'], 2); ?></span>
      <span class="rpt-stat-sub"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--red">
      <div class="rpt-stat-card-top"><?php echo icon('trend-down', 'icon-sm'); ?> Total Expenses</div>
      <span class="rpt-stat-value">₱<?php echo number_format($data['expenses'], 2); ?></span>
      <span class="rpt-stat-sub"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--blue">
      <div class="rpt-stat-card-top"><?php echo icon('wallet', 'icon-sm'); ?> Net Profit</div>
      <span class="rpt-stat-value">₱<?php echo number_format($data['profit'], 2); ?></span>
      <span class="rpt-stat-sub"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  </div>

  <div class="rpt-panel-grid-2">
    <div class="panel panel-pad">
      <div class="panel-header">
        <h2 class="panel-title">Revenue Breakdown by Accommodation</h2>
      </div>
      <?php if (empty($data['byType'])): ?>
        <?php render_empty_state('bar-chart', 'No revenue yet', 'Revenue by accommodation type will appear here once bookings come in.'); ?>
      <?php else: ?>
        <div class="rpt-bar-list">
          <?php foreach ($data['byType'] as $row): ?>
            <?php $pct = $maxType > 0 ? round(((float) $row['amount'] / $maxType) * 100) : 0; ?>
            <div class="rpt-bar-row">
              <div class="rpt-bar-row-head">
                <span class="rpt-bar-label"><?php echo htmlspecialchars(rpt_type_label($row['label']), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="rpt-bar-amount">₱<?php echo number_format((float) $row['amount'], 0); ?></span>
              </div>
              <div class="rpt-bar-track"><div class="rpt-bar-fill" style="width: <?php echo $pct; ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel panel-pad">
      <div class="panel-header">
        <h2 class="panel-title">Revenue Breakdown by Accommodation Type</h2>
      </div>
      <?php if (empty($accShown)): ?>
        <?php render_empty_state('bar-chart', 'No revenue yet', 'Top-performing accommodations will appear here once bookings come in.'); ?>
      <?php else: ?>
        <div class="rpt-bar-list">
          <?php foreach ($accShown as $row): ?>
            <?php $pct = $maxAcc > 0 ? round(((float) $row['amount'] / $maxAcc) * 100) : 0; ?>
            <div class="rpt-bar-row">
              <div class="rpt-bar-row-head">
                <span class="rpt-bar-label"><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="rpt-bar-amount">₱<?php echo number_format((float) $row['amount'], 0); ?></span>
              </div>
              <div class="rpt-bar-track"><div class="rpt-bar-fill" style="width: <?php echo $pct; ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($accRest)): ?>
          <div class="rpt-see-all-wrap">
            <a href="accommodations.php" class="btn-text">See All <?php echo icon('chevron-right', 'icon-sm'); ?></a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel panel-pad">
    <div class="panel-header">
      <h2 class="panel-title">Payment Collection Status</h2>
    </div>
    <div class="rpt-collect-grid">
      <div>
        <div class="rpt-collect-label"><?php echo icon('wallet', 'icon-sm'); ?> Paid</div>
        <div class="rpt-collect-amount rpt-collect-amount--paid">₱<?php echo number_format($data['paidTotal'], 2); ?></div>
        <div class="rpt-collect-count"><?php echo (int) $data['paidCount']; ?> Transaction<?php echo $data['paidCount'] === 1 ? '' : 's'; ?></div>
      </div>
      <div>
        <div class="rpt-collect-label"><?php echo icon('clock', 'icon-sm'); ?> Pending</div>
        <div class="rpt-collect-amount rpt-collect-amount--pending">₱<?php echo number_format($data['pendingTotal'], 2); ?></div>
        <div class="rpt-collect-count"><?php echo (int) $data['pendingCount']; ?> Transaction<?php echo $data['pendingCount'] === 1 ? '' : 's'; ?></div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>
