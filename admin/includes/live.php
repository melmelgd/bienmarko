<?php
/**
 * admin/includes/live.php
 * -----------------------------------------------------------------------
 * Live-refresh helpers for the ADMIN portal.
 *
 * Used by:
 *   - api/live.php        (polled every few seconds by assets/js/live-refresh.js)
 *   - includes/foot.php   (stamps each page with the fingerprint it was
 *                          rendered with, so the first poll can tell
 *                          whether anything changed since the page loaded)
 *
 * How it works
 *   Every page is mapped to the "channels" (groups of tables) it displays.
 *   A channel's fingerprint is COUNT(*) + a CRC32 checksum of every
 *   column of every row, so it changes on any insert, update or delete —
 *   with no dependency on an updated_at column. When a page's combined
 *   fingerprint differs from the one it was rendered with, the browser
 *   knows the data on screen is stale.
 *
 * Adding a page: add one line to bm_live_admin_pages().
 * -----------------------------------------------------------------------
 */

if (!function_exists('bm_live_columns')) {

    /** Column names of $table (password columns excluded). Cached per request. */
    function bm_live_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $cols = [];
        try {
            $st = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                 ORDER BY ORDINAL_POSITION'
            );
            $st->execute(['t' => $table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                if (stripos((string) $c, 'password') !== false) {
                    continue; // never hash credentials
                }
                $cols[] = (string) $c;
            }
        } catch (Throwable $e) {
            $cols = [];
        }
        return $cache[$table] = $cols;
    }

    /**
     * "count:checksum" for a table. $where / $table are internal constants
     * only — never user input. Returns 'none' for a missing table and
     * 'err' on a query failure (so a broken channel never breaks the page).
     */
    function bm_live_table_fp(PDO $pdo, string $table, string $where = '1=1', array $params = [], ?array $cols = null): string
    {
        try {
            $cols = $cols ?? bm_live_columns($pdo, $table);
            if (!$cols) {
                return 'none';
            }
            $parts = [];
            foreach ($cols as $c) {
                $parts[] = "IFNULL(`" . str_replace('`', '``', $c) . "`,'')";
            }
            $sql = "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|'," . implode(',', $parts) . "))),0)
                    FROM `{$table}` WHERE {$where}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_NUM);
            return ((string) ($r[0] ?? 0)) . ':' . ((string) ($r[1] ?? 0));
        } catch (Throwable $e) {
            return 'err';
        }
    }
}

/** Channel => tables it covers. */
function bm_live_admin_channels(): array
{
    return [
        'bookings'       => ['bookings'],
        'payments'       => ['payments'],
        'feedback'       => ['feedback'],
        'expenses'       => ['expenses'],
        'inventory'      => ['inventory_items', 'inventory_logs'],
        'accommodations' => ['accommodations', 'accommodation_categories', 'accommodation_photos'],
        'guests'         => ['guests'],
        'settings'       => ['system_settings'],
        'backups'        => ['backups'],
        'admins'         => ['admins'],
    ];
}

/** Admin page (file name) => channels shown on it. Unknown pages refresh nothing. */
function bm_live_admin_pages(): array
{
    $reports = ['bookings', 'payments', 'expenses', 'inventory', 'accommodations', 'feedback', 'guests', 'settings'];
    return [
        'dashboard.php'          => ['bookings', 'payments', 'expenses', 'inventory', 'accommodations', 'feedback', 'guests', 'settings'],
        'bookings.php'           => ['bookings', 'payments', 'accommodations', 'guests', 'settings'],
        'payments.php'           => ['bookings', 'payments', 'accommodations', 'guests', 'settings'],
        'accommodations.php'     => ['accommodations', 'bookings', 'settings'],
        'inventory.php'          => ['inventory', 'expenses', 'settings'],
        'expenses.php'           => ['expenses', 'inventory', 'settings'],
        'feedback.php'           => ['feedback', 'guests', 'settings'],
        'report.php'             => $reports,
        'report-financial.php'   => $reports,
        'report-performance.php' => $reports,
        'report-guest-insights.php' => $reports,
        'report-inventory.php'   => $reports,
        'backup.php'             => ['backups', 'admins', 'settings'],
        'settings.php'           => ['settings', 'admins'],
        'help.php'               => ['settings'],
        'create-admin.php'       => ['admins'],
    ];
}

