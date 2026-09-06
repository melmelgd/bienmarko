<?php
/**
 * topbar.php
 * -----------------------------------------------------------------------
 * Renders the page header bar (title, breadcrumb, period pills, bell,
 * action button) and opens .app-main + <main class="content">. It knows
 * nothing about sidebar markup — it sits NEXT TO the sidebar, never
 * inside it — and nothing about what a page's body contains.
 *
 * Reads (all optional, with sane defaults):
 *   $page_title          - also used by head.php for the <title> tag
 *   $breadcrumb           - defaults to $page_title
 *   $page_action_label    - omit/leave null to hide the gold action button
 *   $show_period_toggle    - bool, Week/Month/Year pills (Dashboard only)
 *   $topbar_extra_right    - optional raw HTML string rendered at the end
 *                            of .topbar-right, after the bell/action button.
 *                            Left unset by every page except the Reports
 *                            & Analytics pages, which use it for their
 *                            functional Week/Month/Year/Custom/Export
 *                            controls — every other page's output is
 *                            unaffected.
 *
 * Notification bell — self-contained on purpose. Every admin page
 * requires this file, so the bell/dropdown is built and its data is
 * queried right here rather than in each page, which is what makes the
 * panel actually show up (and work) on Bookings, Payments, Settings,
 * etc. — not just on the Dashboard. Clicking a notification that has a
 * related booking opens Bookings (or Payments, for payment
 * notifications) pre-filtered to that booking's reference number.
 * Individual notifications — and all of them at once — can be deleted
 * by the admin via admin/api/notifications.php. The click/delete/open
 * behaviour itself lives in assets/js/script.js so it isn't re-declared
 * on every page render.
 *
 * NOTE: this file intentionally leaves <main class="content"> open.
 * The including page's own markup follows directly after, and
 * includes/foot.php closes both <main> and the wrappers opened here.
 * -----------------------------------------------------------------------
 */
$page_title = $page_title ?? 'Dashboard';
$breadcrumb = $breadcrumb ?? $page_title;
$page_action_label = $page_action_label ?? null;
$show_period_toggle = $show_period_toggle ?? false;
$topbar_extra_right = $topbar_extra_right ?? null;
require_once __DIR__ . '/icons.php';

/* ------------------------------------------------------------------
   Notification data
   -----------------------------------------------------------------
   Self-guarding, additive migration — safe to run on every request,
   same pattern used throughout the project (see admin/dashboard.php,
   admin/bookings.php, etc.). Ensures the table exists at all, and adds
   the `type` column that admin/api/bookings.php and
   admin/api/payments.php already write to (new_booking, booking_status,
   booking_updated, payment) so those inserts stop silently failing.
------------------------------------------------------------------ */
require_once __DIR__ . '/../../includes/db.php';
$__notif_pdo = bienmarko_db();

