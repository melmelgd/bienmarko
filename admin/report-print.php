<?php
/**
 * admin/report-print.php
 * -----------------------------------------------------------------------
 * Paper-ready version of each Reports & Analytics report.
 *
 *   report-print.php?report=financial|performance|guest-insights|inventory
 *                   &period=week|month|year|custom [&start=Y-m-d&end=Y-m-d]
 *                   [&autoprint=1]
 *
 * Why a separate page instead of window.print() on the dashboard screen:
 * the on-screen reports are built for a dark dashboard (hover charts,
 * "See All" links, pastel cards, a sidebar, a toolbar). Printing that
 * wastes ink and hides numbers behind charts. This page prints only what
 * an admin needs on paper: a letterhead (property, report, period, who
 * and when), the key figures, and clearly labelled tables that add up —
 * plus a short "How these figures are calculated" note so the printout
 * can be understood without the screen.
 *
 * Numbers come from the SAME reports_*_data() functions as the on-screen
 * pages and the PDF/XLSX export (includes/reports-data.php), over the
 * same resolved date range, so the printout can never disagree with them.
 *
 * Self-contained on purpose (own CSS + tiny JS, no shared admin theme):
 * it must look the same on paper regardless of the dashboard's dark theme.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/reports-data.php';

$pdo = bienmarko_db();
reports_ensure_core_tables($pdo);

$report         = (string) ($_GET['report'] ?? '');
$allowedReports = ['financial', 'performance', 'guest-insights', 'inventory'];
if (!in_array($report, $allowedReports, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unknown report requested.';
    exit;
}

$period    = reports_resolve_period($_GET);
$autoPrint = (($_GET['autoprint'] ?? '') === '1');

$titles = [
    'financial'      => 'Financial Report',
    'performance'    => 'Performance Report',
    'guest-insights' => 'Guest Insights Report',
    'inventory'      => 'Inventory Report',
];
$reportTitle = $titles[$report];

/* ------------------------------------------------------------------
   Letterhead details — property info from Settings, admin from session.
   Both are best-effort: a missing table/row just falls back to defaults.
------------------------------------------------------------------ */
$property = ['name' => "Bien Marko's Pension House", 'address' => '', 'phone' => '', 'email' => ''];
try {
    $row = $pdo->query('SELECT property_name, contact_email, contact_number, address FROM system_settings WHERE id = 1')->fetch();
    if ($row) {
        if (trim((string) $row['property_name']) !== '') { $property['name'] = trim((string) $row['property_name']); }
        $property['address'] = trim((string) $row['address']);
        $property['phone']   = trim((string) $row['contact_number']);
        $property['email']   = trim((string) $row['contact_email']);
    }
} catch (\Throwable $e) {
    /* system_settings not created yet — keep defaults */
}

$preparedBy = '';
try {
    $st = $pdo->prepare('SELECT name FROM admins WHERE id = :id');
    $st->execute(['id' => (int) ($_SESSION['auth_user_id'] ?? 0)]);
    $preparedBy = trim((string) $st->fetchColumn());
} catch (\Throwable $e) {
    /* leave blank */
}

/* ------------------------------------------------------------------
   Small formatting helpers (rp_ prefix: local to this page)
------------------------------------------------------------------ */
function rp_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** ₱1,234.00 — negatives as −₱1,234.00 so a loss is unmistakable in black & white. */
function rp_money(float $v, int $dec = 2): string
{
    $neg = $v < 0 && round(abs($v), $dec) > 0;
    return ($neg ? '−' : '') . '₱' . number_format(abs($v), $dec);
}

function rp_int($v): string
{
    return number_format((int) $v);
}

/** Share of a whole as "37.5%", or an em dash when the whole is zero. */
function rp_pct(float $part, float $whole): string
{
    return $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '—';
}

function rp_plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

function rp_empty(string $message): void
{
    echo '<p class="empty">' . rp_h($message) . '</p>';
}

$startNice = (new DateTime($period['start']))->format('M j, Y');
$endNice   = (new DateTime($period['end']))->format('M j, Y');
$rangeNice = $startNice === $endNice ? $startNice : $startNice . ' – ' . $endNice;
$generated = date('F j, Y \a\t g:i A');

