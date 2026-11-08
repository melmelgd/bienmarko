<?php
/**
 * admin/api/live.php
 * -----------------------------------------------------------------------
 * GET-only JSON endpoint polled by assets/js/live-refresh.js.
 *
 *   ?page=bookings.php   page the browser is showing (see bm_live_admin_pages())
 *   &n=<sig>             notification signature the browser already shows
 *
 * Response:
 *   { ok, sig, notif_sig, bell? }
 *     sig        fingerprint of the data the page displays
 *     notif_sig  fingerprint of the bell contents
 *     bell       only present when notif_sig differs from ?n= — contains
 *                the re-rendered dropdown list/actions + unread count
 *
 * Read-only: it never writes to the database and releases the PHP session
 * lock immediately, so polling can never block another request.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/live.php';

// Release the session lock before doing any work.
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

$page = basename((string) ($_GET['page'] ?? ''));
if (!preg_match('/^[a-z0-9\-]+\.php$/i', $page)) {
    $page = '';
}
$knownNotif = (string) ($_GET['n'] ?? '');

try {
    $pdo  = bienmarko_db();
    $bell = bm_live_admin_bell($pdo, false);          // signature only (cheap)
    $out  = [
        'ok'        => true,
        'sig'       => bm_live_admin_page_sig($pdo, $page),
        'notif_sig' => $bell['sig'],
        'ts'        => time(),
    ];
    if ($bell['sig'] !== $knownNotif) {
        $out['bell'] = bm_live_admin_bell($pdo, true); // full re-render only on change
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('[admin-live] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Live update unavailable.']);
}
