<?php
/**
 * admin/report-inventory.php
 * -----------------------------------------------------------------------
 * Inventory Reports: stock-level stat cards, the full item table with
 * status badges (In Stock / Low Stock / Out of Stock), and a Most Used
 * Items panel based on usage logged in the selected period. Reads the
 * same inventory_items / inventory_logs tables admin/inventory.php
 * manages — this page is read-only, no new inventory tables.
 *
 * Design note: the fourth overview stat is "Total Stock Units" (sum of
 * on-hand quantity across all items) — the source mockup showed two
 * cards both labelled "Inventory Value", which looks like a labeling
 * slip in the mockup rather than an intentional duplicate metric, so a
 * distinct fourth metric was chosen here instead of literally repeating
 * the third card.
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
$data   = reports_inventory_data($pdo, $period['start'], $period['end']);

$active_page         = 'report';
$page_title          = 'Inventory Reports';
$breadcrumb          = 'Inventory Reports';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('inventory', $period);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';

$statusMeta = [
    'in_stock'     => ['label' => 'In Stock',     'class' => 'sb--green'],
    'low_stock'    => ['label' => 'Low Stock',    'class' => 'sb--amber'],
    'out_of_stock' => ['label' => 'Out of Stock', 'class' => 'sb--red'],
];
?>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="rpt-stat-grid">
    <div class="rpt-stat-card rpt-stat--blue">
      <div class="rpt-stat-card-top"><?php echo icon('boxes', 'icon-sm'); ?> Total Items</div>
      <span class="rpt-stat-value"><?php echo (int) $data['totalItems']; ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--rose">
      <div class="rpt-stat-card-top"><?php echo icon('alert-triangle', 'icon-sm'); ?> Low Stock Items</div>
      <span class="rpt-stat-value"><?php echo (int) $data['lowStock']; ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--green">
      <div class="rpt-stat-card-top"><?php echo icon('trend-up', 'icon-sm'); ?> Inventory Value</div>
      <span class="rpt-stat-value">₱<?php echo number_format($data['totalValue'], 0); ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--lime">
      <div class="rpt-stat-card-top"><?php echo icon('pie-chart', 'icon-sm'); ?> Total Stock Units</div>
      <span class="rpt-stat-value"><?php echo (int) $data['totalUnits']; ?></span>
    </div>
  </div>

  <div class="panel panel-pad">
    <?php if (empty($data['items'])): ?>
      <?php render_empty_state('boxes', 'No inventory items yet', 'Items added on the Inventory page will show up here.'); ?>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Item Name</th>
              <th>Quantity</th>
              <th>Min Stock</th>
              <th>Cost</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['items'] as $it): ?>
              <?php $st = $statusMeta[reports_inv_status($it)]; ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                <td><?php echo (int) $it['quantity']; ?> units</td>
                <td><?php echo (int) $it['min_stock_level']; ?> units</td>
                <td>₱<?php echo number_format((float) $it['cost_per_unit'] * (int) $it['quantity'], 0); ?></td>
                <td><span class="sb <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel panel-pad rpt-light-panel">
    <div class="panel-header">
      <h2 class="panel-title">Most Used Items</h2>
    </div>

    <?php if (empty($data['mostUsed'])): ?>
      <?php render_empty_state('boxes', 'No usage logged yet', 'Usage recorded on the Inventory page for ' . htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8') . ' will show up here.'); ?>
    <?php else: ?>
      <div class="rpt-used-list">
        <?php foreach ($data['mostUsed'] as $m): ?>
          <div class="rpt-bar-row">
            <div class="rpt-used-row-head">
              <span class="rpt-used-label"><?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="rpt-used-pct"><?php echo (int) $m['pct']; ?>%</span>
            </div>
            <div class="rpt-bar-track"><div class="rpt-bar-fill" style="width: <?php echo (int) $m['pct']; ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>
