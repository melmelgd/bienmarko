<?php
/**
 * admin/report.php
 * -----------------------------------------------------------------------
 * Reports & Analytics overview. Four cards — Financial, Performance,
 * Guest Insights, Inventory — each pulling a couple of live quick-stats
 * from the same shared data layer (includes/reports-data.php) the
 * detailed sub-report pages use, and linking through to them.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/reports-data.php';
require_once __DIR__ . '/includes/reports-toolbar.php';

$active_page        = 'report';
$page_title         = 'Reports & Analytics';
$breadcrumb         = 'Report';
$page_action_label  = null;
$show_period_toggle = false;
$topbar_extra_right = null; // no toolbar on the overview page itself

$pdo = bienmarko_db();
reports_ensure_core_tables($pdo);

/* This month, for every card's quick stats */
$thisMonth = reports_resolve_period(['period' => 'month']);

$fin  = reports_financial_data($pdo, $thisMonth['start'], $thisMonth['end']);
$perf = reports_performance_data($pdo, $thisMonth['start'], $thisMonth['end']);
$gi   = reports_guest_insights_data($pdo, $thisMonth['start'], $thisMonth['end']);
$inv  = reports_inventory_data($pdo, $thisMonth['start'], $thisMonth['end']);

/* Performance card's headline: the single best-performing type this
   month and its peak-season label (already computed for the year). */
$topType = $perf['rankings'][0]['label'] ?? null;
$topTypePeak = $topType ? ($perf['peak'][$topType]['label'] ?? '—') : '—';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';
?>

<div class="stack">
  <div class="rpt-overview-grid">

    <!-- Financial Reports -->
    <a href="report-financial.php" class="panel panel-pad rpt-overview-card">
      <div class="rpt-overview-card-head">
        <div>
          <h3>Financial Reports</h3>
          <p>Revenue breakdown, expenses, and profit analysis</p>
        </div>
        <span class="report-card-icon"><?php echo icon('wallet'); ?></span>
      </div>
      <div class="rpt-overview-stats">
        <div>
          <span class="rpt-overview-stat-label">Revenue</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--green">₱<?php echo number_format($fin['revenue'] / 1000, 0); ?>K</span>
        </div>
        <div>
          <span class="rpt-overview-stat-label">Expenses</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--red">₱<?php echo number_format($fin['expenses'] / 1000, 0); ?>K</span>
        </div>
        <div>
          <span class="rpt-overview-stat-label">Profit</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--blue">₱<?php echo number_format($fin['profit'] / 1000, 0); ?>K</span>
        </div>
      </div>
      <span class="btn-text">View Report <?php echo icon('chevron-right', 'icon-sm'); ?></span>
    </a>

    <!-- Performance -->
    <a href="report-performance.php" class="panel panel-pad rpt-overview-card">
      <div class="rpt-overview-card-head">
        <div>
          <h3>Performance</h3>
          <p>Accommodation performance</p>
        </div>
        <span class="report-card-icon"><?php echo icon('trend-up'); ?></span>
      </div>
      <div class="rpt-overview-stats">
        <div>
          <span class="rpt-overview-stat-label">Top Performer</span>
          <span class="rpt-overview-stat-value"><?php echo htmlspecialchars($topType ? rpt_type_short($topType) : '—', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div>
          <span class="rpt-overview-stat-label">Peak Season</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--green"><?php echo htmlspecialchars($topTypePeak, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
      <span class="btn-text">View Report <?php echo icon('chevron-right', 'icon-sm'); ?></span>
    </a>

    <!-- Guest Insights -->
    <a href="report-guest-insights.php" class="panel panel-pad rpt-overview-card">
      <div class="rpt-overview-card-head">
        <div>
          <h3>Guest Insights</h3>
          <p>Demographics &amp; customer analytics</p>
        </div>
        <span class="report-card-icon"><?php echo icon('users'); ?></span>
      </div>
      <div class="rpt-overview-stats">
        <div>
          <span class="rpt-overview-stat-label">Total Guests</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--blue"><?php echo (int) $gi['totalGuests']; ?></span>
        </div>
        <div>
          <span class="rpt-overview-stat-label">Repeat Guests</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--green"><?php echo (int) $gi['repeatGuests']; ?></span>
        </div>
      </div>
      <span class="btn-text">View Report <?php echo icon('chevron-right', 'icon-sm'); ?></span>
    </a>

    <!-- Inventory Reports -->
    <a href="report-inventory.php" class="panel panel-pad rpt-overview-card">
      <div class="rpt-overview-card-head">
        <div>
          <h3>Inventory Reports</h3>
          <p>Stock levels and usage tracking</p>
        </div>
        <span class="report-card-icon"><?php echo icon('boxes'); ?></span>
      </div>
      <div class="rpt-overview-stats">
        <div>
          <span class="rpt-overview-stat-label">Total Items</span>
          <span class="rpt-overview-stat-value"><?php echo (int) $inv['totalItems']; ?></span>
        </div>
        <div>
          <span class="rpt-overview-stat-label">Low Stock</span>
          <span class="rpt-overview-stat-value rpt-overview-stat-value--red"><?php echo (int) $inv['lowStock']; ?></span>
        </div>
      </div>
      <span class="btn-text">View Report <?php echo icon('chevron-right', 'icon-sm'); ?></span>
    </a>

  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
