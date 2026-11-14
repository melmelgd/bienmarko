<?php
/**
 * admin/report-inventory.php
 * -----------------------------------------------------------------------
 * Inventory Reports: Total Items / Needs Restocking cards, the stock
 * table (Beginning, Added, Total, Used, Remaining, cost, status) and a
 * Most Used Items panel (Used ÷ Total Stock). Reads the same
 * inventory_items / inventory_logs tables admin/inventory.php manages —
 * this page is read-only, no new tables.
 *
 *   Total Stock      = Beginning Stock + Added Stock (everything received)
 *   Used             = Total Stock - Remaining
 *   Price per Unit   = cost_per_unit saved on the Inventory page
 *   Total Stock Cost = Total Stock x cost per unit
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


/* ------------------------------------------------------------------
   Item rows — same ordering and maths as the Inventory page
------------------------------------------------------------------ */
$restockedMap = [];
foreach ($pdo->query("SELECT item_id, SUM(change_qty) AS q FROM inventory_logs WHERE reason = 'restock' AND change_qty > 0 GROUP BY item_id")->fetchAll() as $rs) {
    $restockedMap[(int) $rs['item_id']] = (int) $rs['q'];
}

$items = [];
$needsRestock = 0; $lowCount = 0; $outCount = 0;
foreach ($pdo->query('SELECT * FROM inventory_items ORDER BY created_at DESC, id DESC')->fetchAll() as $it) {
    $remaining = (int) $it['quantity'];
    $total     = max((int) ($it['total_quantity'] ?? 0), $remaining);
    $restocked = min($total, $restockedMap[(int) $it['id']] ?? 0);
    $st        = reports_inv_status($it);
    if ($st === 'low_stock')    { $lowCount++; }
    if ($st === 'out_of_stock') { $outCount++; }
    $items[] = [
        'name'      => $it['name'],
        'category'  => $it['category'] ?? '',
        'opening'   => max(0, $total - $restocked),
        'restocked' => $restocked,
        'total'     => $total,
        'used'      => max(0, $total - $remaining),
        'remaining' => $remaining,
        'unit_cost' => round((float) $it['cost_per_unit'], 2),
        'cost'      => round($total * (float) $it['cost_per_unit'], 2),
        'status'    => $st,
    ];
}
$needsRestock = $lowCount + $outCount;

/* Most used = Used ÷ Total Stock, highest first (only items that have been used) */
$mostUsed = [];
foreach ($items as $it) {
    if ($it['used'] > 0 && $it['total'] > 0) {
        $mostUsed[] = ['name' => $it['name'], 'pct' => (int) round($it['used'] / $it['total'] * 100)];
    }
}
usort($mostUsed, function ($a, $b) { return $b['pct'] <=> $a['pct']; });

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

<style>
/* ============ Inventory report — page-scoped styles ============ */
.ir-kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.ir-kpi { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 24px; }
.ir-kpi-label { font-size: 16px; color: var(--text-secondary); }
.ir-kpi-value { display: block; font-family: var(--font-display); font-size: 38px; font-weight: 700; line-height: 1; margin: 20px 0 12px; }
.ir-kpi-value--blue { color: var(--blue); }
.ir-kpi-value--gold { color: var(--gold-400); }
.ir-kpi-sub { font-size: 15px; color: var(--text-secondary); }

.ir-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 24px; }
.ir-table { width: 100%; border-collapse: collapse; }
.ir-table thead th { text-align: left; padding: 12px 12px; font-size: 14px; font-weight: 500; color: var(--text-secondary); border-bottom: 1px solid var(--border); white-space: nowrap; }
.ir-table tbody td { padding: 14px 12px; font-size: 16px; color: var(--text); vertical-align: middle; }
.ir-table tbody tr { border-bottom: 1px solid var(--border); }
.ir-table tbody tr:last-child { border-bottom: none; }
.ir-table th:first-child, .ir-table td:first-child { padding-left: 12px; }
.ir-name { font-weight: 700; font-size: 17px; }
.ir-cat  { font-size: 14px; color: var(--text-secondary); margin-top: 2px; }
.ir-opening { color: var(--gold-400); font-weight: 600; }
.ir-added   { color: var(--emerald); }
.ir-dash    { color: var(--text-tertiary); }
.ir-note { font-size: 15px; color: var(--text-secondary); margin: 14px 0 0 0; }

