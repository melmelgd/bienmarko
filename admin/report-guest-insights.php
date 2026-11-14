<?php
/**
 * admin/report-guest-insights.php
 * -----------------------------------------------------------------------
 * Guest Insights: total / repeat / new guest counts for the selected
 * period, plus an all-time Top Returning Guests table (visits, most
 * booked accommodation, country and each guest's highest
 * rating from the same `feedback` table admin/feedback.php manages).
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
$data   = reports_guest_insights_data($pdo, $period['start'], $period['end']);

$active_page         = 'report';
$page_title          = 'Guest Insights';
$breadcrumb          = 'Guest Insights';
$page_action_label   = null;
$show_period_toggle  = false;
$topbar_extra_right  = render_reports_topbar_controls('guest-insights', $period);


/* New guests = everyone active this period who is not a repeat guest */
$newGuests = max(0, (int) $data['totalGuests'] - (int) $data['repeatGuests']);

/* Highest rating each returning guest has given (all feedback, published or not) */
$highRatingStmt = $pdo->prepare('SELECT MAX(rating) FROM feedback WHERE guest_id = :gid');
foreach ($data['topGuests'] as &$tg) {
    $highRatingStmt->execute(['gid' => $tg['id']]);
    $hr = $highRatingStmt->fetchColumn();
    $tg['highest'] = ($hr !== false && $hr !== null) ? (float) $hr : null;
}
unset($tg);

function gi_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';
$periodLabel = htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8');
?>

<style>
/* ============ Guest insights — page-scoped styles ============ */
.gi-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.gi-kpi { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 24px; }
.gi-kpi-label { font-size: 16px; color: var(--text-secondary); }
.gi-kpi-value { display: block; font-family: var(--font-display); font-size: 38px; font-weight: 700; line-height: 1; margin: 20px 0 12px; }
.gi-kpi-value--blue  { color: var(--blue); }
.gi-kpi-value--green { color: var(--emerald); }
.gi-kpi-value--gold  { color: var(--gold-400); }
.gi-kpi-sub { font-size: 15px; color: var(--text-secondary); }

.gi-panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 24px; }
.gi-panel-title { font-family: var(--font-display); font-size: 25px; font-weight: 700; margin: 0 0 14px; }
.gi-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
.gi-table thead th { text-align: left; padding: 12px 12px; font-size: 14px; font-weight: 500; color: var(--text-secondary); border-bottom: 1px solid var(--border); }
.gi-table tbody td { padding: 16px 12px; font-size: 16px; color: var(--text); vertical-align: middle; }
.gi-table tbody tr { border-bottom: 1px solid var(--border); }
.gi-table tbody tr:last-child { border-bottom: none; }
.gi-guest { display: inline-flex; align-items: center; gap: 14px; font-weight: 700; font-size: 17px; }
.gi-avatar { width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--amber-bg); border: 1px solid var(--border); color: var(--gold-400); font-size: 13px; font-weight: 700; flex-shrink: 0; }
.gi-visits { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 28px; padding: 0 10px; border-radius: var(--r-full); background: var(--amber-bg); color: var(--gold-400); font-size: 14px; font-weight: 700; }
.gi-none   { color: var(--text-tertiary); }
.gi-rating { display: inline-flex; align-items: center; gap: 6px; color: var(--gold-400); font-weight: 600; }

@media (max-width: 900px) { .gi-kpi-grid { grid-template-columns: 1fr; } }
@media print { .gi-kpi, .gi-panel { background: #fff !important; border: 1px solid #ddd !important; break-inside: avoid; } }
</style>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="gi-kpi-grid">
    <div class="gi-kpi">
      <div class="gi-kpi-label">Total guests</div>
      <span class="gi-kpi-value gi-kpi-value--blue"><?php echo (int) $data['totalGuests']; ?></span>
      <div class="gi-kpi-sub"><?php echo $periodLabel; ?></div>
    </div>
    <div class="gi-kpi">
      <div class="gi-kpi-label">Repeat guests</div>
      <span class="gi-kpi-value gi-kpi-value--green"><?php echo (int) $data['repeatGuests']; ?></span>
      <div class="gi-kpi-sub"><?php echo $periodLabel; ?></div>
    </div>
    <div class="gi-kpi">
      <div class="gi-kpi-label">New guests</div>
      <span class="gi-kpi-value gi-kpi-value--gold"><?php echo $newGuests; ?></span>
      <div class="gi-kpi-sub"><?php echo $periodLabel; ?></div>
    </div>
  </div>

  <div class="gi-panel">
    <h2 class="gi-panel-title">Top returning guests</h2>

    <?php if (empty($data['topGuests'])): ?>
      <?php render_empty_state('users', 'No repeat guests yet', 'Guests who book more than once will show up here.'); ?>
    <?php else: ?>
      <div class="table-scroll">
        <table class="gi-table">
          <thead>
            <tr>
              <th>Guest name</th>
              <th>Total visits</th>
              <th>Most booked accommodation</th>
              <th>Country</th>
              <th>Highest rating</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['topGuests'] as $g): ?>
              <tr>
                <td>
                  <span class="gi-guest">
                    <span class="gi-avatar"><?php echo htmlspecialchars(gi_initials($g['name']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php echo htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td><span class="gi-visits"><?php echo (int) $g['visits']; ?></span></td>
                <td><?php echo htmlspecialchars($g['most_booked'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($g['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php if ($g['highest'] !== null): ?>
                    <span class="gi-rating"><?php echo icon('star', 'icon-sm'); ?> <?php echo number_format($g['highest'], 1); ?></span>
                  <?php else: ?>
                    <span class="gi-none">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="assets/js/reports.js" defer></script>

<?php require __DIR__ . '/includes/foot.php'; ?>