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
 * -----------------------------------------------------------------------
 */

/** The only four statuses an accommodation can have. Order matters for
 *  the stat cards and the status <select> on each card. */
const ACC_STATUSES = ['available', 'booked', 'unavailable', 'maintenance'];

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

    // For existing tables that pre-date the is_featured column, add it
    // if missing. We check information_schema first so there's no error
    // on databases that don't support ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
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

    $done = true;
}

/** Makes sure the upload folder exists and is locked down against script execution. */
function acc_ensure_upload_dir(): void
{
    if (!is_dir(ACC_UPLOAD_DIR)) {
        mkdir(ACC_UPLOAD_DIR, 0755, true);
    }

    $htaccess = ACC_UPLOAD_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents(
            $htaccess,
            "# Uploaded photos only — never execute scripts from this folder.\n"
            . "php_flag engine off\n"
            . "<FilesMatch \"\\.(php|phtml|php\\d|phar)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
        );
    }

    $indexGuard = ACC_UPLOAD_DIR . '/index.html';
    if (!file_exists($indexGuard)) {
        file_put_contents($indexGuard, '');
    }
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
    ];

    if (isset($extra[$name])) {
        $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $extra[$name] . '</svg>';
    }

    return icon($name, $class);
}