/* Reports that are not period-based say so on the letterhead. */
$periodNote = '';
if ($report === 'inventory') {
    $periodNote = 'Stock levels are as of the time of printing; item usage is for the period shown.';
} elseif ($report === 'guest-insights') {
    $periodNote = 'Guest counts are for the period shown; ratings and returning guests are all-time.';
} elseif ($report === 'performance') {
    $periodNote = 'Rankings are for the period shown; monthly occupancy covers the full calendar year ' . (int) date('Y', strtotime($period['end'])) . '.';
}

/* ==================================================================
   REPORT BODIES — each prints into the page; none touches the DB
   except through reports-data.php.
================================================================== */

/* ---------------------------- FINANCIAL --------------------------- */
function rp_body_financial(PDO $pdo, array $period): void
{
    $d       = reports_financial_data($pdo, $period['start'], $period['end']);
    $revenue = (float) $d['revenue'];
    $expense = (float) $d['expenses'];
    $profit  = (float) $d['profit'];
    ?>
    <section class="block">
      <h2>Summary</h2>
      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Total Revenue</span>
          <span class="kpi-value"><?php echo rp_money($revenue); ?></span>
          <span class="kpi-sub"><?php echo rp_plural((int) $d['bookingCount'], 'booking'); ?></span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Total Expenses</span>
          <span class="kpi-value"><?php echo rp_money($expense); ?></span>
          <span class="kpi-sub"><?php echo rp_plural((int) array_sum(array_column($d['expensesByCategory'], 'entries')), 'expense entry', 'expense entries'); ?></span>
        </div>
        <div class="kpi kpi--strong">
          <span class="kpi-label"><?php echo $profit < 0 ? 'Net Loss' : 'Net Profit'; ?></span>
          <span class="kpi-value"><?php echo rp_money($profit); ?></span>
          <span class="kpi-sub">Revenue − Expenses</span>
        </div>
      </div>
    </section>

    <section>
      <h2>Revenue by Accommodation</h2>
      <?php
      /* Group the individual accommodations under their type, biggest first. */
      $groups = [];
      foreach ($d['byAccommodation'] as $r) {
          $type = trim((string) $r['type']) !== '' ? (string) $r['type'] : 'Other';
          if (!isset($groups[$type])) {
              $groups[$type] = ['label' => rpt_type_label($type), 'bookings' => 0, 'amount' => 0.0, 'rows' => []];
          }
          $groups[$type]['bookings'] += (int) $r['bookings'];
          $groups[$type]['amount']   += (float) $r['amount'];
          $groups[$type]['rows'][]    = $r;
      }
      uasort($groups, fn($a, $b) => $b['amount'] <=> $a['amount']);

      /* Revenue is summed straight from bookings; the breakdown joins to
         accommodations. If an accommodation was deleted after it was booked,
         those bookings would be missing from the breakdown — show them on
         their own line so the table always adds up to Total Revenue. */
      $listedAmount   = array_sum(array_column($groups, 'amount'));
      $listedBookings = array_sum(array_column($groups, 'bookings'));
      $gapAmount      = round($revenue - $listedAmount, 2);
      $gapBookings    = (int) $d['bookingCount'] - (int) $listedBookings;
      ?>
      <?php if ($revenue <= 0 && empty($groups)): ?>
        <?php rp_empty('No revenue recorded for this period.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Accommodation</th>
              <th class="num">Bookings</th>
              <th class="num">Revenue</th>
              <th class="num">% of Revenue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $g): ?>
              <tr class="group">
                <td><?php echo rp_h($g['label']); ?></td>
                <td class="num"><?php echo rp_int($g['bookings']); ?></td>
                <td class="num"><?php echo rp_money($g['amount']); ?></td>
                <td class="num"><?php echo rp_pct($g['amount'], $revenue); ?></td>
              </tr>
              <?php foreach ($g['rows'] as $r): ?>
                <tr class="sub">
                  <td><?php echo rp_h($r['label']); ?></td>
                  <td class="num"><?php echo rp_int($r['bookings']); ?></td>
                  <td class="num"><?php echo rp_money((float) $r['amount']); ?></td>
                  <td class="num"><?php echo rp_pct((float) $r['amount'], $revenue); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (abs($gapAmount) >= 0.01 || $gapBookings !== 0): ?>
              <tr class="group">
                <td>Other / accommodation no longer listed</td>
                <td class="num"><?php echo rp_int(max(0, $gapBookings)); ?></td>
                <td class="num"><?php echo rp_money($gapAmount); ?></td>
                <td class="num"><?php echo rp_pct($gapAmount, $revenue); ?></td>
              </tr>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="total">
              <td>Total Revenue</td>
              <td class="num"><?php echo rp_int($d['bookingCount']); ?></td>
              <td class="num"><?php echo rp_money($revenue); ?></td>
              <td class="num"><?php echo $revenue > 0 ? '100.0%' : '—'; ?></td>
            </tr>
          </tfoot>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="block">
      <h2>Payment Collection</h2>
      <p class="lead">Of the revenue above, how much has actually been received.</p>
      <div class="tbl-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>&nbsp;</th>
            <th class="num">Bookings</th>
            <th class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Total billed (Total Revenue)</td>
            <td class="num"><?php echo rp_int($d['bookingCount']); ?></td>
            <td class="num"><?php echo rp_money($revenue); ?></td>
          </tr>
          <tr>
            <td>Collected (payments received)</td>
            <td class="num"><?php echo rp_int($d['paidCount']); ?></td>
            <td class="num"><?php echo rp_money((float) $d['collected']); ?></td>
          </tr>
          <tr class="total">
            <td>Outstanding balance (still to collect)</td>
            <td class="num"><?php echo rp_int($d['pendingCount']); ?></td>
            <td class="num"><?php echo rp_money((float) $d['outstanding']); ?></td>
          </tr>
        </tbody>
      </table>
      </div>
      <p class="lead">
        Booking payment status:
        <strong><?php echo rp_int($d['fullyPaidCount']); ?></strong> fully paid ·
        <strong><?php echo rp_int($d['partialCount']); ?></strong> partially paid ·
        <strong><?php echo rp_int($d['unpaidCount']); ?></strong> unpaid.
      </p>
    </section>

    <section>
      <h2>Expenses by Category</h2>
      <?php if (empty($d['expensesByCategory'])): ?>
        <?php rp_empty('No expenses recorded for this period.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Category</th>
              <th class="num">Entries</th>
              <th class="num">Amount</th>
              <th class="num">% of Expenses</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($d['expensesByCategory'] as $r): ?>
              <tr>
                <td><?php echo rp_h($r['label']); ?></td>
                <td class="num"><?php echo rp_int($r['entries']); ?></td>
                <td class="num"><?php echo rp_money((float) $r['amount']); ?></td>
                <td class="num"><?php echo rp_pct((float) $r['amount'], $expense); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="total">
              <td>Total Expenses</td>
              <td class="num"><?php echo rp_int(array_sum(array_column($d['expensesByCategory'], 'entries'))); ?></td>
              <td class="num"><?php echo rp_money($expense); ?></td>
              <td class="num"><?php echo $expense > 0 ? '100.0%' : '—'; ?></td>
            </tr>
          </tfoot>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="notes block">
      <h2>How these figures are calculated</h2>
      <ul>
        <li><strong>Revenue</strong> is the billed amount (after discounts and extra charges) of <em>Confirmed</em> and <em>Completed</em> bookings whose <em>check-in date</em> falls in this period. Cancelled and Pending bookings are not counted.</li>
        <li><strong>Collected</strong> is the money recorded as received on those same bookings, including partial payments. <strong>Outstanding</strong> is what is still owed on them.</li>
        <li><strong>Expenses</strong> are the expenses recorded with a date inside this period. <strong>Net profit</strong> = Revenue − Expenses.</li>
      </ul>
    </section>
    <?php
}

/* --------------------------- PERFORMANCE -------------------------- */
function rp_body_performance(PDO $pdo, array $period): void
{
    $d          = reports_performance_data($pdo, $period['start'], $period['end']);
    $unitCounts = $d['unitCounts'];

    /* Only show categories the resort actually has units for. */
    $types = array_values(array_filter($d['types'], fn($t) => ($unitCounts[$t] ?? 0) > 0));

    /* Rankings: types with bookings first (already ordered by the data
       layer), then types that have units but no bookings, shown as 0. */
    $ranked  = [];
    $seen    = [];
    foreach ($d['rankings'] as $r) {
        $ranked[] = ['type' => $r['label'], 'bookings' => (int) $r['bookings'], 'revenue' => (float) $r['revenue']];
        $seen[$r['label']] = true;
    }
    foreach ($types as $t) {
        if (!isset($seen[$t])) {
            $ranked[] = ['type' => $t, 'bookings' => 0, 'revenue' => 0.0];
        }
    }
    $totalBookings = array_sum(array_column($ranked, 'bookings'));
    $totalRevenue  = array_sum(array_column($ranked, 'revenue'));
    ?>
    <section>
      <h2>Performance Ranking — <?php echo rp_h($period['label']); ?></h2>
      <p class="lead">Accommodation types ranked by number of bookings (Confirmed and Completed, check-in within the period).</p>
      <?php if (empty($ranked)): ?>
        <?php rp_empty('No accommodations are set up yet.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th class="num narrow">Rank</th>
              <th>Accommodation Type</th>
              <th class="num">Units</th>
              <th class="num">Bookings</th>
              <th class="num">Revenue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ranked as $i => $r): ?>
              <tr>
                <td class="num narrow"><?php echo $r['bookings'] > 0 ? ($i + 1) : '—'; ?></td>
                <td><?php echo rp_h(rpt_type_label($r['type'])); ?></td>
                <td class="num"><?php echo rp_int($unitCounts[$r['type']] ?? 0); ?></td>
                <td class="num"><?php echo rp_int($r['bookings']); ?></td>
                <td class="num"><?php echo rp_money($r['revenue']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="total">
              <td class="narrow">&nbsp;</td>
              <td>Total</td>
              <td class="num"><?php echo rp_int(array_sum(array_map(fn($t) => $unitCounts[$t] ?? 0, array_column($ranked, 'type')))); ?></td>
              <td class="num"><?php echo rp_int($totalBookings); ?></td>
              <td class="num"><?php echo rp_money($totalRevenue); ?></td>
            </tr>
          </tfoot>
        </table>
        </div>
        <?php if ($totalBookings === 0): ?>
          <p class="lead">No bookings were recorded in this period.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if (!empty($types)): ?>
    <section class="block">
      <h2>Peak Season by Type — <?php echo (int) $d['year']; ?></h2>
      <p class="lead">The longest stretch of months in which a type was busier than its own yearly average.</p>
      <div class="tbl-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Accommodation Type</th>
            <th>Peak Months</th>
            <th class="num">Avg. Occupancy in Peak</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($types as $t): ?>
            <?php $p = $d['peak'][$t]; $noData = ($p['label'] === 'No data'); ?>
            <tr>
              <td><?php echo rp_h(rpt_type_label($t)); ?></td>
              <td><?php echo $noData ? 'No bookings this year' : rp_h($p['label']); ?></td>
              <td class="num"><?php echo $noData ? '—' : (int) $p['pct'] . '%'; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>

    <section class="block">
      <h2>Monthly Occupancy — <?php echo (int) $d['year']; ?></h2>
      <p class="lead">Percentage of available unit-days that were booked, by month.</p>
      <?php
      $monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      ?>
      <div class="tbl-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Month</th>
            <?php foreach ($types as $t): ?>
              <th class="num"><?php echo rp_h(rpt_type_label($t)); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($monthNames as $mi => $mn): ?>
            <tr>
              <td><?php echo $mn; ?></td>
              <?php foreach ($types as $t): ?>
                <td class="num"><?php echo (int) $d['occupancy'][$t][$mi + 1]; ?>%</td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total">
            <td>Year average</td>
            <?php foreach ($types as $t): ?>
              <td class="num"><?php echo number_format(array_sum($d['occupancy'][$t]) / 12, 1); ?>%</td>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="notes block">
      <h2>How these figures are calculated</h2>
      <ul>
        <li><strong>Ranking</strong> counts Confirmed and Completed bookings by check-in date within the selected period; ties are broken by revenue.</li>
        <li><strong>Occupancy</strong> = nights booked ÷ (number of units × days in the month). It always covers the whole calendar year, whatever period is selected above.</li>
        <li>The <strong>Swimming Pool</strong> is shared by many guests at once, so its figure counts booking-days and can exceed 100% when several bookings overlap.</li>
      </ul>
    </section>
    <?php
}

/* -------------------------- GUEST INSIGHTS ------------------------ */
function rp_body_guest_insights(PDO $pdo, array $period): void
{
    $d = reports_guest_insights_data($pdo, $period['start'], $period['end']);
    ?>
    <section class="block">
      <h2>Summary</h2>
      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Total Guests</span>
          <span class="kpi-value"><?php echo rp_int($d['totalGuests']); ?></span>
          <span class="kpi-sub">Booked in this period</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Repeat Guests</span>
          <span class="kpi-value"><?php echo rp_int($d['repeatGuests']); ?></span>
          <span class="kpi-sub">Registered, 2+ bookings overall</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Average Guest Rating</span>
          <span class="kpi-value"><?php echo $d['avgRating'] !== null ? number_format((float) $d['avgRating'], 1) . ' / 5' : '—'; ?></span>
          <span class="kpi-sub"><?php echo $d['avgRating'] !== null ? 'All-time · ' . rp_plural((int) $d['ratingCount'], 'published review') : 'No published reviews yet'; ?></span>
        </div>
      </div>
    </section>

    <section>
      <h2>Top Returning Guests (all-time)</h2>
      <p class="lead">Registered guests with two or more bookings, most visits first (up to 5).</p>
      <?php if (empty($d['topGuests'])): ?>
        <?php rp_empty('No guest has booked more than once yet.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th class="num narrow">#</th>
              <th>Guest</th>
              <th class="num">Total Visits</th>
              <th>Most Booked Accommodation</th>
              <th>Country</th>
              <th class="num">Avg. Rating</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($d['topGuests'] as $i => $g): ?>
              <tr>
                <td class="num narrow"><?php echo $i + 1; ?></td>
                <td><strong><?php echo rp_h($g['name']); ?></strong></td>
                <td class="num"><?php echo rp_int($g['visits']); ?></td>
                <td><?php echo rp_h($g['most_booked']); ?></td>
                <td><?php echo rp_h($g['country']); ?></td>
                <td class="num"><?php echo $g['rating'] !== null ? number_format((float) $g['rating'], 1) : '—'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="notes block">
      <h2>How these figures are calculated</h2>
      <ul>
        <li><strong>Total Guests</strong> counts each guest once if they have a Confirmed, Completed or Pending booking with check-in in this period (walk-in guests are counted by name and email).</li>
        <li><strong>Repeat Guests</strong> are registered guests from that group who have more than one booking overall. Walk-in guests without an account cannot be matched across visits, so they are not included.</li>
        <li><strong>Ratings</strong> use published feedback only, across all dates. Cancelled bookings are never counted. A blank country is shown as Philippines.</li>
      </ul>
    </section>
    <?php
}

/* ----------------------------- INVENTORY -------------------------- */
function rp_body_inventory(PDO $pdo, array $period): void
{
    $d = reports_inventory_data($pdo, $period['start'], $period['end']);

    $labels = ['out_of_stock' => 'OUT OF STOCK', 'low_stock' => 'Low stock', 'in_stock' => 'In stock'];
    $rank   = ['out_of_stock' => 0, 'low_stock' => 1, 'in_stock' => 2];

    $items = $d['items'];
    $out = 0; $low = 0;
    foreach ($items as &$it) {
        $it['_status'] = reports_inv_status($it);
        if ($it['_status'] === 'out_of_stock') { $out++; }
        if ($it['_status'] === 'low_stock')    { $low++; }
    }
    unset($it);

    /* Most urgent first, then A–Z, so the items that need action are at the top. */
    usort($items, function ($a, $b) use ($rank) {
        return [$rank[$a['_status']], strtolower($a['name'])] <=> [$rank[$b['_status']], strtolower($b['name'])];
    });
    $needRestock = $out + $low;
    ?>
    <section class="block">
      <h2>Summary</h2>
      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">Total Items</span>
          <span class="kpi-value"><?php echo rp_int($d['totalItems']); ?></span>
          <span class="kpi-sub"><?php echo rp_plural((int) $d['totalUnits'], 'unit'); ?> on hand</span>
        </div>
        <div class="kpi <?php echo $needRestock > 0 ? 'kpi--strong' : ''; ?>">
          <span class="kpi-label">Need Restocking</span>
          <span class="kpi-value"><?php echo rp_int($needRestock); ?></span>
          <span class="kpi-sub"><?php echo rp_int($out); ?> out of stock · <?php echo rp_int($low); ?> low</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">Inventory Value</span>
          <span class="kpi-value"><?php echo rp_money((float) $d['totalValue']); ?></span>
          <span class="kpi-sub">On hand × unit cost</span>
        </div>
      </div>
    </section>

    <section>
      <h2>Stock Levels</h2>
      <p class="lead">Items needing attention are listed first.</p>
      <?php if (empty($items)): ?>
        <?php rp_empty('No inventory items have been added yet.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th class="num">On Hand</th>
              <th class="num">Minimum</th>
              <th class="num">Unit Cost</th>
              <th class="num">Value</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr class="<?php echo $it['_status'] === 'in_stock' ? '' : 'flag'; ?>">
                <td><strong><?php echo rp_h($it['name']); ?></strong></td>
                <td><?php echo rp_h($it['category']); ?></td>
                <td class="num"><?php echo rp_int($it['quantity']); ?></td>
                <td class="num"><?php echo rp_int($it['min_stock_level']); ?></td>
                <td class="num"><?php echo rp_money((float) $it['cost_per_unit']); ?></td>
                <td class="num"><?php echo rp_money((float) $it['cost_per_unit'] * (int) $it['quantity']); ?></td>
                <td class="status status--<?php echo rp_h($it['_status']); ?>"><?php echo rp_h($labels[$it['_status']]); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="total">
              <td colspan="2">Total (<?php echo rp_plural((int) $d['totalItems'], 'item'); ?>)</td>
              <td class="num"><?php echo rp_int($d['totalUnits']); ?></td>
              <td class="num">&nbsp;</td>
              <td class="num">&nbsp;</td>
              <td class="num"><?php echo rp_money((float) $d['totalValue']); ?></td>
              <td>&nbsp;</td>
            </tr>
          </tfoot>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="block">
      <h2>Most Used Items — <?php echo rp_h($period['label']); ?></h2>
      <?php if (empty($d['mostUsed'])): ?>
        <?php rp_empty('No item usage was logged in this period.'); ?>
      <?php else: ?>
        <div class="tbl-wrap">
        <table class="data">
          <thead>
            <tr>
              <th class="num narrow">#</th>
              <th>Item</th>
              <th class="num">Units Used</th>
              <th class="num">On Hand Now</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($d['mostUsed'] as $i => $m): ?>
              <tr>
                <td class="num narrow"><?php echo $i + 1; ?></td>
                <td><?php echo rp_h($m['name']); ?></td>
                <td class="num"><?php echo rp_int($m['used']); ?></td>
                <td class="num"><?php echo rp_int($m['stock']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="notes block">
      <h2>How these figures are calculated</h2>
      <ul>
        <li><strong>Out of stock</strong> = none on hand. <strong>Low stock</strong> = on hand is at or below the item's minimum level. Both count as “Need Restocking”.</li>
        <li><strong>Value</strong> = units on hand × unit cost. Stock levels are a snapshot taken when this report was printed, not for the period.</li>
        <li><strong>Units Used</strong> is the usage recorded on the Inventory page within the selected period (top 6 items).</li>
      </ul>
    </section>
    <?php
}

$docTitle = $reportTitle . ' — ' . $period['label'] . ' — ' . $property['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo rp_h($docTitle); ?></title>
<style>
/* =====================================================================
   Paper-first stylesheet. Black on white, no theme variables, so it
   prints identically whatever the dashboard theme is.
===================================================================== */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0; background: #e9ecef; color: #111;
  font-family: "Segoe UI", system-ui, -apple-system, Arial, "Helvetica Neue", sans-serif;
  font-size: 10.5pt; line-height: 1.45;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}

/* ---------- Screen-only preview chrome ---------- */
.screen-bar {
  position: sticky; top: 0; z-index: 10;
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 10px 16px; background: #1f2933; color: #fff;
}
.screen-bar .hint { font-size: 0.8rem; opacity: 0.8; }
.screen-bar .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.screen-bar a, .screen-bar button {
  font: inherit; font-size: 0.875rem; font-weight: 600; cursor: pointer;
  padding: 9px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35);
  background: transparent; color: #fff; text-decoration: none; min-height: 40px;
  display: inline-flex; align-items: center;
}
.screen-bar button.primary { background: #fff; color: #1f2933; border-color: #fff; }

.sheet {
  width: 100%; max-width: 210mm; margin: 16px auto; padding: 14mm 12mm;
  background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,0.18);
}

/* ---------- Letterhead ---------- */
.masthead { text-align: center; padding-bottom: 8px; border-bottom: 2px solid #111; }
.prop-name { font-size: 17pt; font-weight: 800; letter-spacing: 0.01em; }
.prop-meta { font-size: 9pt; color: #444; margin-top: 2px; }
.doc-title { font-size: 15pt; font-weight: 800; text-align: center; margin: 12px 0 8px; text-transform: uppercase; letter-spacing: 0.06em; }
.meta { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 4px; }
.meta td { padding: 2px 0; vertical-align: top; }
.meta td:first-child { width: 28mm; color: #444; font-weight: 600; }
.period-note { font-size: 9pt; color: #444; font-style: italic; margin: 2px 0 0; }

/* ---------- Sections ---------- */
section { margin-top: 16px; }
h2 { font-size: 11.5pt; margin: 0 0 6px; padding-bottom: 3px; border-bottom: 1px solid #111; break-after: avoid; page-break-after: avoid; }
.lead { margin: 0 0 6px; font-size: 9.5pt; color: #333; }
.empty { margin: 6px 0; padding: 10px; border: 1px dashed #888; text-align: center; color: #444; font-style: italic; }

/* ---------- Key figures ---------- */
.kpis { display: flex; border: 1.5px solid #111; }
.kpi { flex: 1 1 0; min-width: 0; padding: 8px 10px; border-right: 1px solid #111; display: flex; flex-direction: column; gap: 1px; }
.kpi:last-child { border-right: none; }
.kpi-label { font-size: 8.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #333; }
.kpi-value { font-size: 15pt; font-weight: 800; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.kpi-sub { font-size: 8.5pt; color: #444; }
.kpi--strong { background: #eef1f4; }

/* ---------- Tables ---------- */
.tbl-wrap { overflow-x: auto; }
table.data { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
table.data th {
  text-align: left; font-size: 8.5pt; font-weight: 700; padding: 5px 8px;
  background: #e3e7eb; border-top: 1.5px solid #111; border-bottom: 1.5px solid #111;
}
table.data td { padding: 5px 8px; border-bottom: 1px solid #c9ced4; vertical-align: top; }
table.data .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.data .narrow { width: 12mm; }
table.data thead { display: table-header-group; }
table.data tfoot { display: table-footer-group; }
table.data tr { break-inside: avoid; page-break-inside: avoid; }
table.data tr.group td { font-weight: 700; background: #f1f3f5; }
table.data tr.sub td:first-child { padding-left: 22px; }
table.data tr.total td { font-weight: 800; background: #e3e7eb; border-top: 1.5px solid #111; border-bottom: 1.5px solid #111; }
table.data tr.flag td { background: #fff7e6; }
table.data td.status { font-weight: 600; white-space: nowrap; }
table.data td.status--out_of_stock { font-weight: 800; }

/* ---------- Notes + footer ---------- */
.notes { padding: 8px 12px; border: 1px solid #888; background: #f8f9fa; font-size: 9pt; }
.notes h2 { font-size: 10pt; border: none; padding: 0; margin-bottom: 4px; }
.notes ul { margin: 0; padding-left: 16px; }
.notes li { margin: 2px 0; }
.block { break-inside: avoid; page-break-inside: avoid; }
.doc-foot {
  margin-top: 18px; padding-top: 6px; border-top: 1px solid #111;
  display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  font-size: 8.5pt; color: #333;
}

/* ---------- Phones (preview only) ---------- */
@media (max-width: 640px) {
  .sheet { margin: 0; padding: 14px 12px; box-shadow: none; }
  .kpis { flex-direction: column; }
  .kpi { border-right: none; border-bottom: 1px solid #111; }
  .kpi:last-child { border-bottom: none; }
  .screen-bar { padding: 8px 12px; }
  .screen-bar .actions { width: 100%; }
  .screen-bar .actions > * { flex: 1; justify-content: center; }
}

/* ---------- Paper ---------- */
@page { size: A4 portrait; margin: 13mm 12mm 14mm; }
@media print {
  body { background: #fff; font-size: 10pt; }
  .screen-bar { display: none !important; }
  .sheet { max-width: none; width: auto; margin: 0; padding: 0; box-shadow: none; }
  .tbl-wrap { overflow: visible; }
  .kpis { flex-direction: row; }
  .kpi { border-right: 1px solid #111; border-bottom: none; }
  .kpi:last-child { border-right: none; }
  a { color: inherit; text-decoration: none; }
}
</style>
</head>
<body>

<div class="screen-bar">
  <span class="hint">Print preview — this is exactly what will be printed.</span>
  <div class="actions">
    <a href="<?php echo rp_h('report-' . $report . '.php?' . http_build_query(['period' => $period['period'], 'start' => $period['start'], 'end' => $period['end']])); ?>">← Back</a>
    <button type="button" class="primary" onclick="window.print()">Print</button>
  </div>
</div>

<main class="sheet">
  <header class="masthead">
    <div class="prop-name"><?php echo rp_h($property['name']); ?></div>
    <?php
    $metaBits = array_filter([$property['address'], $property['phone'], $property['email']], fn($v) => $v !== '');
    if ($metaBits): ?>
      <div class="prop-meta"><?php echo rp_h(implode('  ·  ', $metaBits)); ?></div>
    <?php endif; ?>
  </header>

  <h1 class="doc-title"><?php echo rp_h($reportTitle); ?></h1>

  <table class="meta">
    <tr><td>Period</td><td><strong><?php echo rp_h($period['label']); ?></strong><?php echo ($period['label'] !== $rangeNice) ? ' (' . rp_h($rangeNice) . ')' : ''; ?></td></tr>
    <tr><td>Printed</td><td><?php echo rp_h($generated); ?></td></tr>
    <?php if ($preparedBy !== ''): ?>
      <tr><td>Prepared by</td><td><?php echo rp_h($preparedBy); ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($periodNote !== ''): ?>
    <p class="period-note"><?php echo rp_h($periodNote); ?></p>
  <?php endif; ?>

  <?php
  switch ($report) {
      case 'financial':      rp_body_financial($pdo, $period);      break;
      case 'performance':    rp_body_performance($pdo, $period);    break;
      case 'guest-insights': rp_body_guest_insights($pdo, $period); break;
      case 'inventory':      rp_body_inventory($pdo, $period);      break;
  }
  ?>

  <footer class="doc-foot">
    <span><?php echo rp_h($property['name']); ?> · <?php echo rp_h($reportTitle); ?> · <?php echo rp_h($period['label']); ?></span>
    <span>Printed <?php echo rp_h($generated); ?></span>
  </footer>
</main>

<?php if ($autoPrint): ?>
<script>
/* Opened from a report's Print button: bring up the print dialog once the
   page has finished laying out. The preview stays open behind it so the
   admin can print again or go back. */
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
</script>
<?php endif; ?>
</body>
</html>