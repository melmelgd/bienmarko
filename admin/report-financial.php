<?php
/**
 * admin/report-financial.php
 * -----------------------------------------------------------------------
 * Financial Reports for the selected period:
 *   - Booked revenue, Collected revenue, Total expenses, Net profit
 *   - Revenue by accommodation (type -> individual unit), switchable
 *     between Booked and Collected
 *   - Expenses by category
 *   - Payment collection status (paid vs pending)
 * Headline figures come from includes/reports-data.php so the on-screen
 * numbers and the PDF/XLSX export (reports-export.php) stay in step. The
 * per-accommodation Booked / Collected split is queried here, over the
 * same bookings (Confirmed + Completed, check-in inside the period).
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
$data   = reports_financial_data($pdo, $period['start'], $period['end']);

$active_page         = 'report';
$page_title          = 'Financial reports';
$breadcrumb          = 'Financial reports';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('financial', $period);

/* ------------------------------------------------------------------
   Headline numbers
------------------------------------------------------------------ */
$booked    = (float) $data['revenue'];       // every booking in the period
$collected = (float) $data['collected'];     // money actually received
$pending   = (float) $data['outstanding'];   // still owed
$expenses  = (float) $data['expenses'];
$netProfit = $collected - $expenses;          // cash in - cash out
$netIfPaid = $booked - $expenses;             // if every pending balance is paid

/* Period line: "November 2026 · Nov 1 – 30, 2026" */
$pStart = new DateTime($period['start']);
$pEnd   = new DateTime($period['end']);
$rangeText = '';
if (in_array($period['period'], ['month', 'year'], true)) {
    $rangeText = ($pStart->format('Y-m') === $pEnd->format('Y-m'))
        ? $pStart->format('M j') . ' – ' . $pEnd->format('j, Y')
        : $pStart->format('M j') . ' – ' . $pEnd->format('M j, Y');
}

/* ------------------------------------------------------------------
   Revenue by accommodation — booked + collected per unit, grouped by type
------------------------------------------------------------------ */
$inStatuses = "'" . implode("','", RPT_REVENUE_STATUSES) . "'";
$stmt = $pdo->prepare(
    "SELECT a.id, a.name AS label, a.type AS type, COUNT(*) AS bookings,
            COALESCE(SUM(b.total_amount), 0)                AS booked,
            COALESCE(SUM(COALESCE(p.total_paid, 0)), 0)     AS collected
     FROM bookings b
     JOIN accommodations a ON a.id = b.accommodation_id
     LEFT JOIN payments p  ON p.booking_id = b.id
     WHERE b.status IN ($inStatuses) AND b.check_in BETWEEN :s AND :e
     GROUP BY a.id, a.name, a.type
     ORDER BY booked DESC"
);
$stmt->execute(['s' => $period['start'], 'e' => $period['end']]);
$unitRows = $stmt->fetchAll();

$typeNames = ['Room' => 'Rooms', 'Cottage' => 'Cottages', 'Event Hall' => 'Event halls', 'Swimming Pool' => 'Pool'];
$tree = [];
foreach (array_keys($typeNames) as $t) {              // the four standard types always show
    $tree[$t] = ['booked' => 0.0, 'collected' => 0.0, 'units' => []];
}
foreach ($unitRows as $u) {
    $t = trim((string) $u['type']);
    if (!isset($tree[$t])) {
        $tree[$t] = ['booked' => 0.0, 'collected' => 0.0, 'units' => []];
    }
    $tree[$t]['booked']    += (float) $u['booked'];
    $tree[$t]['collected'] += (float) $u['collected'];
    $tree[$t]['units'][]    = $u;
}

$maxTypeBooked = 0.0; $maxTypeCollected = 0.0;
foreach ($tree as $node) {
    $maxTypeBooked    = max($maxTypeBooked, $node['booked']);
    $maxTypeCollected = max($maxTypeCollected, $node['collected']);
}

/* ------------------------------------------------------------------
   Expenses by category
------------------------------------------------------------------ */
$expCats = array_values(array_filter($data['expensesByCategory'], function ($r) { return (float) $r['amount'] > 0; }));
$maxExp  = 0.0;
foreach ($expCats as $r) { $maxExp = max($maxExp, (float) $r['amount']); }

