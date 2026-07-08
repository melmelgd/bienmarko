<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'report';
$page_title = 'Reports';
$breadcrumb = 'Report';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';

$reports = [
    ['title' => 'Revenue Report',   'icon' => 'bar-chart',  'desc' => 'Summary of income across bookings and payments.'],
    ['title' => 'Occupancy Report', 'icon' => 'pie-chart',  'desc' => 'Track room and unit utilization over time.'],
    ['title' => 'Expense Report',   'icon' => 'trend-down', 'desc' => 'Breakdown of operational costs by category.'],
    ['title' => 'Guest Report',     'icon' => 'users',      'desc' => 'Guest activity and booking history.'],
];
?>

<div class="grid-2">
  <?php foreach ($reports as $r): ?>
    <div class="panel panel-pad report-card">
      <span class="report-card-icon"><?php echo icon($r['icon']); ?></span>
      <div>
        <h3><?php echo htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
        <p><?php echo htmlspecialchars($r['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
      <button type="button" class="btn-text">
        Generate Report <?php echo icon('chevron-right', 'icon-sm'); ?>
      </button>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
