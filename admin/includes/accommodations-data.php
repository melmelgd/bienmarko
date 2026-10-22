<?php
/**
 * admin/includes/accommodations-data.php
 * -----------------------------------------------------------------------
 * Shared data-access layer for the Accommodations feature. Single source
 * of truth for its table schema, valid statuses, and small pure helpers
 * (amenities parsing, status counts) — same "shared include" principle
 * already used by includes/ui.php, includes/nav-items.php, and
 * includes/icons.php in this project.
 *
 * Used by BOTH admin/accommodations.php (page) and
 * admin/api/accommodations.php (API), so the two never drift apart.
 *
 * CHANGE: added `is_featured` TINYINT(1) column so admins can mark
 * rooms that appear in the landing page "Featured Rooms" section.
 * The column is added via ALTER TABLE IF NOT EXISTS the key pattern
 * so existing deployments pick it up without manual SQL.
 *
 * CHANGE: added `tour_filename` VARCHAR(255) column — one uploaded
 * self-contained 360° tour HTML file per room (e.g. a Pannellum export
 * like the sample the client provided). Same ALTER-if-missing pattern.
 *
 * CHANGE: added the `accommodation_photos` table — extra gallery photos
 * per room, on top of the single `image_filename` "cover" photo. Cover
 * stays on `accommodations.image_filename` so every existing page that
 * already reads that column (cards, guest-book, the booking flow, the
 * landing page) keeps working unchanged; the gallery is purely additive.
 *
 * CHANGE: `type` is no longer just a free-text "room type" — it's now the
 * accommodation CATEGORY (Room, Event Hall, Cottage, Swimming Pool, or a
 * custom "other" value), so the system can manage more than just rooms.
 * Added `room_number` VARCHAR(255) column, populated (and required) only
 * when the category is exactly "Room". Same ALTER-if-missing pattern as
 * is_featured / tour_filename above, so existing deployments pick it up
 * automatically.
 * -----------------------------------------------------------------------
 */

/** The only four statuses an accommodation can have. Order matters for
 *  the stat cards and the status <select> on each card. */
const ACC_STATUSES = ['available', 'booked', 'unavailable', 'maintenance'];

/**
 * The preset accommodation type/category options offered in the
 * Add/Edit Accommodation modal's "Accommodation Type" dropdown. Order
 * matters — the first entry is the default selected on "Add Accommodation".
 * Admins can also pick "Other (specify)" in the UI and type a custom value,
 * which is stored as-is and isn't part of this list.
 *
 * "Room" is the one category that carries a `room_number` — see
 * ACC_TYPE_REQUIRING_ROOM_NUMBER below, which both the page and the API
 * gate on so the two never drift apart.
 */
const ACC_ACCOMMODATION_TYPES = ['Room', 'Event Hall', 'Cottage', 'Swimming Pool'];

/** The single accommodation type that requires a room number. Kept as a
 *  named constant (rather than a bare 'Room' string scattered around)
 *  so the gating rule lives in exactly one place. */
const ACC_TYPE_REQUIRING_ROOM_NUMBER = 'Room';

const ACC_STATUS_LABELS = [
    'available'   => 'Available',
    'booked'      => 'Booked',
    'unavailable' => 'Unavailable',
    'maintenance' => 'Under Maintenance',
];

/** Physical folder + web-relative path (from admin/*.php) for uploaded photos. */
const ACC_UPLOAD_DIR      = __DIR__ . '/../assets/uploads/accommodations';
const ACC_UPLOAD_URL_BASE = 'assets/uploads/accommodations';

const ACC_MAX_IMAGE_BYTES    = 5 * 1024 * 1024; // 5 MB
const ACC_ALLOWED_IMAGE_EXT  = ['jpg', 'jpeg', 'png', 'webp'];

/** Extra gallery photos allowed per room, on top of the cover photo. */
const ACC_GALLERY_MAX_PHOTOS = 8;

