<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'BienMarko');
define('SITE_TAGLINE', "Bien Marko's Pension House");


$pdo = bienmarko_db();
$pdo->exec("ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `avatar_filename` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");

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
