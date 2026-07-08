<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'expenses';
$page_title = 'Expenses';
$breadcrumb = 'Expenses';
$page_action_label = 'Add Expense';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('This Month', '₱0.00', 'trend-down', 'red');
      render_stat_card('Last Month', '₱0.00', 'calendar', 'blue');
      render_stat_card('Categories', '0', 'clipboard', 'amber');
    ?>
  </div>

  <?php
    render_table_empty(
        ['Date', 'Category', 'Description', 'Amount'],
        'trend-down',
        'No expenses recorded',
        'Log operational costs here to keep track of your spending.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
