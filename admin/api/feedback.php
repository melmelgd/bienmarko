<?php
/**
 * admin/api/feedback.php
 * -----------------------------------------------------------------------
 * POST-only JSON endpoint behind admin/feedback.php. Session + 'admin'
 * role + CSRF, checked in that order. Mirrors admin/api/notifications.php.
 *
 * Actions:
 *   mark_read       — marks a single feedback row as read (requires id)
 *   mark_all_read   — marks every unread feedback row as read
 *   toggle_publish  — shows/hides a feedback row on the public landing
 *                     page's testimonials section (requires id, publish)
 *   delete          — permanently removes a feedback row (requires id)
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('admin');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please refresh the page.']);
    exit;
}

$pdo = bienmarko_db();

// Make sure the table (and its moderation columns) exist — a no-op
// once they're already there.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `feedback` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `guest_id`     INT UNSIGNED NOT NULL,
        `rating`       TINYINT UNSIGNED NOT NULL,
        `category`     ENUM('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
        `message`      TEXT NOT NULL,
        `is_read`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_guest`     (`guest_id`),
        KEY `idx_created`   (`created_at`),
        KEY `idx_read`      (`is_read`),
        KEY `idx_published` (`is_published`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`");

$action = (string) ($input['action'] ?? '');

switch ($action) {
    case 'mark_all_read':
        $pdo->exec('UPDATE feedback SET is_read = 1 WHERE is_read = 0');
        echo json_encode(['success' => true]);
        break;

    case 'mark_read':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid feedback id.']);
            break;
        }
        $stmt = $pdo->prepare('UPDATE feedback SET is_read = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['success' => true]);
        break;

    case 'toggle_publish':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid feedback id.']);
            break;
        }
        $publish = !empty($input['publish']);
        $stmt = $pdo->prepare('UPDATE feedback SET is_published = :pub WHERE id = :id');
        $stmt->execute(['pub' => $publish ? 1 : 0, 'id' => $id]);
        echo json_encode(['success' => true, 'is_published' => $publish]);
        break;

    case 'delete':
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid feedback id.']);
            break;
        }
        $stmt = $pdo->prepare('DELETE FROM feedback WHERE id = :id');
        $stmt->execute(['id' => $id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
