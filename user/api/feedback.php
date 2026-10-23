<?php

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../includes/booking-stay.php';

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

/** How long after sending a guest may still edit their feedback (24 hours). */
const FB_EDIT_WINDOW_SECONDS = 86400;

/**
 * When true, an edited review goes back to the admin's moderation queue
 * (unpublished + unread) so a guest cannot swap an approved review for
 * different text that was never reviewed. Set to false to keep an
 * approved review published after it is edited.
 */
const FB_EDIT_REQUIRES_REMODERATION = true;

/**
 * Validates rating / category / message exactly like a new submission.
 * Exits with a 422 JSON error on the first problem.
 *
 * @return array{0:int,1:string,2:string} [rating, category, message]
 */
function fb_read_fields(array $input): array
{
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

    return [(int) $rating, $category, $message];
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
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL AFTER `guest_id`");
    $pdo->exec("ALTER TABLE `feedback` ADD UNIQUE INDEX IF NOT EXISTS `uq_feedback_booking` (`booking_id`)");
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_id` INT UNSIGNED NULL DEFAULT NULL AFTER `booking_id`");
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_type` VARCHAR(100) NULL DEFAULT NULL AFTER `accommodation_id`");
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `accommodation_name` VARCHAR(150) NULL DEFAULT NULL AFTER `accommodation_type`");
    /* Set when the guest edits their feedback (NULL = never edited). */
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL DEFAULT NULL AFTER `created_at`");
}

$pdo = bienmarko_db();
fb_ensure_table($pdo);

$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);
if ($guestId < 1) {
    fb_fail(401, 'Please sign in again.');
}

$action = (string) ($input['action'] ?? '');

/* ------------------------------------------------------------------
   Edit an already-sent feedback — only within 24 hours of sending it.
   The age is measured in SQL (NOW() vs created_at) so it never depends
   on PHP's timezone, and the row is locked while it is checked + saved.
------------------------------------------------------------------ */
if ($action === 'update') {
    $feedbackId = filter_var($input['feedback_id'] ?? null, FILTER_VALIDATE_INT);
    if ($feedbackId === false || $feedbackId < 1) {
        fb_fail(422, 'We could not tell which feedback you want to edit.');
    }

    [$rating, $category, $message] = fb_read_fields($input);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
             FROM feedback
             WHERE id = :id AND guest_id = :gid
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $feedbackId, 'gid' => $guestId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            fb_fail(404, 'We could not find that feedback on your account.');
        }

        $ageSeconds = max(0, (int) $row['age_seconds']);
        if ($ageSeconds >= FB_EDIT_WINDOW_SECONDS) {
            $pdo->rollBack();
            fb_fail(403, 'You can only edit feedback within 24 hours of sending it, and that time has passed.');
        }

        $set = 'rating = :rating, category = :category, message = :message, updated_at = NOW()';
        if (FB_EDIT_REQUIRES_REMODERATION) {
            $set .= ', is_published = 0, is_read = 0';
        }

        $stmt = $pdo->prepare("UPDATE feedback SET {$set} WHERE id = :id AND guest_id = :gid");
        $stmt->execute([
            'rating'   => $rating,
            'category' => $category,
            'message'  => $message,
            'id'       => $feedbackId,
            'gid'      => $guestId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[feedback-edit] ' . $e->getMessage());
        fb_fail(500, 'Could not save your changes. Please try again.');
    }

    fb_ok([
        'feedback' => [
            'id'           => (int) $feedbackId,
            'rating'       => $rating,
            'category'     => $category,
            'message'      => $message,
            'edited'       => true,
            'seconds_left' => max(0, FB_EDIT_WINDOW_SECONDS - $ageSeconds),
        ],
    ]);
}

if ($action !== 'submit') {
    fb_fail(400, 'Unknown action.');
}

/* ---- Only guests with a COMPLETED booking may leave feedback ---- */
$bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT);
if ($bookingId === false || $bookingId < 1) {
    fb_fail(422, 'Please choose the stay you want to review.');
}

try {
    $stmt = $pdo->prepare(
        'SELECT b.id, b.status, ' . bk_stay_select($pdo) . ', b.accommodation_id,
                a.name AS accommodation_name, a.type AS accommodation_type
         FROM bookings b
         LEFT JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.id = :id AND b.guest_id = :gid LIMIT 1'
    );
    $stmt->execute(['id' => $bookingId, 'gid' => $guestId]);
    $booking = $stmt->fetch();
} catch (Throwable $e) {
    $booking = false;
}

if (!$booking) {
    fb_fail(403, 'We could not find that booking on your account.');
}
if (bk_stage($booking) !== 'completed') {
    fb_fail(403, 'You can leave feedback once your stay is completed.');
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE booking_id = :bid');
$stmt->execute(['bid' => $bookingId]);
if ((int) $stmt->fetchColumn() > 0) {
    fb_fail(409, 'You have already sent feedback for this stay.');
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

try {
    $stmt = $pdo->prepare(
        'INSERT INTO feedback (guest_id, booking_id, accommodation_id, accommodation_type, accommodation_name,
                               rating, category, message)
         VALUES (:guest_id, :booking_id, :accommodation_id, :accommodation_type, :accommodation_name,
                 :rating, :category, :message)'
    );
    $stmt->execute([
        'guest_id'           => $guestId,
        'booking_id'         => $bookingId,
        'accommodation_id'   => $booking['accommodation_id'] ?: null,
        'accommodation_type' => $booking['accommodation_type'] !== null ? mb_substr((string) $booking['accommodation_type'], 0, 100) : null,
        'accommodation_name' => $booking['accommodation_name'] !== null ? mb_substr((string) $booking['accommodation_name'], 0, 150) : null,
        'rating'     => $rating,
        'category'   => $category,
        'message'    => $message,
    ]);
} catch (PDOException $e) {
    // Unique index on booking_id: a double-click / second tab already saved one.
    if ($e->getCode() === '23000') {
        fb_fail(409, 'You have already sent feedback for this stay.');
    }
    throw $e;
}

// The reminder has done its job — mark its bell notification as read.
try {
    $pdo->prepare(
        "UPDATE guest_notifications SET is_read = 1
         WHERE guest_id = :gid AND booking_id = :bid AND type = 'feedback_reminder'"
    )->execute(['gid' => $guestId, 'bid' => $bookingId]);
} catch (Throwable $e) {
    // notifications are optional; never fail the submission over them
}

fb_ok([
    'feedback' => [
        'id'       => (int) $pdo->lastInsertId(),
        'rating'   => $rating,
        'category' => $category,
        'message'  => $message,
    ],
]);