$__notif_pdo->exec(
    "CREATE TABLE IF NOT EXISTS `admin_notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `booking_id` INT UNSIGNED NOT NULL,
        `message`    VARCHAR(400) NOT NULL,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_booking` (`booking_id`),
        KEY `idx_read`    (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$__notif_pdo->exec(
    "ALTER TABLE `admin_notifications`
        ADD COLUMN IF NOT EXISTS `type` VARCHAR(40) NULL DEFAULT NULL AFTER `booking_id`"
);

/* Most recent 20, with the related booking's reference (if any) so a
   click can jump straight to it. LEFT JOIN — the booking may since
   have been deleted, in which case the notification just isn't
   clickable, same defensive pattern used for walk-in bookings
   elsewhere in the project. */
$__notif_rows = $__notif_pdo->query(
    "SELECT n.id, n.type, n.message, n.is_read, n.created_at, n.booking_id,
            b.reference AS booking_reference
     FROM admin_notifications n
     LEFT JOIN bookings b ON b.id = n.booking_id
     ORDER BY n.created_at DESC
     LIMIT 20"
)->fetchAll();

$__notif_unread = 0;
foreach ($__notif_rows as $__notif_row) {
    if (!$__notif_row['is_read']) {
        $__notif_unread++;
    }
}

if (!function_exists('bienmarko_notif_link')) {
    /**
     * Where a notification should take the admin when clicked. Payment
     * notifications open Payments filtered to that booking; every other
     * type (new booking, status change, edit) opens Bookings the same
     * way. Both pages already support ?search= against the booking
     * reference (see $filterSearch in bookings.php / payments.php), so
     * this just reuses that instead of inventing a new lookup route.
     * Returns null when there's nothing to link to yet.
     */
    function bienmarko_notif_link(array $n): ?string
    {
        if (empty($n['booking_reference'])) {
            return null;
        }
        $ref = rawurlencode($n['booking_reference']);
        return ($n['type'] === 'payment') ? "payments.php?search={$ref}" : "bookings.php?search={$ref}";
    }
}
?>
<div class="app-main">
  <header class="topbar">
    <div class="topbar-inner">
      <div class="topbar-left">
        <button type="button" class="topbar-mobile-toggle" aria-label="Open menu">
          <?php echo icon('menu', 'icon-lg'); ?>
        </button>
        <div class="topbar-titles">
          <h1 class="page-title"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
          <p class="breadcrumb">
            <a href="dashboard.php">Home</a>
            <span class="sep">/</span>
            <span class="current"><?php echo htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8'); ?></span>
          </p>
        </div>
      </div>

      <div class="topbar-right">
        <?php if ($show_period_toggle): ?>
          <div class="period-toggle">
            <button type="button" class="is-active">Week</button>
            <button type="button">Month</button>
            <button type="button">Year</button>
          </div>
        <?php endif; ?>

        <div class="notif-wrap" data-csrf-token="<?php echo htmlspecialchars(bienmarko_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <button
            type="button"
            class="icon-btn"
            id="notifBellBtn"
            aria-haspopup="true"
            aria-expanded="false"
            aria-label="<?php echo (int) $__notif_unread; ?> unread notification<?php echo $__notif_unread === 1 ? '' : 's'; ?>"
          >
            <?php echo icon('bell'); ?>
            <span class="notif-dot" <?php echo $__notif_unread === 0 ? 'style="display:none;"' : ''; ?>>
              <?php echo $__notif_unread > 99 ? '99+' : (int) $__notif_unread; ?>
            </span>
          </button>

          <div class="notif-dropdown" id="notifDropdown" role="menu" hidden>
            <div class="notif-dropdown-head">
              <span class="notif-dropdown-title">Notifications</span>
              <div class="notif-dropdown-actions">
                <?php if ($__notif_unread > 0): ?>
                  <button type="button" class="notif-action-link" id="notifMarkAllBtn">Mark all read</button>
                <?php endif; ?>
                <?php if (!empty($__notif_rows)): ?>
                  <button type="button" class="notif-action-link notif-action-link--danger" id="notifClearAllBtn">Clear all</button>
                <?php endif; ?>
              </div>
            </div>

            <div class="notif-dropdown-body" id="notifList">
              <?php if (empty($__notif_rows)): ?>
                <div class="empty-state" id="notifEmptyState">
                  <p class="empty-state-title">No notifications yet</p>
                  <p class="empty-state-message">New booking and payment activity will show up here.</p>
                </div>
              <?php else: ?>
                <?php foreach ($__notif_rows as $__notif_row): ?>
                  <?php
                    $__notif_href = bienmarko_notif_link($__notif_row);
                    $__notif_tag  = $__notif_href ? 'a' : 'div';
                    $__notif_time = htmlspecialchars(
                        date('M j, g:i A', strtotime($__notif_row['created_at'])),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                  ?>
                  <<?php echo $__notif_tag; ?>
                    class="notif-item<?php echo $__notif_row['is_read'] ? '' : ' is-unread'; ?>"
                    data-id="<?php echo (int) $__notif_row['id']; ?>"
                    <?php if ($__notif_href): ?>href="<?php echo htmlspecialchars($__notif_href, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                  >
                    <span class="notif-item-dot" aria-hidden="true"></span>
                    <span class="notif-item-body">
                      <span class="notif-item-msg"><?php echo htmlspecialchars($__notif_row['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <span class="notif-item-time"><?php echo $__notif_time; ?></span>
                    </span>
                    <button type="button" class="notif-item-delete" data-id="<?php echo (int) $__notif_row['id']; ?>" aria-label="Delete notification" title="Delete notification">
                      <?php echo icon('trash', 'icon-sm'); ?>
                    </button>
                  </<?php echo $__notif_tag; ?>>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($page_action_label): ?>
          <button type="button" class="btn btn-primary btn-action">
            <?php echo icon('plus', 'icon-sm'); ?>
            <span class="btn-label"><?php echo htmlspecialchars($page_action_label, ENT_QUOTES, 'UTF-8'); ?></span>
          </button>
        <?php endif; ?>

        <?php if ($topbar_extra_right): ?>
          <?php echo $topbar_extra_right; ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="content">
