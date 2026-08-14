<?php
/**
 * admin/includes/accommodations-data.php
 * -----------------------------------------------------------------------
 * Shared data-access layer for the Accommodations feature. Single source
 * of truth for its table schema, valid statuses, and small pure helpers.
 *
 * Used by BOTH admin/accommodations.php (page) and
 * admin/api/accommodations.php (API).
 * -----------------------------------------------------------------------
 */

/** The only four statuses an accommodation can have. */
const ACC_STATUSES = ['available', 'booked', 'unavailable', 'maintenance'];

const ACC_STATUS_LABELS = [
    'available'   => 'Available',
    'booked'      => 'Booked',
    'unavailable' => 'Unavailable',
    'maintenance' => 'Under Maintenance',
];

/** Physical folder + web-relative path for uploaded photos. */
const ACC_UPLOAD_DIR      = __DIR__ . '/../assets/uploads/accommodations';
const ACC_UPLOAD_URL_BASE = 'assets/uploads/accommodations';

const ACC_MAX_IMAGE_BYTES    = 5 * 1024 * 1024; // 5 MB
const ACC_ALLOWED_IMAGE_EXT  = ['jpg', 'jpeg', 'png', 'webp'];

/**
 * Creates the accommodations table if it doesn't exist yet.
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
            `category_id` INT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // If the table already existed without category_id, add the column.
    try {
        $pdo->exec("ALTER TABLE `accommodations` ADD COLUMN `category_id` INT UNSIGNED NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE `accommodations` ADD KEY `idx_category` (`category_id`)");
    } catch (PDOException $e) {
        // Column already exists — ignore duplicate column error (1060).
    }

    $done = true;
}

/**
 * Creates the accommodation_categories table if it doesn't exist yet.
 */
function acc_ensure_categories_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = bienmarko_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `accommodation_categories` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cat_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

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
 * Returns all categories ordered by sort_order then name.
 */
function acc_get_categories(): array
{
    $pdo = bienmarko_db();
    return $pdo->query(
        'SELECT `id`, `name`, `description`, `sort_order`
         FROM `accommodation_categories`
         ORDER BY `sort_order` ASC, `name` ASC'
    )->fetchAll();
}

/**
 * Returns all accommodations keyed by category_id (null = uncategorised).
 * Each item includes room count per category.
 */
function acc_get_accommodations_by_category(): array
{
    $pdo = bienmarko_db();
    $rows = $pdo->query(
        'SELECT `id`, `name`, `type`, `capacity`, `price`, `status`,
                `description`, `amenities`, `image_filename`, `category_id`
         FROM `accommodations`
         ORDER BY `category_id` ASC, `name` ASC'
    )->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $key = $row['category_id'] ?? 'null';
        $map[$key][] = $row;
    }

    return $map;
}

/**
 * Extra inline icons this feature needs that aren't in the shared icons.php.
 * Falls through to the shared icon() helper for everything else.
 */
function acc_icon(string $name, string $class = 'icon'): string
{
    $extra = [
        'edit'     => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
        'trash'    => '<polyline points="4 7 20 7"></polyline><path d="M6 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path><path d="M19 7l-1 13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'search'   => '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.2" y2="16.2"></line>',
        'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>',
        'tag'      => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line>',
    ];

    if (isset($extra[$name])) {
        $safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<svg class="' . $safeClass . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . $extra[$name] . '</svg>';
    }

    return icon($name, $class);
}