/* Payment collection bar (paid vs pending share of what was billed) */
$collectBase = $collected + $pending;
$paidPct     = $collectBase > 0 ? round(($collected / $collectBase) * 100, 2) : 0;

function fin_money(float $n): string { return '₱' . number_format($n, 2); }
function fin_pct(float $part, float $whole): float { return $whole > 0 ? round(($part / $whole) * 100, 2) : 0; }

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';
?>

<style>
/* ============ Financial report — page-scoped styles ============ */
.fin-period { font-size: 17px; color: var(--text-secondary); }
.fin-period strong { color: var(--text); font-weight: 600; }

.fin-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.fin-kpi { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 26px; }
.fin-kpi-label { font-size: 15px; color: var(--text-secondary); }
.fin-kpi-value { display: block; font-family: var(--font-display); font-size: 38px; font-weight: 700; line-height: 1; color: var(--text); margin: 18px 0 14px; white-space: nowrap; }
.fin-kpi-value--green { color: var(--emerald); }
.fin-kpi-value--red   { color: var(--red); }
.fin-kpi-sub { font-size: 13.5px; color: var(--text-secondary); line-height: 1.45; }

.fin-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 20px; align-items: stretch; }
.fin-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 28px; }
.fin-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 22px; }
.fin-panel-title { font-family: var(--font-display); font-size: 24px; font-weight: 700; margin: 0; }
.fin-panel-right { display: flex; align-items: center; gap: 14px; }
.fin-total { font-size: 15px; color: var(--text-secondary); }

.fin-seg { display: inline-flex; padding: 3px; border: 1px solid var(--border); border-radius: var(--r-md); background: var(--panel-alt); }
.fin-seg button { padding: 7px 16px; border: none; border-radius: var(--r-sm); background: transparent; color: var(--text-secondary); font-size: 14px; font-family: inherit; cursor: pointer; transition: color var(--transition), background var(--transition); }
.fin-seg button.is-active { background: var(--amber-bg); color: var(--gold-400); }

