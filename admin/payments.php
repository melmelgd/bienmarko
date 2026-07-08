<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'payments';
$page_title = 'Payments';
$breadcrumb = 'Payments';
$page_action_label = 'Record Payment';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('Total Collected', '₱0.00', 'wallet', 'emerald');
      render_stat_card('Pending', '₱0.00', 'clock', 'amber');
      render_stat_card('Refunded', '₱0.00', 'refresh', 'red');
    ?>
  </div>

  <?php
    render_table_empty(
        ['Guest', 'Booking ID', 'Method', 'Amount', 'Status', 'Date'],
        'receipt',
        'No payment records yet',
        'Payments linked to bookings will show up here once recorded.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
