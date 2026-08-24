<?php
/**
 * admin/inventory.php
 * -----------------------------------------------------------------------
 * Full live inventory management page. Tracks linens, toiletries, and
 * other resort supplies: stock levels, minimum-stock alerts, per-unit
 * cost, and expiration dates. Admins can add, view, edit, and delete
 * items. Stock status (In Stock / Low Stock / Out of Stock) is computed
 * from quantity vs. minimum stock level rather than stored, so it's
 * always correct after any edit.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';

$active_page        = 'inventory';
$page_title          = 'Inventory';
$breadcrumb          = 'Inventory';
$page_action_label   = 'Add Item';
$show_period_toggle  = false;

$pdo  = bienmarko_db();
$csrf = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure inventory tables exist (additive, self-guarding — same
   approach admin/payments.php uses for its own tables)
------------------------------------------------------------------ */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `inventory_items` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name`            VARCHAR(150) NOT NULL,
        `category`        VARCHAR(60)  NOT NULL DEFAULT 'Others',
        `quantity`        INT UNSIGNED NOT NULL DEFAULT 0,
        `min_stock_level` INT UNSIGNED NOT NULL DEFAULT 0,
        `cost_per_unit`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `expiration_date` DATE NULL DEFAULT NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `inventory_logs` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `item_id`    INT UNSIGNED NOT NULL,
        `change_qty` INT NOT NULL,
        `reason`     ENUM('initial','restock','usage','adjustment') NOT NULL DEFAULT 'adjustment',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_inv_log_item` (`item_id`),
        CONSTRAINT `fk_inv_log_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

const INV_CATEGORIES = ['Linens', 'Toiletries', 'Cleaning Supplies', 'Kitchen & Dining', 'Beverages', 'Maintenance Supplies', 'Amenities', 'Others'];

/** Stock status is derived, never stored, so it's always in sync. */
function inv_status(array $item): string
{
    $qty = (int) $item['quantity'];
    $min = (int) $item['min_stock_level'];
    if ($qty <= 0) return 'out_of_stock';
    if ($qty <= $min) return 'low_stock';
    return 'in_stock';
}

function inv_status_label(string $status): string
{
    if ($status === 'out_of_stock') return 'Out of Stock';
    if ($status === 'low_stock') return 'Low Stock';
    return 'In Stock';
}

function inv_status_badge(string $status): string
{
    $map = [
        'in_stock'     => 'sb--green',
        'low_stock'    => 'sb--amber',
        'out_of_stock' => 'sb--red',
    ];
    $cls = $map[$status] ?? 'sb--grey';
    return '<span class="sb ' . $cls . '">' . htmlspecialchars(inv_status_label($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * A couple of extra inline icons this page needs that aren't in the
 * shared admin/includes/icons.php registry. Kept local here — same
 * approach admin/includes/accommodations-data.php uses for acc_icon().
 * Falls through to the shared icon() helper for every other name.
 */
function inv_icon(string $name, string $class = 'icon'): string
{
    $extra = [
        'eye'   => '<path d="M1 12s4-7.5 11-7.5S23 12 23 12s-4 7.5-11 7.5S1 12 1 12Z"></path><circle cx="12" cy="12" r="3"></circle>',
        'trash' => '<polyline points="4 7 20 7"></polyline><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path><path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'search'=> '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.2" y2="16.2"></line>',
    ];
    if (isset($extra[$name])) {
        $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $extra[$name] . '</svg>';
    }
    return icon($name, $class);
}

/* ------------------------------------------------------------------
   Filters
------------------------------------------------------------------ */
$filterCategory = trim($_GET['category'] ?? '');
$filterStatus    = trim($_GET['stock_status'] ?? '');
$filterSearch    = trim($_GET['search'] ?? '');

if (!in_array($filterCategory, INV_CATEGORIES, true)) {
    $filterCategory = '';
}
if (!in_array($filterStatus, ['in_stock', 'low_stock', 'out_of_stock'], true)) {
    $filterStatus = '';
}

/* ------------------------------------------------------------------
   Stat cards — always reflect the FULL inventory, not the filtered view
------------------------------------------------------------------ */
$allItems      = $pdo->query('SELECT * FROM inventory_items')->fetchAll();
$statTotal     = count($allItems);
$statLowStock  = 0;
$statValue     = 0.0;

foreach ($allItems as $it) {
    if (in_array(inv_status($it), ['low_stock', 'out_of_stock'], true)) {
        $statLowStock++;
    }
    $statValue += ((int) $it['quantity']) * ((float) $it['cost_per_unit']);
}

$monthStart = date('Y-m-01 00:00:00');
$usageStmt  = $pdo->prepare(
    "SELECT COALESCE(SUM(ABS(change_qty)), 0) FROM inventory_logs
     WHERE reason = 'usage' AND created_at >= :m"
);
$usageStmt->execute(['m' => $monthStart]);
$statMonthlyUsage = (int) $usageStmt->fetchColumn();

/* ------------------------------------------------------------------
   Main list query
------------------------------------------------------------------ */
$where  = [];
$params = [];

if ($filterCategory !== '') {
    $where[] = 'category = :cat';
    $params['cat'] = $filterCategory;
}
if ($filterSearch !== '') {
    $where[] = 'name LIKE :s';
    $params['s'] = '%' . $filterSearch . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM inventory_items {$whereSql} ORDER BY name ASC");
$stmt->execute($params);
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $status = inv_status($r);
    if ($filterStatus !== '' && $status !== $filterStatus) {
        continue;
    }
    $r['status'] = $status;
    $rows[] = $r;
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';

/* Serialize the currently-rendered rows to JSON for the view/edit modals */
$rowsJson = json_encode(array_map(function ($r) {
    return [
        'id'              => (int) $r['id'],
        'name'            => $r['name'],
        'category'        => $r['category'],
        'quantity'        => (int) $r['quantity'],
        'min_stock_level' => (int) $r['min_stock_level'],
        'cost_per_unit'   => (float) $r['cost_per_unit'],
        'expiration_date' => $r['expiration_date'],
        'status'          => $r['status'],
    ];
}, $rows));
?>

<style>
/* ============================================================
   Inventory — page-scoped styles. Kept local to this page
   (same approach admin/payments.php already uses).
   ============================================================ */

/* Toolbar */
.toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.toolbar-search { position: relative; flex: 1; min-width: 220px; }
.toolbar-search input {
    width: 100%; padding: 9px 14px 9px 38px;
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    transition: border-color var(--transition);
}
.toolbar-search input:focus { outline: none; border-color: var(--gold-500); }
.toolbar-search input::placeholder { color: var(--text-tertiary); }
.toolbar-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; }
.filter-select {
    padding: 9px 14px; background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-md); color: var(--text); font-size: 14px;
    cursor: pointer; transition: border-color var(--transition);
}
.filter-select:focus { outline: none; border-color: var(--gold-500); }

/* Table */
.table-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 0; margin-bottom: 14px; }
.results-count { font-size: 12.5px; color: var(--text-secondary); }
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; cursor: pointer; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--panel-alt); }
tbody td { padding: 12px 14px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
.td-name { font-weight: 700; }
.td-sub  { color: var(--text-secondary); }
.td-amount { font-family: var(--font-display); font-weight: 700; }
.td-actions { display: flex; gap: 6px; }

.icon-btn-sm {
    width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--r-sm); border: 1px solid var(--border); background: var(--panel-alt);
    cursor: pointer; transition: background var(--transition), color var(--transition), border-color var(--transition);
}
.icon-btn-sm--view   { color: var(--gold-400); }
.icon-btn-sm--view:hover   { background: var(--amber-bg); border-color: var(--gold-600); }
.icon-btn-sm--edit   { color: var(--blue); }
.icon-btn-sm--edit:hover   { background: var(--blue-bg); border-color: var(--blue); }
.icon-btn-sm--danger { color: var(--red); }
.icon-btn-sm--danger:hover { background: var(--red-bg); border-color: var(--red); }

/* Status badges */
.sb { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; }
.sb--green { background: var(--emerald-bg); color: var(--emerald); }
.sb--amber { background: var(--amber-bg);   color: var(--gold-400); }
.sb--red   { background: var(--red-bg);     color: var(--red); }
.sb--grey  { background: var(--panel-alt);  color: var(--text-secondary); }

/* Modal shell — shared shape across View / Add-Edit / Delete-confirm */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    display: flex; align-items: center; justify-content: center;
    padding: 20px; z-index: 500;
    opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.is-open { opacity: 1; pointer-events: auto; }
.modal {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--r-lg); width: 100%; max-width: 520px;
    max-height: 92vh; display: flex; flex-direction: column;
    transform: translateY(12px); transition: transform .2s;
}
.modal-overlay.is-open .modal { transform: translateY(0); }
.modal-confirm { max-width: 420px; }
.modal-header {
    padding: 20px 24px 16px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.modal-header-left { display: flex; align-items: flex-start; gap: 12px; }
.modal-icon-box {
    width: 40px; height: 40px; border-radius: var(--r-md);
    background: var(--amber-bg); color: var(--gold-400);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.modal-title    { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
.modal-subtitle { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; }
.modal-close    { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: var(--r-sm); flex-shrink: 0; }
.modal-close:hover { color: var(--text); background: var(--panel-alt); }
.modal-body     { padding: 20px 24px; overflow-y: auto; flex: 1; min-height: 0; }
.modal-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-tertiary); margin-bottom: 10px; }
.modal-actions  { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }

/* Detail boxes used inside the View modal */
.detail-box { background: var(--panel-alt); border: 1px solid var(--border); border-radius: var(--r-md); padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; }
.detail-box + .detail-box { margin-top: 16px; }
.detail-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13.5px; }
.detail-row-label { color: var(--text-secondary); }
.detail-row-value { font-weight: 600; color: var(--text); text-align: right; }

/* Form fields */
.form-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.form-hint  { font-size: 11px; color: var(--text-tertiary); }
.form-input {
    padding: 9px 12px; background: var(--input-bg);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--text); font-size: 14px; width: 100%;
    transition: border-color var(--transition);
}
.form-input:focus { outline: none; border-color: var(--gold-500); }
.form-input::placeholder { color: var(--text-tertiary); }
.currency-input-wrap { position: relative; }
.currency-input-wrap span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); font-size: 14px; pointer-events: none; }
.currency-input-wrap input { padding-left: 28px; }
.form-alert {
    background: var(--red-bg); border: 1px solid var(--red-bg-strong); color: var(--red);
    border-radius: var(--r-md); padding: 10px 14px; font-size: 13px; margin-bottom: 16px;
}

.btn-danger { background: var(--red-bg); color: var(--red); border-color: var(--red); }
.btn-danger:hover { background: var(--red); color: #fff; }

/* Toast */
.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast { padding: 12px 18px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.5); transform: translateX(120%); opacity: 0; transition: transform .3s, opacity .3s; }
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }

@media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
</style>

<div class="stack">

    <!-- ===== Stat cards ===== -->
    <div class="stat-grid">
        <?php
            render_stat_card('Total Items', (string) $statTotal, 'boxes', 'blue');
            render_stat_card('Low Stock Items', (string) $statLowStock, 'alert-triangle', 'amber');
            render_stat_card('Inventory Value', '₱' . number_format($statValue, 2), 'wallet', 'emerald');
            render_stat_card('Monthly Usage', (string) $statMonthlyUsage, 'pie-chart', 'fuchsia');
        ?>
    </div>

    <!-- ===== Toolbar ===== -->
    <div class="toolbar">
        <div class="toolbar-search">
            <span class="toolbar-search-icon"><?php echo inv_icon('search', 'icon-sm'); ?></span>
            <input type="text" id="searchInput" placeholder="Search items…"
                   value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <select class="filter-select" id="categorySelect">
            <option value="">All Items</option>
            <?php foreach (INV_CATEGORIES as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterCategory === $cat ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="filter-select" id="statusSelect">
            <option value="">All Status</option>
            <option value="in_stock"     <?php echo $filterStatus === 'in_stock'     ? 'selected' : ''; ?>>In Stock</option>
            <option value="low_stock"    <?php echo $filterStatus === 'low_stock'    ? 'selected' : ''; ?>>Low Stock</option>
            <option value="out_of_stock" <?php echo $filterStatus === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
        </select>
    </div>

    <!-- ===== Inventory Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head">
            <h2 class="panel-title">Inventory Items</h2>
            <span class="results-count"><?php echo count($rows); ?> item<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Min Stock</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7">
                                <?php
                                    $hasFilters = ($filterSearch !== '' || $filterCategory !== '' || $filterStatus !== '');
                                    render_empty_state(
                                        'boxes',
                                        $hasFilters ? 'No matching items' : 'No inventory items yet',
                                        $hasFilters ? 'Try a different search term or filter.' : 'Track linens, toiletries, and supplies once you add inventory items.'
                                    );
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $value = ((int) $row['quantity']) * ((float) $row['cost_per_unit']); ?>
                            <tr onclick="openView(<?php echo (int) $row['id']; ?>)">
                                <td class="td-name"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-sub"><?php echo htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) $row['quantity']; ?> units</td>
                                <td><?php echo (int) $row['min_stock_level']; ?> units</td>
                                <td class="td-amount">₱<?php echo number_format($value, 2); ?></td>
                                <td><?php echo inv_status_badge($row['status']); ?></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="td-actions">
                                        <button class="icon-btn-sm icon-btn-sm--view" onclick="openView(<?php echo (int) $row['id']; ?>)"
                                                title="View" aria-label="View <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo inv_icon('eye', 'icon-sm'); ?>
                                        </button>
                                        <button class="icon-btn-sm icon-btn-sm--edit" onclick="openEdit(<?php echo (int) $row['id']; ?>)"
                                                title="Edit" aria-label="Edit <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo icon('edit', 'icon-sm'); ?>
                                        </button>
                                        <button class="icon-btn-sm icon-btn-sm--danger" onclick="openDeleteConfirm(<?php echo (int) $row['id']; ?>)"
                                                title="Delete" aria-label="Delete <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo inv_icon('trash', 'icon-sm'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.stack -->

<!-- ===== View Item Modal ===== -->
<div class="modal-overlay" id="viewModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-left">
                <span class="modal-icon-box"><?php echo icon('boxes', 'icon-sm'); ?></span>
                <div>
                    <div class="modal-title" id="viewName">—</div>
                    <div class="modal-subtitle" id="viewStatusText">—</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeView()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <div class="detail-box">
                <div class="detail-row"><span class="detail-row-label">Item Name:</span><span class="detail-row-value" id="vName">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Category:</span><span class="detail-row-value" id="vCategory">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Quantity:</span><span class="detail-row-value" id="vQuantity">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Min. Stock Level:</span><span class="detail-row-value" id="vMinStock">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Cost per Unit:</span><span class="detail-row-value" id="vCost">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Expiration Date:</span><span class="detail-row-value" id="vExpiry">—</span></div>
            </div>
            <div class="detail-box">
                <div class="modal-section-title">Current Status</div>
                <div class="detail-row"><span class="detail-row-label">Current Quantity</span><span class="detail-row-value" id="vCurQuantity">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Min Stock</span><span class="detail-row-value" id="vCurMinStock">—</span></div>
                <div class="detail-row"><span class="detail-row-label">Status</span><span id="vCurStatus">—</span></div>
            </div>
        </div>
        <div class="modal-actions" style="justify-content:flex-end;">
            <button class="btn btn-primary" onclick="updateFromView()">
                <?php echo icon('edit', 'icon-sm'); ?> Update Item
            </button>
        </div>
    </div>
</div>

<!-- ===== Add / Edit Item Modal ===== -->
<div class="modal-overlay" id="itemFormModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-left">
                <span class="modal-icon-box" id="formIconBox"><?php echo icon('plus', 'icon-sm'); ?></span>
                <div>
                    <div class="modal-title" id="formTitle">Add New Item</div>
                    <div class="modal-subtitle" id="formSubtitle">Add a new item to your inventory.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeItemForm()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <div class="form-alert" id="formAlert" style="display:none;"></div>
            <form id="itemForm" onsubmit="submitItemForm(event)" novalidate>
                <div class="form-row">
                    <div class="form-group full">
                        <label class="form-label" for="fName">Item Name <span style="color:var(--red)">*</span></label>
                        <input class="form-input" type="text" id="fName" maxlength="150" placeholder="e.g., Bath Towels" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fCategory">Category <span style="color:var(--red)">*</span></label>
                        <select class="form-input" id="fCategory" required>
                            <?php foreach (INV_CATEGORIES as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fQuantity">Quantity <span style="color:var(--red)">*</span></label>
                        <input class="form-input" type="number" id="fQuantity" min="0" step="1" value="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fMinStock">Minimum Stock Level <span style="color:var(--red)">*</span></label>
                        <input class="form-input" type="number" id="fMinStock" min="0" step="1" value="0" required>
                        <span class="form-hint">Alert when stock falls below this level</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fCost">Cost per Unit <span style="color:var(--red)">*</span></label>
                        <div class="currency-input-wrap">
                            <span>₱</span>
                            <input class="form-input" type="number" id="fCost" min="0" step="0.01" value="0.01" required>
                        </div>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="fExpiry">Expiration Date <span style="color:var(--red)">*</span></label>
                        <input class="form-input" type="date" id="fExpiry" required>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeItemForm()">Cancel</button>
            <button class="btn btn-primary" id="formSubmitBtn" onclick="submitItemForm(event)">
                <?php echo icon('plus', 'icon-sm'); ?> Add Item
            </button>
        </div>
    </div>
</div>

<!-- ===== Delete Confirmation Modal ===== -->
<div class="modal-overlay" id="deleteModal" aria-modal="true" role="dialog">
    <div class="modal modal-confirm">
        <div class="modal-header">
            <div class="modal-title">Delete Item</div>
            <button class="modal-close" onclick="closeDeleteConfirm()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <p id="deleteText" style="font-size:14px;color:var(--text-secondary);">Delete this item? This can&rsquo;t be undone.</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDeleteConfirm()">Cancel</button>
            <button class="btn btn-danger" id="deleteConfirmBtn" onclick="confirmDelete()">
                <?php echo inv_icon('trash', 'icon-sm'); ?> Delete
            </button>
        </div>
    </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Inventory page JS
   ============================================================ */
var ROWS = <?php echo $rowsJson; ?>;
var CSRF = <?php echo json_encode($csrf); ?>;
var rowMap = {};
ROWS.forEach(function (r) { rowMap[r.id] = r; });

var editingId  = null; // set = edit mode, null = create mode
var viewingId  = null;
var deletingId = null;

/* ---------- Filters ---------- */
function applyFilter(key, val) {
    var url = new URL(window.location.href);
    if (val) url.searchParams.set(key, val);
    else url.searchParams.delete(key);
    window.location.href = url.toString();
}
document.getElementById('categorySelect').addEventListener('change', function () { applyFilter('category', this.value); });
document.getElementById('statusSelect').addEventListener('change', function () { applyFilter('stock_status', this.value); });

var stimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(stimer);
    var v = this.value;
    stimer = setTimeout(function () {
        var url = new URL(window.location.href);
        if (v.trim()) url.searchParams.set('search', v.trim());
        else url.searchParams.delete('search');
        window.location.href = url.toString();
    }, 500);
});

/* ---------- View modal ---------- */
function openView(id) {
    var r = rowMap[id];
    if (!r) return;
    viewingId = id;

    document.getElementById('viewName').textContent       = r.name;
    document.getElementById('viewStatusText').textContent = statusText(r.status);

    document.getElementById('vName').textContent     = r.name;
    document.getElementById('vCategory').textContent = r.category;
    document.getElementById('vQuantity').textContent = r.quantity + ' Units';
    document.getElementById('vMinStock').textContent = r.min_stock_level + ' Units';
    document.getElementById('vCost').textContent      = '₱' + fmt(r.cost_per_unit);
    document.getElementById('vExpiry').textContent    = fmtDate(r.expiration_date);

    document.getElementById('vCurQuantity').textContent = r.quantity + ' Units';
    document.getElementById('vCurMinStock').textContent = r.min_stock_level + ' Units';
    document.getElementById('vCurStatus').innerHTML      = statusBadge(r.status);

    document.getElementById('viewModal').classList.add('is-open');
}
function closeView() {
    document.getElementById('viewModal').classList.remove('is-open');
    viewingId = null;
}
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeView(); });

function updateFromView() {
    if (!viewingId) return;
    var id = viewingId;
    closeView();
    openEdit(id);
}

/* ---------- Add / Edit modal ---------- */
function openAdd() {
    editingId = null;
    document.getElementById('itemForm').reset();
    document.getElementById('formTitle').textContent    = 'Add New Item';
    document.getElementById('formSubtitle').textContent = 'Add a new item to your inventory.';
    document.getElementById('formIconBox').innerHTML    = <?php echo json_encode(icon('plus', 'icon-sm')); ?>;
    document.getElementById('formSubmitBtn').innerHTML  = <?php echo json_encode(icon('plus', 'icon-sm') . ' Add Item'); ?>;
    hideFormAlert();
    document.getElementById('itemFormModal').classList.add('is-open');
}

function openEdit(id) {
    var r = rowMap[id];
    if (!r) return;
    editingId = id;

    document.getElementById('fName').value     = r.name;
    document.getElementById('fCategory').value = r.category;
    document.getElementById('fQuantity').value = r.quantity;
    document.getElementById('fMinStock').value = r.min_stock_level;
    document.getElementById('fCost').value     = r.cost_per_unit.toFixed(2);
    document.getElementById('fExpiry').value   = r.expiration_date || '';

    document.getElementById('formTitle').textContent    = 'Edit Item';
    document.getElementById('formSubtitle').textContent = 'Update inventory item details.';
    document.getElementById('formIconBox').innerHTML    = <?php echo json_encode(icon('edit', 'icon-sm')); ?>;
    document.getElementById('formSubmitBtn').innerHTML  = <?php echo json_encode(icon('save', 'icon-sm') . ' Save Changes'); ?>;
    hideFormAlert();
    document.getElementById('itemFormModal').classList.add('is-open');
}

function closeItemForm() {
    document.getElementById('itemFormModal').classList.remove('is-open');
    editingId = null;
}
document.getElementById('itemFormModal').addEventListener('click', function (e) { if (e.target === this) closeItemForm(); });

var actionBtn = document.querySelector('.btn-action');
if (actionBtn) actionBtn.addEventListener('click', openAdd);

function showFormAlert(msg) {
    var el = document.getElementById('formAlert');
    el.textContent = msg;
    el.style.display = '';
}
function hideFormAlert() {
    document.getElementById('formAlert').style.display = 'none';
}

function submitItemForm(e) {
    e.preventDefault();

    var name     = document.getElementById('fName').value.trim();
    var category = document.getElementById('fCategory').value;
    var quantity = parseInt(document.getElementById('fQuantity').value, 10);
    var minStock = parseInt(document.getElementById('fMinStock').value, 10);
    var cost     = parseFloat(document.getElementById('fCost').value);
    var expiry   = document.getElementById('fExpiry').value;

    if (!name)                              { showFormAlert('Item name is required.'); return; }
    if (!category)                          { showFormAlert('Please select a category.'); return; }
    if (isNaN(quantity) || quantity < 0)    { showFormAlert('Quantity must be zero or more.'); return; }
    if (isNaN(minStock) || minStock < 0)    { showFormAlert('Minimum stock level must be zero or more.'); return; }
    if (isNaN(cost) || cost < 0)            { showFormAlert('Cost per unit must be zero or more.'); return; }
    if (!expiry)                            { showFormAlert('Expiration date is required.'); return; }
    hideFormAlert();

    var btn = document.getElementById('formSubmitBtn');
    btn.disabled = true;

    var payload = {
        action:           editingId ? 'update' : 'create',
        csrf_token:       CSRF,
        name:             name,
        category:         category,
        quantity:         quantity,
        min_stock_level:  minStock,
        cost_per_unit:    cost,
        expiration_date:  expiry
    };
    if (editingId) payload.id = editingId;

    fetch('api/inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast(editingId ? 'Item updated successfully!' : 'Item added successfully!', 'success');
            setTimeout(function () { window.location.reload(); }, 700);
        } else {
            showFormAlert(data.error || 'Something went wrong. Please try again.');
            btn.disabled = false;
        }
    })
    .catch(function () {
        showFormAlert('Network error. Please try again.');
        btn.disabled = false;
    });
}

/* ---------- Delete ---------- */
function openDeleteConfirm(id) {
    var r = rowMap[id];
    if (!r) return;
    deletingId = id;
    document.getElementById('deleteText').textContent = 'Delete "' + r.name + '"? This can\'t be undone.';
    document.getElementById('deleteModal').classList.add('is-open');
}
function closeDeleteConfirm() {
    document.getElementById('deleteModal').classList.remove('is-open');
    deletingId = null;
}
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteConfirm(); });

function confirmDelete() {
    if (!deletingId) return;
    var btn = document.getElementById('deleteConfirmBtn');
    btn.disabled = true;

    fetch('api/inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', csrf_token: CSRF, id: deletingId })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast('Item deleted.', 'success');
            setTimeout(function () { window.location.reload(); }, 700);
        } else {
            toast(data.error || 'Failed to delete item.', 'error');
            btn.disabled = false;
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled = false;
    });
}

/* ---------- Helpers ---------- */
function statusText(s) {
    if (s === 'out_of_stock') return 'Out of Stock';
    if (s === 'low_stock') return 'Low Stock';
    return 'In Stock';
}
function statusBadge(s) {
    var cls = s === 'out_of_stock' ? 'sb--red' : (s === 'low_stock' ? 'sb--amber' : 'sb--green');
    return '<span class="sb ' + cls + '">' + statusText(s) + '</span>';
}
function fmt(n) { return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function toast(msg, type) {
    var wrap = document.getElementById('toastWrap');
    var el   = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 20);
    setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { try { wrap.removeChild(el); } catch (e) {} }, 400);
    }, 3500);
}
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>
