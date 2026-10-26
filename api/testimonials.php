<?php
/**
 * api/testimonials.php
 * -----------------------------------------------------------------------
 * Public, read-only JSON endpoint for the landing page's "What Our
 * Guests Say" section. No session or CSRF — same as the other public
 * landing-page endpoints (api/accommodations.php, api/featured-rooms.php).
 *
 * Returns only feedback an admin has explicitly published from
 * admin/feedback.php, and never exposes a guest's email, phone, or
 * full last name — first name plus last initial only (e.g. "Mariel D."),
 * matching how testimonials have always been presented on this page.
 *
 * Each testimonial also carries the date the guest submitted the feedback
 * (feedback.created_at — NOT the date an admin published it):
 *   date        ISO date, e.g. "2026-10-26"
 *   date_label  display text, e.g. "Oct 26, 2026"
 * and `context` now reads "<Category> · <date>" (e.g. "Room & Amenities ·
 * Oct 26, 2026"), so the date shows up on the page wherever the category
 * line is already displayed, with no change needed in script.js.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = bienmarko_db();

/* Make sure the feedback table (and its moderation columns) exist —
   safe no-op once already there. Mirrors the same guard duplicated in
   admin/feedback.php and user/feedback.php. */
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

$stmt = $pdo->query(
    "SELECT f.rating, f.category, f.message, f.created_at,
            g.first_name, g.last_name
     FROM feedback f
     LEFT JOIN guests g ON g.id = f.guest_id
     WHERE f.is_published = 1
     ORDER BY f.created_at DESC
     LIMIT 12"
);
$rows = $stmt->fetchAll();

$categoryLabels = [
    'general'     => 'General',
    'room'        => 'Room & Amenities',
    'service'     => 'Staff & Service',
    'cleanliness' => 'Cleanliness',
    'other'       => 'Other',
];

$testimonials = [];
foreach ($rows as $row) {
    $firstName   = trim((string) $row['first_name']);
    $lastInitial = !empty($row['last_name']) ? strtoupper(substr($row['last_name'], 0, 1)) . '.' : '';
    $displayName = trim($firstName . ' ' . $lastInitial);
    if ($displayName === '') {
        $displayName = 'Verified Guest';
    }

    /* The day the guest gave the feedback */
    $ts        = strtotime((string) $row['created_at']);
    $dateIso   = $ts ? date('Y-m-d', $ts) : '';
    $dateLabel = $ts ? date('M j, Y', $ts) : '';
    $category  = $categoryLabels[$row['category']] ?? 'General';

    $testimonials[] = [
        'rating'     => (int) $row['rating'],
        'message'    => $row['message'],
        'name'       => $displayName,
        'context'    => $dateLabel !== '' ? $category . ' · ' . $dateLabel : $category,
        'date'       => $dateIso,
        'date_label' => $dateLabel,
    ];
}

echo json_encode(['success' => true, 'testimonials' => $testimonials]);