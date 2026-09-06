<?php
/**
 * admin/api/notifications.php
 * -----------------------------------------------------------------------
 * POST-only JSON endpoint for the admin notification panel
 * (includes/topbar.php + assets/js/script.js).
 *
 * Actions:
 *   mark_all_read  — marks every admin_notifications row as is_read=1
 *   mark_read      — marks a single notification as read (requires id)
 *   delete         — deletes a single notification (requires id)
 *   delete_all     — deletes every notification ("Clear all")
 *
 * All actions require the admin role and a valid CSRF token.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh.']);
    exit;
}

$pdo = bienmarko_db();

/* Self-guarding, additive migration — mirrors the same guard in
   includes/topbar.php so this endpoint works even if it's ever hit
   before a page load has run it (e.g. a stale tab). */
$pdo->exec(
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
$pdo->exec(
    "ALTER TABLE `admin_notifications`
        ADD COLUMN IF NOT EXISTS `type` VARCHAR(40) NULL DEFAULT NULL AFTER `booking_id`"
);

$action = (string) ($input['action'] ?? '');

switch ($action) {
    case 'mark_all_read':
        $pdo->exec("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        echo json_encode(['success' => true]);
        break;

    case 'mark_read':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid notification id.']);
            break;
        }
        $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = :id");
        $stmt->execute(['id' => $id]);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid notification id.']);
            break;
        }
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_all':
        $pdo->exec("DELETE FROM admin_notifications");
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
