<?php
/**
 * admin/expenses.php
 * -----------------------------------------------------------------------
 * Full live expense tracking page. Lets admins log operational costs
 * (utilities, maintenance, supplies, salaries, etc.), view them in a
 * table, and edit or delete existing records. Categories are a fixed
 * set (EXP_CATEGORIES) rather than a free-text field, so reporting and
 * the category badge colors stay consistent.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/expense-link.php';

$active_page        = 'expenses';
$page_title          = 'Expenses';
$breadcrumb          = 'Expenses';
$page_action_label   = 'Add expense';
$show_period_toggle  = false;

$pdo  = bienmarko_db();
$csrf = bienmarko_csrf_token();

/* ------------------------------------------------------------------
   Ensure the expenses table exists (additive, self-guarding — same
   approach admin/inventory.php uses for its own tables)
------------------------------------------------------------------ */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `expenses` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `expense_date`  DATE NOT NULL,
        `category`      VARCHAR(40) NOT NULL DEFAULT 'Others',
        `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `notes`         VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* Link columns that connect an expense to an Inventory purchase */
expense_link_ensure_columns($pdo);

const EXP_CATEGORIES = ['Utilities', 'Maintenance', 'Supplies', 'Salaries', 'Others'];

/** Maps a category name to a short slug used for its badge color class. */
function exp_category_slug(string $category): string
{
    $slug = strtolower(trim($category));
    return in_array($slug, ['utilities', 'maintenance', 'supplies', 'salaries'], true) ? $slug : 'others';
}

function exp_category_badge(string $category): string
{
    $slug = exp_category_slug($category);
    $safe = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
    return '<span class="cb cb--' . $slug . '">' . $safe . '</span>';
}

/**
 * A couple of extra inline icons this page needs that aren't in the
 * shared admin/includes/icons.php registry. Kept local here — same
 * approach admin/inventory.php uses for inv_icon().
 * Falls through to the shared icon() helper for every other name.
 */
function exp_icon(string $name, string $class = 'icon'): string
{
    $extra = [
        'trash' => '<polyline points="4 7 20 7"></polyline><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path><path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
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
   Stat cards
   - Total Expenses: sum of amounts recorded this calendar month
   - Categories: count of the fixed category options offered
   - Average per Category: this month's total spread across categories
------------------------------------------------------------------ */
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$sumStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN :start AND :end'
);
$sumStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
$statThisMonth = (float) $sumStmt->fetchColumn();

/* Portion of this month's total that came from Inventory purchases */
$invStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM expenses
     WHERE source = 'inventory' AND expense_date BETWEEN :start AND :end"
);
$invStmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
$statInventoryMonth = (float) $invStmt->fetchColumn();

$statCategoryCount = count(EXP_CATEGORIES);
$statAveragePerCat = $statCategoryCount > 0 ? ($statThisMonth / $statCategoryCount) : 0.0;

/* Last calendar month — for the second summary card */
$lastStart = date('Y-m-01', strtotime('first day of last month'));
$lastEnd   = date('Y-m-t',  strtotime('first day of last month'));
$sumStmt->execute(['start' => $lastStart, 'end' => $lastEnd]);
$statLastMonth      = (float) $sumStmt->fetchColumn();
$statLastMonthLabel = date('F Y', strtotime($lastStart));
$statManualMonth    = max(0, $statThisMonth - $statInventoryMonth);

/* ------------------------------------------------------------------
   Filters — month (default: this month), category, search in notes
------------------------------------------------------------------ */
$filterMonth    = trim($_GET['month'] ?? date('Y-m'));
$filterCategory = trim($_GET['category'] ?? '');
$filterSearch   = trim($_GET['search'] ?? '');
if ($filterMonth !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filterMonth)) {
    $filterMonth = date('Y-m');
}
if (!in_array($filterCategory, EXP_CATEGORIES, true)) {
    $filterCategory = '';
}

/* Months that have expenses (+ this month, + the one being viewed) */
$monthOptions = [date('Y-m') => date('F Y')];
foreach ($pdo->query("SELECT DISTINCT DATE_FORMAT(expense_date, '%Y-%m') AS ym FROM expenses ORDER BY ym DESC")->fetchAll(PDO::FETCH_COLUMN) as $ym) {
    $monthOptions[$ym] = date('F Y', strtotime($ym . '-01'));
}
if ($filterMonth !== 'all' && !isset($monthOptions[$filterMonth])) {
    $monthOptions[$filterMonth] = date('F Y', strtotime($filterMonth . '-01'));
}
krsort($monthOptions);

$where  = [];
$params = [];
if ($filterMonth !== 'all') {
    $where[] = 'expense_date BETWEEN :ms AND :me';
    $params['ms'] = $filterMonth . '-01';
    $params['me'] = date('Y-m-t', strtotime($filterMonth . '-01'));
}
if ($filterCategory !== '') {
    $where[] = 'category = :cat';
    $params['cat'] = $filterCategory;
}
if ($filterSearch !== '') {
    $where[] = 'notes LIKE :q';
    $params['q'] = '%' . $filterSearch . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Main list — most recent expenses first */
$stmt = $pdo->prepare("SELECT * FROM expenses {$whereSql} ORDER BY expense_date DESC, id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAllExpenses = (int) $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$totalShown = 0.0;
foreach ($rows as $r) { $totalShown += (float) $r['amount']; }

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';

/* Serialize the rendered rows to JSON for the edit/delete modals */
$rowsJson = json_encode(array_map(function ($r) {
    return [
        'id'            => (int) $r['id'],
        'expense_date'  => $r['expense_date'],
        'category'      => $r['category'],
        'amount'        => (float) $r['amount'],
        'notes'         => $r['notes'],
        'source'        => $r['source'] ?? 'manual',
    ];
}, $rows));
?>

<style>
/* ============================================================
   Expenses — page-scoped styles. Kept local to this page
   (same approach admin/inventory.php already uses).
   ============================================================ */
.stack { display: flex; flex-direction: column; gap: 24px; }

/* Stat cards reuse the shared .stat-card / .stat-icon classes from
   assets/css/style.css — only the subtitle line is page-local. */
.stat-sub { font-size: 11.5px; color: var(--text-tertiary); margin-top: 4px; }

/* Table */
.table-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 0; margin-bottom: 14px; gap: 12px; flex-wrap: wrap; }
.results-count { font-size: 12.5px; color: var(--text-secondary); }
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--panel-alt); }
tbody td { padding: 12px 14px; font-size: 13.5px; color: var(--text); vertical-align: middle; }
.td-date   { color: var(--text-secondary); white-space: nowrap; }
.td-notes  { color: var(--text-secondary); max-width: 320px; }
.td-amount { font-family: var(--font-display); font-weight: 700; }
.td-actions { display: flex; gap: 6px; }

.icon-btn-sm {
    width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--r-sm); border: 1px solid var(--border); background: var(--panel-alt);
    cursor: pointer; transition: background var(--transition), color var(--transition), border-color var(--transition);
}
.icon-btn-sm--edit   { color: var(--blue); }
.icon-btn-sm--edit:hover   { background: var(--blue-bg); border-color: var(--blue); }
.icon-btn-sm--danger { color: var(--red); }
.icon-btn-sm--danger:hover { background: var(--red-bg); border-color: var(--red); }

/* Category badges */
.cb { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: var(--r-full); font-size: 12px; font-weight: 700; white-space: nowrap; }
.cb--utilities   { background: var(--red-bg);     color: var(--red); }
.cb--maintenance { background: var(--fuchsia-bg); color: var(--fuchsia); }
.cb--supplies    { background: var(--blue-bg);    color: var(--blue); }
.cb--salaries    { background: var(--amber-bg);   color: var(--gold-400); }
.cb--others      { background: var(--panel-alt);  color: var(--text-secondary); }

.cb--inventory { background: var(--emerald-bg); color: var(--emerald); margin-left: 8px; padding: 2px 9px; font-size: 11px; }
.cb--inventory:hover { text-decoration: underline; }
.td-locked { font-size: 12px; color: var(--text-tertiary); white-space: nowrap; }

/* Modal shell */
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
.modal-actions  { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }

/* Form fields */
.form-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1 / -1; }
.form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.form-input {
    padding: 9px 12px; background: var(--input-bg);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    color: var(--text); font-size: 14px; width: 100%;
    transition: border-color var(--transition);
    font-family: inherit;
}
.form-input:focus { outline: none; border-color: var(--gold-500); }
.form-input::placeholder { color: var(--text-tertiary); }
textarea.form-input { resize: vertical; min-height: 80px; }
.currency-input-wrap { position: relative; }
.currency-input-wrap span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); font-size: 14px; pointer-events: none; }
.currency-input-wrap input { padding-left: 28px; }
.form-alert {
    background: var(--red-bg); border: 1px solid var(--red-bg-strong); color: var(--red);
    border-radius: var(--r-md); padding: 10px 14px; font-size: 13px; margin-bottom: 16px;
}

.btn-success { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald); }
.btn-success:hover { background: var(--emerald); color: #0a0a0b; }
.btn-danger  { background: var(--red-bg); color: var(--red); border-color: var(--red); }
.btn-danger:hover  { background: var(--red); color: #fff; }

/* Toast */
.toast-wrap { position: fixed; bottom: 28px; right: 28px; z-index: 900; display: flex; flex-direction: column; gap: 10px; }
.toast { padding: 12px 18px; border-radius: var(--r-md); font-size: 13.5px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.5); transform: translateX(120%); opacity: 0; transition: transform .3s, opacity .3s; }
.toast.show { transform: translateX(0); opacity: 1; }
.toast--success { background: var(--emerald-bg); border: 1px solid var(--emerald); color: var(--emerald); }
.toast--error   { background: var(--red-bg);     border: 1px solid var(--red);     color: var(--red); }

/* ---------- Summary cards ---------- */
.exp-kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.exp-kpi { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 28px; }
.exp-kpi-label { font-size: 15px; color: var(--text-secondary); }
.exp-kpi-value { display: block; font-family: var(--font-display); font-size: 40px; font-weight: 700; line-height: 1; color: var(--text); margin: 20px 0 12px; }
.exp-kpi-sub { font-size: 13.5px; color: var(--text-secondary); }

/* ---------- Filters ---------- */
.exp-filters { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.exp-filters .filter-select { padding: 15px 18px; background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-md); color: var(--text); font-size: 15px; cursor: pointer; }
.exp-filters .filter-select:focus { outline: none; border-color: var(--gold-500); }
.exp-search { flex: 1; min-width: 220px; }
.exp-search input { width: 100%; padding: 15px 18px; background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-md); color: var(--text); font-size: 15px; transition: border-color var(--transition); }
.exp-search input:focus { outline: none; border-color: var(--gold-500); }
.exp-search input::placeholder { color: var(--text-tertiary); }

/* ---------- Records table ---------- */
.table-head--exp { justify-content: flex-start; gap: 12px; padding: 22px 28px 14px; margin-bottom: 0; }
.table-head--exp .panel-title { font-size: 24px; }
.table-head--exp .results-count { font-size: 14px; }
.exp-table thead th { padding: 12px 12px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary); white-space: nowrap; }
.exp-table tbody td { padding: 18px 12px; font-size: 15px; vertical-align: middle; }
.exp-table th:first-child, .exp-table td:first-child { padding-left: 28px; }
.exp-table th:last-child,  .exp-table td:last-child  { padding-right: 28px; }
.exp-table .th-right, .exp-table .td-right { text-align: right; }
.exp-table .td-date  { color: var(--text); white-space: nowrap; }
.exp-table .td-notes { color: var(--text); max-width: none; white-space: normal; }
.exp-table .td-amount { font-family: inherit; font-weight: 700; white-space: nowrap; }
.exp-table .td-actions { justify-content: flex-end; }
.exp-table .td-locked { font-size: 15px; color: var(--text-secondary); }
.pill { display: inline-flex; align-items: center; padding: 4px 13px; border-radius: var(--r-full); font-size: 13px; font-weight: 400; white-space: nowrap; border: 1px solid var(--border); background: var(--panel-alt); color: var(--text-secondary); }
a.pill--inventory, .pill--inventory { background: var(--blue-bg); color: var(--blue); border-color: transparent; text-decoration: none; }
a.pill--inventory:hover { text-decoration: underline; }
.exp-btn-edit { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: var(--r-md); background: transparent; border: 1px solid var(--gold-500); color: var(--gold-400); cursor: pointer; transition: background var(--transition); }
.exp-btn-edit:hover { background: var(--amber-bg); }
.exp-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 18px 28px; border-top: 1px solid var(--border); font-size: 15px; color: var(--text-secondary); }
.exp-foot strong { color: var(--text); }

/* Delete now lives inside the Edit dialog */
.modal-actions .btn-delete { margin-right: auto; display: inline-flex; align-items: center; gap: 6px; }

@media (max-width: 700px) { .exp-kpi-grid { grid-template-columns: 1fr; } .table-head--exp, .exp-foot { padding-left: 16px; padding-right: 16px; } }
@media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
</style>

<div class="stack">

    <!-- ===== Summary cards ===== -->
    <div class="exp-kpi-grid">
        <div class="exp-kpi">
            <div class="exp-kpi-label">This month</div>
            <span class="exp-kpi-value">₱<?php echo number_format($statThisMonth, 2); ?></span>
            <div class="exp-kpi-sub">₱<?php echo number_format($statInventoryMonth, 2); ?> inventory · ₱<?php echo number_format($statManualMonth, 2); ?> manual</div>
        </div>
        <div class="exp-kpi">
            <div class="exp-kpi-label">Last month</div>
            <span class="exp-kpi-value">₱<?php echo number_format($statLastMonth, 2); ?></span>
            <div class="exp-kpi-sub"><?php echo htmlspecialchars($statLastMonthLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <!-- ===== Filters ===== -->
    <div class="exp-filters">
        <select class="filter-select" id="monthSelect" aria-label="Month">
            <?php foreach ($monthOptions as $ym => $label): ?>
                <option value="<?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterMonth === $ym ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
            <option value="all" <?php echo $filterMonth === 'all' ? 'selected' : ''; ?>>All time</option>
        </select>
        <select class="filter-select" id="categorySelect" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach (EXP_CATEGORIES as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterCategory === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="exp-search">
            <input type="text" id="searchInput" placeholder="Search notes" value="<?php echo htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
    </div>

    <!-- ===== Expense Records Table ===== -->
    <div class="panel table-wrap">
        <div class="table-head table-head--exp">
            <h2 class="panel-title">Expense records</h2>
            <span class="results-count"><?php echo count($rows); ?> expense<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>

        <div class="table-scroll">
            <table class="exp-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Notes</th>
                        <th>Source</th>
                        <th class="th-right">Amount</th>
                        <th class="th-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6">
                                <?php
                                $hasFilters = ($filterSearch !== '' || $filterCategory !== '' || $filterMonth !== date('Y-m'));
                                render_empty_state('trend-down', $hasFilters ? 'No matching expenses' : 'No expenses recorded', $hasFilters ? 'Try a different month, category or search.' : 'Log operational costs here to keep track of your spending.');
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $isInv = (($row['source'] ?? 'manual') === 'inventory'); ?>
                            <tr>
                                <td class="td-date"><?php echo htmlspecialchars(date('M j, Y', strtotime($row['expense_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="pill"><?php echo htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="td-notes"><?php echo htmlspecialchars($row['notes'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($isInv): ?>
                                        <a class="pill pill--inventory" href="inventory.php" title="Created from an Inventory purchase">Inventory</a>
                                    <?php else: ?>
                                        <span class="pill">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="td-right td-amount">₱<?php echo number_format((float) $row['amount'], 2); ?></td>
                                <td class="td-right">
                                    <?php if ($isInv): ?>
                                        <span class="td-locked" title="Managed from the Inventory page">Auto-recorded</span>
                                    <?php else: ?>
                                        <div class="td-actions">
                                            <button class="exp-btn-edit" onclick="openEdit(<?php echo (int) $row['id']; ?>)" title="Edit" aria-label="Edit expense">
                                                <?php echo icon('edit', 'icon-sm'); ?>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="exp-foot">
            <span>Showing <?php echo count($rows); ?> of <?php echo $totalAllExpenses; ?> expense<?php echo $totalAllExpenses === 1 ? '' : 's'; ?></span>
            <span>Total shown <strong>₱<?php echo number_format($totalShown, 2); ?></strong></span>
        </div>
    </div>

</div><!-- /.stack -->

<!-- ===== Add / Edit Expense Modal ===== -->
<div class="modal-overlay" id="expenseFormModal" aria-modal="true" role="dialog">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-left">
                <span class="modal-icon-box" id="formIconBox"><?php echo icon('plus', 'icon-sm'); ?></span>
                <div>
                    <div class="modal-title" id="formTitle">Add Expense</div>
                    <div class="modal-subtitle" id="formSubtitle">Record a new expense transaction.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeExpenseForm()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <div class="form-alert" id="formAlert" style="display:none;"></div>
            <form id="expenseForm" onsubmit="submitExpenseForm(event)" novalidate>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="fDate">Date <span style="color:var(--red)">*</span></label>
                        <input class="form-input" type="date" id="fDate" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fCategory">Category <span style="color:var(--red)">*</span></label>
                        <select class="form-input" id="fCategory" required>
                            <?php foreach (EXP_CATEGORIES as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="fAmount">Amount <span style="color:var(--red)">*</span></label>
                        <div class="currency-input-wrap">
                            <span>₱</span>
                            <input class="form-input" type="number" id="fAmount" min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="fNotes">Notes <span style="color:var(--red)">*</span></label>
                        <textarea class="form-input" id="fNotes" maxlength="255" placeholder="e.g., Electricity and water bills" required></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-danger btn-delete" id="formDeleteBtn" style="display:none;" onclick="openDeleteConfirm(editingId)">
                <?php echo exp_icon('trash', 'icon-sm'); ?> Delete
            </button>
            <button class="btn btn-secondary" onclick="closeExpenseForm()">Cancel</button>
            <button class="btn btn-primary" id="formSubmitBtn" onclick="submitExpenseForm(event)">
                <?php echo icon('plus', 'icon-sm'); ?> Add Expense
            </button>
        </div>
    </div>
</div>

<!-- ===== Delete Confirmation Modal ===== -->
<div class="modal-overlay" id="deleteModal" aria-modal="true" role="dialog">
    <div class="modal modal-confirm">
        <div class="modal-header">
            <div class="modal-title">Delete Expense</div>
            <button class="modal-close" onclick="closeDeleteConfirm()" aria-label="Close"><?php echo icon('close'); ?></button>
        </div>
        <div class="modal-body">
            <p id="deleteText" style="font-size:14px;color:var(--text-secondary);">Delete this expense record? This can&rsquo;t be undone.</p>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeDeleteConfirm()">Cancel</button>
            <button class="btn btn-danger" id="deleteConfirmBtn" onclick="confirmDelete()">
                <?php echo exp_icon('trash', 'icon-sm'); ?> Delete
            </button>
        </div>
    </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ============================================================
   Expenses page JS
   ============================================================ */
var ROWS = <?php echo $rowsJson; ?>;
var CSRF = <?php echo json_encode($csrf); ?>;
var rowMap = {};
ROWS.forEach(function (r) { rowMap[r.id] = r; });

var editingId  = null; // set = edit mode, null = create mode
var deletingId = null;

/* ---------- Add / Edit modal ---------- */
function openAdd() {
    editingId = null;
    document.getElementById('expenseForm').reset();
    document.getElementById('fDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('formTitle').textContent    = 'Add Expense';
    document.getElementById('formSubtitle').textContent = 'Record a new expense transaction.';
    document.getElementById('formIconBox').innerHTML    = <?php echo json_encode(icon('plus', 'icon-sm')); ?>;
    document.getElementById('formSubmitBtn').innerHTML  = <?php echo json_encode(icon('plus', 'icon-sm') . ' Add Expense'); ?>;
    document.getElementById('formSubmitBtn').className  = 'btn btn-primary';
    document.getElementById('formDeleteBtn').style.display = 'none';
    hideFormAlert();
    document.getElementById('expenseFormModal').classList.add('is-open');
}

function openEdit(id) {
    var r = rowMap[id];
    if (!r) return;
    editingId = id;

    document.getElementById('fDate').value     = r.expense_date;
    document.getElementById('fCategory').value = r.category;
    document.getElementById('fAmount').value   = r.amount.toFixed(2);
    document.getElementById('fNotes').value    = r.notes;

    document.getElementById('formTitle').textContent    = 'Update Expense';
    document.getElementById('formSubtitle').textContent = r.category;
    document.getElementById('formIconBox').innerHTML    = <?php echo json_encode(icon('edit', 'icon-sm')); ?>;
    document.getElementById('formSubmitBtn').innerHTML  = <?php echo json_encode(icon('save', 'icon-sm') . ' Save Changes'); ?>;
    document.getElementById('formSubmitBtn').className  = 'btn btn-success';
    document.getElementById('formDeleteBtn').style.display = '';
    hideFormAlert();
    document.getElementById('expenseFormModal').classList.add('is-open');
}

function closeExpenseForm() {
    document.getElementById('expenseFormModal').classList.remove('is-open');
    editingId = null;
}
document.getElementById('expenseFormModal').addEventListener('click', function (e) { if (e.target === this) closeExpenseForm(); });

function showFormAlert(msg) {
    var el = document.getElementById('formAlert');
    el.textContent = msg;
    el.style.display = '';
}
function hideFormAlert() {
    document.getElementById('formAlert').style.display = 'none';
}

function submitExpenseForm(e) {
    e.preventDefault();

    var date     = document.getElementById('fDate').value;
    var category = document.getElementById('fCategory').value;
    var amount   = parseFloat(document.getElementById('fAmount').value);
    var notes    = document.getElementById('fNotes').value.trim();

    if (!date)                          { showFormAlert('Date is required.'); return; }
    if (!category)                      { showFormAlert('Please select a category.'); return; }
    if (isNaN(amount) || amount <= 0)   { showFormAlert('Amount must be greater than zero.'); return; }
    if (!notes)                         { showFormAlert('Notes are required.'); return; }
    hideFormAlert();

    var btn = document.getElementById('formSubmitBtn');
    btn.disabled = true;

    var payload = {
        action:        editingId ? 'update' : 'create',
        csrf_token:    CSRF,
        expense_date:  date,
        category:      category,
        amount:        amount,
        notes:         notes
    };
    if (editingId) payload.id = editingId;

    fetch('api/expenses.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast(editingId ? 'Expense updated successfully!' : 'Expense added successfully!', 'success');
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
    document.getElementById('deleteText').textContent =
        'Delete this ' + r.category + ' expense of ₱' + fmt(r.amount) + '? This can\'t be undone.';
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

    fetch('api/expenses.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', csrf_token: CSRF, id: deletingId })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            toast('Expense deleted.', 'success');
            setTimeout(function () { window.location.reload(); }, 700);
        } else {
            toast(data.error || 'Failed to delete expense.', 'error');
            btn.disabled = false;
        }
    })
    .catch(function () {
        toast('Network error. Please try again.', 'error');
        btn.disabled = false;
    });
}

/* ---------- Helpers ---------- */
function fmt(n) { return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

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

/* ---------- Filters (month / category / search notes) ---------- */
function applyFilter(key, val) {
    var url = new URL(window.location.href);
    if (val) url.searchParams.set(key, val);
    else url.searchParams.delete(key);
    window.location.href = url.toString();
}
document.getElementById('monthSelect').addEventListener('change', function () {
    var url = new URL(window.location.href);
    url.searchParams.set('month', this.value);
    window.location.href = url.toString();
});
document.getElementById('categorySelect').addEventListener('change', function () { applyFilter('category', this.value); });
var searchTimer;
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    var v = this.value.trim();
    searchTimer = setTimeout(function () { applyFilter('search', v); }, 500);
});

/* Topbar's own "+ Add Expense" action button (page_action_label) opens
   the same modal as the one above the table. */
var topbarActionBtn = document.querySelector('.btn-action');
if (topbarActionBtn) topbarActionBtn.addEventListener('click', openAdd);
</script>

<?php require __DIR__ . '/includes/foot.php'; ?>