.ir-used-title { display: flex; align-items: baseline; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.ir-used-title h2 { font-family: var(--font-display); font-size: 25px; font-weight: 700; margin: 0; }
.ir-used-title span { font-size: 14px; color: var(--text-secondary); }
.ir-used-list { display: flex; flex-direction: column; gap: 20px; }
.ir-used-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 9px; font-size: 17px; }
.ir-used-pct { font-weight: 700; }
.ir-track { height: 9px; border-radius: var(--r-full); background: var(--panel-alt); overflow: hidden; }
.ir-fill  { height: 100%; border-radius: var(--r-full); background: var(--gold-500); }

@media (max-width: 700px) { .ir-kpi-grid { grid-template-columns: 1fr; } }
@media print { .ir-kpi, .ir-panel { background: #fff !important; border: 1px solid #ddd !important; break-inside: avoid; } }
</style>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="ir-kpi-grid">
    <div class="ir-kpi">
      <div class="ir-kpi-label">Total Items</div>
      <span class="ir-kpi-value ir-kpi-value--blue"><?php echo count($items); ?></span>
      <div class="ir-kpi-sub">Tracked in inventory</div>
    </div>
    <div class="ir-kpi">
      <div class="ir-kpi-label">Needs Restocking</div>
      <span class="ir-kpi-value ir-kpi-value--gold"><?php echo $needsRestock; ?></span>
      <div class="ir-kpi-sub"><?php echo $lowCount; ?> low · <?php echo $outCount; ?> out of stock</div>
    </div>
  </div>

  <div class="ir-panel">
    <?php if (empty($items)): ?>
      <?php render_empty_state('boxes', 'No inventory items yet', 'Items added on the Inventory page will show up here.'); ?>
    <?php else: ?>
      <div class="table-scroll">
        <table class="ir-table">
          <thead>
            <tr>
              <th>Item Name</th>
              <th>Beginning Stock</th>
              <th>Added Stock</th>
              <th>Total Stock</th>
              <th>Used</th>
              <th>Remaining</th>
              <th>Price per Unit</th>
              <th>Total Stock Cost</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <?php $st = $statusMeta[$it['status']]; ?>
              <tr>
                <td>
                  <div class="ir-name"><?php echo htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if ($it['category'] !== ''): ?><div class="ir-cat"><?php echo htmlspecialchars($it['category'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                </td>
                <td class="ir-opening"><?php echo (int) $it['opening']; ?></td>
                <td><?php if ($it['restocked'] > 0): ?><span class="ir-added">+<?php echo (int) $it['restocked']; ?></span><?php else: ?><span class="ir-dash">—</span><?php endif; ?></td>
                <td><?php echo (int) $it['total']; ?></td>
                <td><?php echo (int) $it['used']; ?></td>
                <td><?php echo (int) $it['remaining']; ?></td>
                <td>₱<?php echo number_format($it['unit_cost'], 2); ?></td>
                <td>₱<?php echo number_format($it['cost'], 2); ?></td>
                <td><span class="sb <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="ir-note">Total Stock = Beginning Stock + Added Stock. Total Stock Cost is the cost of all Total Stock purchased.</p>
    <?php endif; ?>
  </div>

  <div class="ir-panel">
    <div class="ir-used-title">
      <h2>Most Used Items</h2>
      <span>Used ÷ Total Stock</span>
    </div>

    <?php if (empty($mostUsed)): ?>
      <?php render_empty_state('boxes', 'No usage logged yet', 'Items that have been used will show up here.'); ?>
    <?php else: ?>
      <div class="ir-used-list">
        <?php foreach ($mostUsed as $m): ?>
          <div>
            <div class="ir-used-head">
              <span><?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="ir-used-pct"><?php echo (int) $m['pct']; ?>%</span>
            </div>
            <div class="ir-track"><div class="ir-fill" style="width: <?php echo (int) $m['pct']; ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>