/** Combined fingerprint of everything $page displays. */
function bm_live_admin_page_sig(PDO $pdo, string $page): string
{
    $pages    = bm_live_admin_pages();
    $channels = bm_live_admin_channels();
    $wanted   = $pages[$page] ?? [];
    $parts    = [];
    foreach ($wanted as $name) {
        foreach (($channels[$name] ?? []) as $table) {
            $parts[] = $table . '=' . bm_live_table_fp($pdo, $table);
        }
    }
    return $parts ? md5(implode(';', $parts)) : 'static';
}

/** Same query the topbar bell uses (latest 20 + the booking reference for the link). */
function bm_live_admin_notif_rows(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT n.id, n.type, n.message, n.is_read, n.created_at, n.booking_id,
                    b.reference AS booking_reference
             FROM admin_notifications n
             LEFT JOIN bookings b ON b.id = n.booking_id
             ORDER BY n.created_at DESC
             LIMIT 20"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Bell snapshot. 'sig' covers id / read-state / message of each row, so
 * marking read, deleting or a new notification all change it. The two HTML
 * strings are identical to what includes/topbar.php renders.
 */
function bm_live_admin_bell(PDO $pdo, bool $withHtml = true): array
{
    $rows   = bm_live_admin_notif_rows($pdo);
    $unread = 0;
    $sigSrc = [];
    foreach ($rows as $r) {
        if (!$r['is_read']) {
            $unread++;
        }
        $sigSrc[] = [(int) $r['id'], (int) $r['is_read'], (string) $r['message']];
    }
    $out = ['sig' => md5(json_encode($sigSrc)), 'unread' => $unread];
    if (!$withHtml) {
        return $out;
    }

    require_once __DIR__ . '/icons.php';

    ob_start();
    if (!$rows) {
        ?>
        <div class="empty-state" id="notifEmptyState">
          <p class="empty-state-title">No notifications yet</p>
          <p class="empty-state-message">New booking and payment activity will show up here.</p>
        </div>
        <?php
    } else {
        foreach ($rows as $row) {
            $href = null;
            if (!empty($row['booking_reference'])) {
                $ref  = rawurlencode($row['booking_reference']);
                $href = ($row['type'] === 'payment') ? "payments.php?search={$ref}" : "bookings.php?search={$ref}";
            }
            $tag  = $href ? 'a' : 'div';
            $time = htmlspecialchars(date('M j, g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8');
            ?>
            <<?php echo $tag; ?>
              class="notif-item<?php echo $row['is_read'] ? '' : ' is-unread'; ?>"
              data-id="<?php echo (int) $row['id']; ?>"
              <?php if ($href): ?>href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
            >
              <span class="notif-item-dot" aria-hidden="true"></span>
              <span class="notif-item-body">
                <span class="notif-item-msg"><?php echo htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="notif-item-time"><?php echo $time; ?></span>
              </span>
              <button type="button" class="notif-item-delete" data-id="<?php echo (int) $row['id']; ?>" aria-label="Delete notification" title="Delete notification">
                <?php echo icon('trash', 'icon-sm'); ?>
              </button>
            </<?php echo $tag; ?>>
            <?php
        }
    }
    $out['list_html'] = ob_get_clean();

    ob_start();
    if ($unread > 0) {
        echo '<button type="button" class="notif-action-link" id="notifMarkAllBtn">Mark all read</button>';
    }
    if ($rows) {
        echo '<button type="button" class="notif-action-link notif-action-link--danger" id="notifClearAllBtn">Clear all</button>';
    }
    $out['actions_html'] = ob_get_clean();

    return $out;
}
