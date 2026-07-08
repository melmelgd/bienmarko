<?php
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';

$active_page = 'inventory';
$page_title = 'Inventory';
$breadcrumb = 'Inventory';
$page_action_label = 'Add Item';
$show_period_toggle = false;

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require_once __DIR__ . '/includes/ui.php';
?>

<div class="stack">
  <div class="stat-grid">
    <?php
      render_stat_card('Total Items', '0', 'boxes', 'blue');
      render_stat_card('Low Stock', '0', 'alert-triangle', 'amber');
      render_stat_card('Out of Stock', '0', 'close', 'red');
    ?>
  </div>

  <?php
    render_table_empty(
        ['Item', 'Category', 'Quantity', 'Unit', 'Status'],
        'clipboard',
        'No inventory items yet',
        'Track linens, toiletries, and supplies once you add inventory items.'
    );
  ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
