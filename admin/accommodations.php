<?php
$active_page = 'accommodations';
$page_title = 'Accommodations';
$breadcrumb = 'Accommodations';
$page_action_label = 'Add Accommodation';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('Total Units', '0', 'bed', 'blue');
      render_stat_card('Available', '0', 'calendar-check', 'emerald');
      render_stat_card('Under Maintenance', '0', 'alert-triangle', 'amber');
    ?>
  </div>

  <?php
    render_empty_panel(
        'bed',
        'No accommodations added yet',
        'Add your first room, hut, or rental unit to start accepting bookings.',
        'Add Accommodation'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
