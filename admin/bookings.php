<?php
$active_page = 'bookings';
$page_title = 'Bookings';
$breadcrumb = 'Bookings';
$page_action_label = 'New Booking';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('Total Bookings', '0', 'calendar', 'blue');
      render_stat_card('Confirmed', '0', 'calendar-check', 'emerald');
      render_stat_card('Cancelled', '0', 'close', 'red');
    ?>
  </div>

  <?php
    render_table_empty(
        ['Guest Name', 'Room', 'Check-in', 'Check-out', 'Status', 'Amount'],
        'calendar',
        'No bookings yet',
        'New bookings will appear here once guests start reserving rooms.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
