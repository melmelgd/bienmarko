<?php
/**
 * admin/api/notifications.php
 * -----------------------------------------------------------------------
 * POST-only JSON endpoint for the admin notification panel.
 *
 * Actions:
 *   mark_all_read  — marks every admin_notifications row as is_read=1
 *   mark_read      — marks a single notification (requires id)
 *
 * Both require the admin role and a valid CSRF token.
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

$pdo    = bienmarko_db();
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

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
