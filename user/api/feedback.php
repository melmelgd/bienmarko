<?php
/**
 * user/api/feedback.php
 * -----------------------------------------------------------------------
 * POST-only JSON endpoint behind user/feedback.php. Session + 'user' role
 * + CSRF, checked in that order, before anything submitted is looked at.
 * Mirrors the structure of user/api/bookings.php and
 * admin/api/notifications.php.
 *
 *   action=submit — insert a new feedback entry for the signed-in guest.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function fb_fail(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function fb_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    fb_fail(405, 'Method not allowed.');
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
    fb_fail(403, 'Your session has expired. Please refresh the page and try again.');
}

/**
 * Makes sure the feedback table (and its moderation columns) exist —
 * safe on every request, a no-op once they're already there. Same
 * pattern as dashboard.php's bookings table guard.
 */
function fb_ensure_table(PDO $pdo): void
{
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
}

$pdo = bienmarko_db();
fb_ensure_table($pdo);

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
if ($guestId < 1) {
    fb_fail(401, 'Please sign in again.');
}

$action = (string) ($input['action'] ?? '');

if ($action !== 'submit') {
    fb_fail(400, 'Unknown action.');
}

/* ---- Validate ---- */
$rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
if ($rating === false || $rating < 1 || $rating > 5) {
    fb_fail(422, 'Please choose a rating from 1 to 5 stars.');
}

$allowedCategories = ['general', 'room', 'service', 'cleanliness', 'other'];
$category = (string) ($input['category'] ?? 'general');
if (!in_array($category, $allowedCategories, true)) {
    fb_fail(422, 'Please choose a valid category.');
}

$message = trim((string) ($input['message'] ?? ''));
if ($message === '') {
    fb_fail(422, 'Please share a few words about your stay.');
}
if (mb_strlen($message) > 2000) {
    fb_fail(422, 'Feedback is too long (max 2000 characters).');
}

$stmt = $pdo->prepare(
    'INSERT INTO feedback (guest_id, rating, category, message)
     VALUES (:guest_id, :rating, :category, :message)'
);
$stmt->execute([
    'guest_id' => $guestId,
    'rating'   => $rating,
    'category' => $category,
    'message'  => $message,
]);

fb_ok([
    'feedback' => [
        'id'       => (int) $pdo->lastInsertId(),
        'rating'   => $rating,
        'category' => $category,
        'message'  => $message,
    ],
]);
