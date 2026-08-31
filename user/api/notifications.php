<?php
/**
 * user/api/notifications.php
 * -----------------------------------------------------------------------
 * POST-only JSON endpoint for the signed-in guest's notification bell.
 *
 * Actions:
 *   mark_all_read — marks every unread guest_notifications row that
 *                   belongs to the signed-in guest as read
 *   mark_read     — marks a single notification as read (requires id;
 *                   scoped to the signed-in guest so no one can mark
 *                   another guest's notifications)
 *
 * Both require the 'user' role and a valid CSRF token. Mirrors
 * admin/api/notifications.php, with every query additionally scoped
 * to guest_id so one guest can never touch another guest's rows.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

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

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
if ($guestId < 1) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
    exit;
}

$pdo    = bienmarko_db();
$action = (string) ($input['action'] ?? '');

/* Additive migration — safe to run on every request. Mirrors
   user/includes/bootstrap.php so this endpoint still works even if
   called before bootstrap.php has had a chance to create the table. */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `guest_notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `guest_id`   INT UNSIGNED NOT NULL,
        `booking_id` INT UNSIGNED NULL DEFAULT NULL,
        `type`       VARCHAR(60)  NOT NULL DEFAULT 'payment',
        `message`    VARCHAR(400) NOT NULL,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_guest`   (`guest_id`),
        KEY `idx_booking` (`booking_id`),
        KEY `idx_read`    (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

switch ($action) {
    case 'mark_all_read':
        $stmt = $pdo->prepare(
            "UPDATE guest_notifications SET is_read = 1 WHERE guest_id = :gid AND is_read = 0"
        );
        $stmt->execute(['gid' => $guestId]);
        echo json_encode(['success' => true]);
        break;

    case 'mark_read':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid notification id.']);
            break;
        }
        $stmt = $pdo->prepare(
            "UPDATE guest_notifications SET is_read = 1 WHERE id = :id AND guest_id = :gid"
        );
        $stmt->execute(['id' => $id, 'gid' => $guestId]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
