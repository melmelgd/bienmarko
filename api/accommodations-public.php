<?php
/**
 * api/accommodations-public.php
 * -----------------------------------------------------------------------
 * Public (no auth required) JSON endpoint.
 * Returns every accommodation whose status is 'available', ordered by
 * name. Used by the landing-page booking strip to populate the
 * Accommodation <select> with exactly what the admin has set up —
 * no hard-coded room names in the HTML.
 *
 * Output shape (200 OK):
 * {
 *   "success": true,
 *   "rooms": [
 *     { "id": 1, "name": "Barkada Room", "capacity": 4, "price": "2000.00", "status": "available",
 *       "image_url": "admin/assets/uploads/accommodations/xxx.jpg", "tour_url": null },
 *     ...
 *   ]
 * }
 *
 * On any error returns { "success": false, "rooms": [] } so the
 * landing page can fall back gracefully (keep the strip hidden / static).
 *
 * CORS: same-origin only — no Access-Control-Allow-Origin header.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
// Short cache — room list doesn't change often, 30 s is fine.
header('Cache-Control: public, max-age=30');

function pub_fail(): never
{
    echo json_encode(['success' => false, 'rooms' => []]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    pub_fail();
}

try {
    $pdo = bienmarko_db();

    // Ensure table exists (same defensive pattern as featured-rooms.php)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `accommodations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `type` VARCHAR(100) NOT NULL DEFAULT '',
            `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('available','booked','unavailable','maintenance') NOT NULL DEFAULT 'available',
            `description` TEXT NULL,
            `amenities` TEXT NULL,
            `image_filename` VARCHAR(255) NULL,
            `is_featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `tour_filename` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Defensive: older deployments may already have this table without
    // `tour_filename` (added after this endpoint first shipped). Add it
    // if missing rather than letting the SELECT below fail outright.
    $tourColExists = true;
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $colCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'tour_filename'"
        );
        $colCheck->execute(['db' => $dbName]);
        $tourColExists = ((int) $colCheck->fetchColumn()) > 0;
        if (!$tourColExists) {
            $pdo->exec("ALTER TABLE `accommodations` ADD COLUMN `tour_filename` VARCHAR(255) NULL AFTER `image_filename`");
            $tourColExists = true;
        }
    } catch (Throwable $e) {
        $tourColExists = false;
    }

    // Return all rooms (any status) so the user can see what exists;
    // availability conflict detection happens in book.php.
    // We expose id, name, capacity, price, status, image, and tour info —
    // enough for the booking strip select, book.php pre-selection, and
    // an optional 360° "View Tour" affordance on the landing page.
    $stmt = $pdo->query(
        "SELECT
            `id`,
            `name`,
            `capacity`,
            `price`,
            `status`,
            `image_filename`" . ($tourColExists ? ", `tour_filename`" : "") . "
         FROM `accommodations`
         ORDER BY `name` ASC"
    );

    $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rooms = [];

    foreach ($rows as $row) {
        $imageUrl = $row['image_filename']
            ? 'admin/assets/uploads/accommodations/' . rawurlencode($row['image_filename'])
            : null;
        $tourFilename = $tourColExists ? ($row['tour_filename'] ?? null) : null;
        $tourUrl = $tourFilename
            ? 'admin/assets/uploads/tours/' . rawurlencode($tourFilename)
            : null;

        $rooms[] = [
            'id'        => (int) $row['id'],
            'name'      => $row['name'],
            'capacity'  => (int) $row['capacity'],
            'price'     => $row['price'],   // string, JS formats it
            'status'    => $row['status'],
            'image_url' => $imageUrl,
            'tour_url'  => $tourUrl,
        ];
    }

    echo json_encode(['success' => true, 'rooms' => $rooms], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    pub_fail();
}
