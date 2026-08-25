<?php
/**
 * admin/report-guest-insights.php
 * -----------------------------------------------------------------------
 * Guest Insights: total/repeat guest counts and average rating for the
 * selected period, plus an all-time Top Returning Guests table. Ratings
 * come from the `feedback` table (created empty by reports-data.php
 * until the guest-facing feedback submission feature exists — see the
 * note on reports_ensure_feedback_table()), so they may show "No
 * ratings yet" on a fresh install.
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

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/topbar.php';
require __DIR__ . '/includes/reports-styles.php';
?>

<div class="stack">
  <?php echo render_reports_subheader(); ?>

  <div class="rpt-stat-grid">
    <div class="rpt-stat-card rpt-stat--blue">
      <div class="rpt-stat-card-top"><?php echo icon('users', 'icon-sm'); ?> Total Guests</div>
      <span class="rpt-stat-value"><?php echo (int) $data['totalGuests']; ?></span>
      <span class="rpt-stat-sub"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--green">
      <div class="rpt-stat-card-top"><?php echo icon('users', 'icon-sm'); ?> Repeat Guests</div>
      <span class="rpt-stat-value"><?php echo (int) $data['repeatGuests']; ?></span>
      <span class="rpt-stat-sub"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="rpt-stat-card rpt-stat--olive">
      <div class="rpt-stat-card-top"><?php echo icon('star', 'icon-sm'); ?> Avg Guest Rating</div>
      <span class="rpt-stat-value"><?php echo $data['avgRating'] !== null ? number_format($data['avgRating'], 1) : '—'; ?></span>
      <span class="rpt-stat-sub"><?php echo $data['avgRating'] !== null ? 'out of 5.0' : 'No ratings yet'; ?></span>
    </div>
  </div>

  <div class="panel panel-pad rpt-light-panel">
    <div class="panel-header">
      <h2 class="panel-title"><?php echo icon('star', 'icon-sm'); ?> Top Returning Guests</h2>
    </div>

    <?php if (empty($data['topGuests'])): ?>
      <?php render_empty_state('users', 'No repeat guests yet', 'Guests who book more than once will show up here.'); ?>
    <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Guest Name</th>
              <th>Total Visits</th>
              <th>Most Booked Accommodation</th>
              <th>Country</th>
              <th>Rating</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['topGuests'] as $g): ?>
              <tr>
                <td class="rpt-guest-name"><?php echo htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) $g['visits']; ?></td>
                <td><?php echo htmlspecialchars($g['most_booked'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($g['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php if ($g['rating'] !== null): ?>
                    <span class="rpt-guest-rating"><?php echo icon('star', 'icon-sm'); ?> <?php echo number_format($g['rating'], 1); ?></span>
                  <?php else: ?>
                    <span class="rpt-guest-rating rpt-guest-rating--none">—</span>
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