.fin-bars { display: flex; flex-direction: column; gap: 18px; }
.fin-row-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.fin-row-label { font-size: 17px; color: var(--text-secondary); }
.fin-row-amount { font-family: var(--font-display); font-weight: 700; font-size: 16px; color: var(--text-secondary); white-space: nowrap; }
.fin-row--has .fin-row-label, .fin-row--has .fin-row-amount { color: var(--text); }
.fin-row--type.fin-row--has .fin-row-label { font-weight: 600; }
.fin-track { height: 8px; border-radius: var(--r-full); background: var(--panel-alt); overflow: hidden; }
.fin-fill { height: 100%; border-radius: var(--r-full); background: var(--gold-500); transition: width .35s ease; }
.fin-fill--red { background: #f0616d; }
.fin-children { display: flex; flex-direction: column; gap: 16px; margin: 18px 0 2px 18px; }
.fin-children .fin-row-label { font-size: 16px; color: var(--text); }
.fin-empty { color: var(--text-tertiary); font-size: 14px; padding: 12px 0; }

.fin-collect-bar { display: flex; height: 14px; border-radius: var(--r-full); overflow: hidden; background: var(--panel-alt); margin-bottom: 26px; }
.fin-collect-paid    { background: var(--emerald); }
.fin-collect-pending { background: var(--gold-500); flex: 1; }
.fin-collect-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.fin-collect-label { font-size: 15px; color: var(--text-secondary); margin-bottom: 14px; }
.fin-collect-amount { font-family: var(--font-display); font-size: 40px; font-weight: 700; line-height: 1; }
.fin-collect-amount--paid    { color: var(--emerald); }
.fin-collect-amount--pending { color: var(--gold-400); }
.fin-collect-count { font-size: 14px; color: var(--text-secondary); margin-top: 14px; }

@media (max-width: 1280px) { .fin-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 1000px) { .fin-grid { grid-template-columns: 1fr; } }
@media (max-width: 600px)  { .fin-kpi-grid, .fin-collect-grid { grid-template-columns: 1fr; } .fin-panel { padding: 20px; } }
@media print {
  .fin-kpi, .fin-panel { background: #fff !important; border: 1px solid #ddd !important; break-inside: avoid; }
  .fin-seg { display: none; }
}
</style>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="fin-period">
    <strong><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($rangeText !== ''): ?> · <?php echo htmlspecialchars($rangeText, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
  </div>

  <!-- ===== Headline numbers ===== -->
  <div class="fin-kpi-grid">
    <div class="fin-kpi">
      <div class="fin-kpi-label">Booked revenue</div>
      <span class="fin-kpi-value"><?php echo fin_money($booked); ?></span>
      <div class="fin-kpi-sub">All bookings in this period · <?php echo (int) $data['bookingCount']; ?> booking<?php echo (int) $data['bookingCount'] === 1 ? '' : 's'; ?></div>
    </div>
    <div class="fin-kpi">
      <div class="fin-kpi-label">Collected revenue</div>
      <span class="fin-kpi-value fin-kpi-value--green"><?php echo fin_money($collected); ?></span>
      <div class="fin-kpi-sub"><?php echo fin_money($pending); ?> still pending</div>
    </div>
    <div class="fin-kpi">
      <div class="fin-kpi-label">Total expenses</div>
      <span class="fin-kpi-value"><?php echo fin_money($expenses); ?></span>
      <div class="fin-kpi-sub">Inventory purchases and other expenses</div>
    </div>
    <div class="fin-kpi">
      <div class="fin-kpi-label">Net profit</div>
      <span class="fin-kpi-value <?php echo $netProfit < 0 ? 'fin-kpi-value--red' : 'fin-kpi-value--green'; ?>"><?php echo ($netProfit < 0 ? '−' : '') . fin_money(abs($netProfit)); ?></span>
      <div class="fin-kpi-sub">Collected − expenses · <?php echo ($netIfPaid < 0 ? '−' : '') . fin_money(abs($netIfPaid)); ?> if all pending is paid</div>
    </div>
  </div>

  <!-- ===== Revenue by accommodation + Expenses by category ===== -->
  <div class="fin-grid">
    <div class="fin-panel">
      <div class="fin-panel-head">
        <h2 class="fin-panel-title">Revenue by accommodation</h2>
        <div class="fin-panel-right">
          <span class="fin-total" id="finRevTotal" data-booked="<?php echo $booked; ?>" data-collected="<?php echo $collected; ?>"><?php echo fin_money($booked); ?> total</span>
          <div class="fin-seg" role="group" aria-label="Revenue basis">
            <button type="button" class="is-active" data-fin-mode="booked">Booked</button>
            <button type="button" data-fin-mode="collected">Collected</button>
          </div>
        </div>
      </div>

      <div class="fin-bars" id="finRevenueBars"
           data-max-booked="<?php echo $maxTypeBooked; ?>" data-max-collected="<?php echo $maxTypeCollected; ?>">
        <?php foreach ($tree as $type => $node): ?>
          <div class="fin-group" data-type
               data-booked="<?php echo $node['booked']; ?>" data-collected="<?php echo $node['collected']; ?>">
            <div class="fin-row fin-row--type <?php echo $node['booked'] > 0 ? 'fin-row--has' : ''; ?>">
              <div class="fin-row-head">
                <span class="fin-row-label"><?php echo htmlspecialchars($typeNames[$type] ?? $type, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="fin-row-amount" data-amount><?php echo fin_money($node['booked']); ?></span>
              </div>
              <div class="fin-track"><div class="fin-fill" data-fill style="width: <?php echo fin_pct($node['booked'], $maxTypeBooked); ?>%"></div></div>
            </div>
            <?php if (!empty($node['units'])): ?>
              <div class="fin-children">
                <?php foreach ($node['units'] as $u): ?>
                  <div class="fin-row fin-row--has" data-unit
                       data-booked="<?php echo (float) $u['booked']; ?>" data-collected="<?php echo (float) $u['collected']; ?>">
                    <div class="fin-row-head">
                      <span class="fin-row-label"><?php echo htmlspecialchars($u['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <span class="fin-row-amount" data-amount><?php echo fin_money((float) $u['booked']); ?></span>
                    </div>
                    <div class="fin-track"><div class="fin-fill" data-fill style="width: <?php echo fin_pct((float) $u['booked'], $node['booked']); ?>%"></div></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="fin-panel">
      <div class="fin-panel-head">
        <h2 class="fin-panel-title">Expenses by category</h2>
        <span class="fin-total"><?php echo fin_money($expenses); ?> total</span>
      </div>
      <?php if (empty($expCats)): ?>
        <div class="fin-empty">No expenses recorded in this period.</div>
      <?php else: ?>
        <div class="fin-bars">
          <?php foreach ($expCats as $r): ?>
            <div class="fin-row fin-row--has">
              <div class="fin-row-head">
                <span class="fin-row-label"><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="fin-row-amount"><?php echo fin_money((float) $r['amount']); ?></span>
              </div>
              <div class="fin-track"><div class="fin-fill fin-fill--red" style="width: <?php echo fin_pct((float) $r['amount'], $maxExp); ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== Payment collection status ===== -->
  <div class="fin-panel">
    <div class="fin-panel-head" style="margin-bottom:18px;">
      <h2 class="fin-panel-title">Payment collection status</h2>
    </div>
    <div class="fin-collect-bar" role="img" aria-label="Paid <?php echo $paidPct; ?> percent, pending the rest">
      <div class="fin-collect-paid" style="width: <?php echo $paidPct; ?>%"></div>
      <div class="fin-collect-pending"></div>
    </div>
    <div class="fin-collect-grid">
      <div>
        <div class="fin-collect-label">Paid</div>
        <div class="fin-collect-amount fin-collect-amount--paid"><?php echo fin_money($collected); ?></div>
        <div class="fin-collect-count"><?php echo (int) $data['paidCount']; ?> transaction<?php echo (int) $data['paidCount'] === 1 ? '' : 's'; ?></div>
      </div>
      <div>
        <div class="fin-collect-label">Pending</div>
        <div class="fin-collect-amount fin-collect-amount--pending"><?php echo fin_money($pending); ?></div>
        <div class="fin-collect-count"><?php echo (int) $data['pendingCount']; ?> transaction<?php echo (int) $data['pendingCount'] === 1 ? '' : 's'; ?></div>
      </div>
    </div>
  </div>
</div>

<script>
/* Booked / Collected switch for "Revenue by accommodation" */
(function () {
  var root  = document.getElementById('finRevenueBars');
  var total = document.getElementById('finRevTotal');
  if (!root || !total) return;

  function money(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function pct(part, whole) { return whole > 0 ? Math.round(part / whole * 10000) / 100 : 0; }

  function setMode(mode) {
    var maxType = parseFloat(root.getAttribute('data-max-' + mode)) || 0;
    root.querySelectorAll('[data-type]').forEach(function (group) {
      var typeVal = parseFloat(group.getAttribute('data-' + mode)) || 0;
      var head = group.querySelector('.fin-row--type');
      head.classList.toggle('fin-row--has', typeVal > 0);
      head.querySelector('[data-amount]').textContent = money(typeVal);
      head.querySelector('[data-fill]').style.width = pct(typeVal, maxType) + '%';
      group.querySelectorAll('[data-unit]').forEach(function (unit) {
        var v = parseFloat(unit.getAttribute('data-' + mode)) || 0;
        unit.querySelector('[data-amount]').textContent = money(v);
        unit.querySelector('[data-fill]').style.width = pct(v, typeVal) + '%';
      });
    });
    total.textContent = money(parseFloat(total.getAttribute('data-' + mode)) || 0) + ' total';
    document.querySelectorAll('[data-fin-mode]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-fin-mode') === mode);
    });
  }

  document.querySelectorAll('[data-fin-mode]').forEach(function (b) {
    b.addEventListener('click', function () { setMode(b.getAttribute('data-fin-mode')); });
  });
})();
</script>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>