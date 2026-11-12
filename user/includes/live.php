<?php
/**
 * user/includes/live.php
 * -----------------------------------------------------------------------
 * Live-refresh helpers for the USER portal (registered guests).
 *
 * Used by:
 *   - api/live.php        (polled every few seconds by assets/js/live-refresh.js)
 *   - includes/foot.php   (stamps each page with the fingerprint it was
 *                          rendered with)
 *
 * Every fingerprint is scoped to the signed-in guest ($guestId), so one
 * guest's activity never refreshes another guest's dashboard. Only the
 * accommodation list and site settings are shared, because every guest sees
 * them. Fingerprint = COUNT(*) + CRC32 checksum of every column of every
 * matching row (password columns excluded).
 *
 * Adding a page: add one line to bm_live_user_pages().
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

/**
 * Channel => list of [table, where, params]. $g is the guest id.
 * Walk-in bookings have guest_id NULL and are intentionally not matched.
 */
function bm_live_user_channels(int $g): array
{
    return [
        'my_bookings' => [['bookings', 'guest_id = :g', ['g' => $g]]],
        'my_payments' => [['payments', 'booking_id IN (SELECT id FROM bookings WHERE guest_id = :g)', ['g' => $g]]],
        'my_feedback' => [['feedback', 'guest_id = :g', ['g' => $g]]],
        'my_profile'  => [['guests', 'id = :g', ['g' => $g]]],
        'rooms'       => [['accommodations', '1=1', []]],
        'settings'    => [['system_settings', '1=1', []]],
    ];
}

/** User page (file name) => channels shown on it. Unknown pages refresh nothing. */
function bm_live_user_pages(): array
{
    return [
        'dashboard.php'  => ['my_bookings', 'my_payments', 'my_feedback', 'my_profile', 'rooms', 'settings'],
        'bookings.php'   => ['my_bookings', 'my_payments', 'my_profile', 'settings'],
        'receipts.php'   => ['my_bookings', 'my_payments', 'my_profile', 'settings'],
        'feedback.php'   => ['my_bookings', 'my_feedback', 'my_profile', 'settings'],
        // The profile form saves over AJAX, so its own save must not trigger a reload.
        'profile.php'    => ['settings'],
        'user-guide.php' => ['settings'],
    ];
}

/** Combined fingerprint of everything $page displays for this guest. */
function bm_live_user_page_sig(PDO $pdo, int $guestId, string $page): string
{
    $pages    = bm_live_user_pages();
    $channels = bm_live_user_channels($guestId);
    $parts    = [];
    foreach (($pages[$page] ?? []) as $name) {
        foreach (($channels[$name] ?? []) as $spec) {
            [$table, $where, $params] = $spec;
            $parts[] = $name . '.' . $table . '=' . bm_live_table_fp($pdo, $table, $where, $params);
        }
    }
    return $parts ? md5(implode(';', $parts)) : 'static';
}

/**
 * Bell snapshot — same query and link rules as user/includes/bootstrap.php
 * (latest 8; unread counted among those 8). 'items' is only included when
 * $withItems is true. Needs bm_relative_time() from includes/ui.php.
 */
function bm_live_user_bell(PDO $pdo, int $guestId, bool $withItems = true): array
{
    $rows = [];
    try {
        $st = $pdo->prepare(
            "SELECT id, booking_id, type, message, is_read, created_at
             FROM guest_notifications
             WHERE guest_id = :gid
             ORDER BY created_at DESC
             LIMIT 8"
        );
        $st->execute(['gid' => $guestId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $unread = 0;
    $sigSrc = [];
    foreach ($rows as $r) {
        if (!(int) $r['is_read']) {
            $unread++;
        }
        $sigSrc[] = [(int) $r['id'], (int) $r['is_read'], (string) $r['message']];
    }
    $out = ['sig' => md5(json_encode($sigSrc)), 'unread' => $unread];
    if (!$withItems) {
        return $out;
    }

    $bookingTypes = ['booking_confirmed', 'booking_modified', 'booking_cancelled', 'booking_reminder', 'booking_reminder_week', 'booking_reminder_night', 'booking_reminder_today'];
    $items = [];
    foreach ($rows as $n) {
        if ($n['type'] === 'feedback_reminder' && $n['booking_id']) {
            $href = 'feedback.php?booking=' . (int) $n['booking_id'];
        } elseif (in_array($n['type'], $bookingTypes, true)) {
            $href = 'bookings.php';
        } else {
            $href = null;
        }
        $items[] = [
            'id'      => (int) $n['id'],
            'href'    => $href,
            'message' => (string) $n['message'],
            'is_read' => (bool) $n['is_read'],
            'time'    => function_exists('bm_relative_time') ? bm_relative_time((string) $n['created_at']) : '',
        ];
    }
    $out['items'] = $items;
    return $out;
}
