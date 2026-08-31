<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'BienMarko');
define('SITE_TAGLINE', "Bien Marko's Pension House");

/* ------------------------------------------------------------------
   Additive migration: guests.avatar_filename. Safe to run on every
   request (same pattern admin/bookings.php uses for its own columns)
   so every page — not just profile.php — can read the column right
   away, on both fresh installs and existing deployments.
------------------------------------------------------------------ */
$pdo = bienmarko_db();
$pdo->exec("ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `avatar_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");

/* ------------------------------------------------------------------
   Load the signed-in guest's display info fresh from the database on
   every page, so header.php's name/avatar always reflect the latest
   saved profile without every page having to fetch this itself.
------------------------------------------------------------------ */
$currentUser = [
    'name'   => null, // e.g. 'Shayra Walters'
    'email'  => null,
    'avatar' => null, // relative URL, or null to fall back to initials
];

if (!empty($_SESSION['auth_user_id'])) {
    $stmt = $pdo->prepare(
        'SELECT first_name, last_name, email, avatar_filename
         FROM guests WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['auth_user_id']]);
    $row = $stmt->fetch();

    if ($row) {
        $currentUser['name']  = trim($row['first_name'] . ' ' . $row['last_name']);
        $currentUser['email'] = $row['email'];
        if (!empty($row['avatar_filename'])) {
            $currentUser['avatar'] = '../admin/assets/uploads/avatars/' . rawurlencode($row['avatar_filename']);
        }
    }
}

require_once __DIR__ . '/nav-items.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/ui.php';

/* ------------------------------------------------------------------
   Additive migration: guest_notifications table. Payment updates are
   pushed here by admin/api/payments.php whenever an admin records a
   payment against one of this guest's bookings — this is how those
   updates reach the guest's own account. Safe to run on every
   request (same self-guarding pattern as the avatar_filename column
   above).
------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------
   Load this guest's latest notifications for the topbar bell.
   $notifUnreadCount drives the badge count; header.php marks unread
   rows with an .is-unread class. Both stay empty for guest (no-
   account) bookings — there's no session to key off of.
------------------------------------------------------------------ */
$notifications    = [];
$notifUnreadCount = 0;

if (!empty($_SESSION['auth_user_id'])) {
    $notifStmt = $pdo->prepare(
        "SELECT id, message, is_read, created_at
         FROM guest_notifications
         WHERE guest_id = :gid
         ORDER BY created_at DESC
         LIMIT 8"
    );
    $notifStmt->execute(['gid' => (int) $_SESSION['auth_user_id']]);
    $notifRows = $notifStmt->fetchAll();

    foreach ($notifRows as $n) {
        $notifications[] = [
            'id'      => (int) $n['id'],
            'message' => $n['message'],
            'is_read' => (bool) $n['is_read'],
            'time'    => bm_relative_time($n['created_at']),
        ];
    }
    $notifUnreadCount = count(array_filter($notifRows, fn($n) => !(bool) $n['is_read']));
}
