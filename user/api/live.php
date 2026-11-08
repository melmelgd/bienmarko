<?php
/**
 * user/api/live.php
 * -----------------------------------------------------------------------
 * GET-only JSON endpoint polled by assets/js/live-refresh.js.
 *
 *   ?page=bookings.php   page the browser is showing (see bm_live_user_pages())
 *   &n=<sig>             notification signature the browser already shows
 *
 * Response: { ok, sig, notif_sig, bell? }  (bell only when notif_sig != ?n=)
 *
 * Only reads data belonging to the signed-in guest. The one write it can
 * make is the same idempotent reminder sync every page load already does
 * (bm_bn_sync), throttled to once a minute, so a "1 week / 1 night before
 * check-in" reminder shows up on a page that was left open. It sends no
 * email or SMS. The PHP session lock is released before any DB work.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/live.php';

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);

// Throttle for the reminder sync — the only session write, done before unlocking.
$runSync = false;
if ($guestId > 0 && (int) ($_SESSION['bm_live_sync_at'] ?? 0) < time() - 60) {
    $_SESSION['bm_live_sync_at'] = time();
    $runSync = true;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
if ($guestId < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in again.']);
    exit;
}

$page = basename((string) ($_GET['page'] ?? ''));
if (!preg_match('/^[a-z0-9\-]+\.php$/i', $page)) {
    $page = '';
}
$knownNotif = (string) ($_GET['n'] ?? '');

try {
    $pdo = bienmarko_db();

    if ($runSync) {
        try {
            require_once __DIR__ . '/../includes/booking-notifications.php';
            bm_bn_sync($pdo, $guestId);
        } catch (Throwable $e) {
            error_log('[user-live] reminder sync: ' . $e->getMessage());
        }
    }

    $bell = bm_live_user_bell($pdo, $guestId, false);   // signature only (cheap)
    $out  = [
        'ok'        => true,
        'sig'       => bm_live_user_page_sig($pdo, $guestId, $page),
        'notif_sig' => $bell['sig'],
        'ts'        => time(),
    ];
    if ($bell['sig'] !== $knownNotif) {
        $out['bell'] = bm_live_user_bell($pdo, $guestId, true);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[user-live] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Live update unavailable.']);
}
