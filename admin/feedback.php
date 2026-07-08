<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'feedback';
$page_title = 'Guest Feedback';
$breadcrumb = 'Feedback';
$page_action_label = null;
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('Total Feedback', '0', 'message', 'blue');
      render_stat_card('Average Rating', '--', 'star', 'amber');
      render_stat_card('Unread', '0', 'mail', 'red');
    ?>
  </div>

  <?php
    render_empty_panel(
        'message',
        'No feedback yet',
        'Guest reviews and feedback will be collected here once submitted.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