/** Physical folder + web-relative path (from admin/*.php) for uploaded 360° tour files. */
const ACC_TOUR_UPLOAD_DIR      = __DIR__ . '/../assets/uploads/tours';
const ACC_TOUR_UPLOAD_URL_BASE = 'assets/uploads/tours';

const ACC_MAX_TOUR_BYTES   = 30 * 1024 * 1024; // 30 MB — self-contained tours embed panorama images as base64
const ACC_ALLOWED_TOUR_EXT = ['html', 'htm'];

/**
 * Creates the accommodations table if it doesn't exist yet. Safe to call
 * on every request — CREATE TABLE IF NOT EXISTS is a cheap no-op once the
 * table is there. Also adds the is_featured column to existing tables via
 * ALTER TABLE ... ADD COLUMN IF NOT EXISTS (MySQL 8.0+) with a silent
 * catch for older MySQL where IF NOT EXISTS isn't supported on ALTER —
 * in that case we check information_schema before attempting the ALTER.
 */
function acc_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = bienmarko_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `accommodations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `type` VARCHAR(100) NOT NULL DEFAULT '',
            `room_number` VARCHAR(20) NULL,
            `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('available','booked','unavailable','maintenance') NOT NULL DEFAULT 'available',
            `description` TEXT NULL,
            `amenities` TEXT NULL,
            `image_filename` VARCHAR(255) NULL,
            `is_featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_featured` (`is_featured`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // For existing tables that pre-date the is_featured / tour_filename
    // columns, add whichever is missing. We check information_schema first
    // so there's no error on databases that don't support
    // ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'is_featured'"
    );
    $exists->execute(['db' => $dbName]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `accommodations`
             ADD COLUMN `is_featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
             ADD KEY `idx_featured` (`is_featured`)"
        );
    }

    $tourExists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'tour_filename'"
    );
    $tourExists->execute(['db' => $dbName]);
    if ((int) $tourExists->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `accommodations`
             ADD COLUMN `tour_filename` VARCHAR(255) NULL AFTER `image_filename`"
        );
    }

    $roomNumberExists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'room_number'"
    );
    $roomNumberExists->execute(['db' => $dbName]);
    if ((int) $roomNumberExists->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `accommodations`
             ADD COLUMN `room_number` VARCHAR(20) NULL AFTER `type`"
        );
    }

    $done = true;
}

/** True only for the one accommodation type that carries a room number. */
function acc_type_requires_room_number(string $type): bool
{
    return $type === ACC_TYPE_REQUIRING_ROOM_NUMBER;
}

/**
 * Creates the accommodation_photos table if it doesn't exist yet — extra
 * gallery photos per room, beyond the single `image_filename` cover photo.
 * Safe to call on every request, same reasoning as acc_ensure_table().
 */
function acc_ensure_photos_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = bienmarko_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `accommodation_photos` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `accommodation_id` INT UNSIGNED NOT NULL,
            `filename`         VARCHAR(255) NOT NULL,
            `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_accommodation` (`accommodation_id`),
            CONSTRAINT `fk_acc_photos_accommodation` FOREIGN KEY (`accommodation_id`)
                REFERENCES `accommodations` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

/**
 * Locks a folder down against script execution + a blank index.html guard.
 * Shared by acc_ensure_upload_dir() and acc_ensure_tour_upload_dir() so the
 * lockdown logic lives in exactly one place.
 */
function acc_lockdown_upload_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // NOTE: a bare `php_flag` line makes Apache return HTTP 500 for EVERY file
    // in the folder on hosts that run PHP as CGI/FPM (e.g. InfinityFree), which
    // shows up as broken images. Wrapping it in <IfModule> keeps it harmless there.
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Uploaded files only - never execute scripts from this folder.\n"
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n"
            . "<FilesMatch \"\\.(php|phtml|php\\d|phar)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
        );
    }

    $indexGuard = $dir . '/index.html';
    if (!file_exists($indexGuard)) {
        @file_put_contents($indexGuard, '');
    }
}

/** Makes sure the photo upload folder exists and is locked down against script execution. */
function acc_ensure_upload_dir(): void
{
    acc_lockdown_upload_dir(ACC_UPLOAD_DIR);
}

/**
 * Makes sure the 360° tour upload folder exists and is locked down against
 * script execution. Tour files themselves are static self-contained HTML
 * (a Pannellum-style export) — they still render normally as HTML, this
 * only blocks anything with a server-executable extension from running.
 */
function acc_ensure_tour_upload_dir(): void
{
    acc_lockdown_upload_dir(ACC_TOUR_UPLOAD_DIR);
}

function acc_valid_status(string $status): bool
{
    return in_array($status, ACC_STATUSES, true);
}

function acc_status_label(string $status): string
{
    return ACC_STATUS_LABELS[$status] ?? ucfirst($status);
}

/** "Wi-Fi, Air Conditioning,  , Private Bathroom" -> ['Wi-Fi', 'Air Conditioning', 'Private Bathroom'] */
function acc_parse_amenities(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_values(array_filter($parts, static function ($v) {
        return $v !== '';
    }));
    $parts = array_slice($parts, 0, 20);

    return array_map(static function ($v) {
        return mb_substr($v, 0, 60);
    }, $parts);
}

function acc_encode_amenities(array $items): string
{
    return (string) json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
}

function acc_decode_amenities(?string $json): array
{
    if (!$json) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/** Total + per-status counts in a single query, for the stat cards. */
function acc_status_counts(): array
{
    $counts = [
        'total'       => 0,
        'available'   => 0,
        'booked'      => 0,
        'unavailable' => 0,
        'maintenance' => 0,
    ];

    $pdo = bienmarko_db();
    $rows = $pdo->query('SELECT `status`, COUNT(*) AS `c` FROM `accommodations` GROUP BY `status`')->fetchAll();

    foreach ($rows as $row) {
        $status = $row['status'];
        $count = (int) $row['c'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = $count;
        }
        $counts['total'] += $count;
    }

    return $counts;
}

/**
 * A few extra inline icons this feature needs that aren't in the shared
 * admin/includes/icons.php registry. Kept local here — same reasoning
 * admin/create-admin.php uses for its own page-scoped <style> block
 * ("matched here rather than editing that file") — rather than adding
 * to that shared file. Falls through to the shared icon() helper for
 * every other name, so page code can call acc_icon() uniformly.
 */
function acc_icon(string $name, string $class = 'icon'): string
{
    $extra = [
        'edit'   => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
        'trash'  => '<polyline points="4 7 20 7"></polyline><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path><path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'search' => '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.2" y2="16.2"></line>',
        'star'   => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
        'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"></path><circle cx="12" cy="13" r="4"></circle>',
        'globe'  => '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"></path>',
        'check'  => '<polyline points="20 6 9 17 4 12"></polyline>',
    ];

    if (isset($extra[$name])) {
        $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $extra[$name] . '</svg>';
    }

    return icon($name, $class);
}

/** All gallery photos for one accommodation, oldest-first by sort order. */
function acc_get_photos(PDO $pdo, int $accommodationId): array
{
    $stmt = $pdo->prepare(
        'SELECT `id`, `filename`, `sort_order`
         FROM `accommodation_photos`
         WHERE `accommodation_id` = :id
         ORDER BY `sort_order` ASC, `id` ASC'
    );
    $stmt->execute(['id' => $accommodationId]);
    return $stmt->fetchAll();
}

/** Deletes one uploaded 360° tour file from disk, if present. */
function acc_delete_tour_file(?string $filename): void
{
    if (!$filename) {
        return;
    }
    $path = ACC_TOUR_UPLOAD_DIR . '/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}
