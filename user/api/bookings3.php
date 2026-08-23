<?php
/**
 * user/api/bookings.php
 * -----------------------------------------------------------------------
 * JSON API for the registered-user booking feature. Handles three actions:
 *
 *   action=availability   – given check_in + check_out, returns all
 *                           accommodations with their real-time status.
 *
 *   action=book           – submits a new booking for a specific room.
 *                           Auto-approves (status=confirmed) when the room
 *                           is free for the requested dates.
 *
 *   action=room_calendar  – given room_id, returns the admin-set status
 *                           and all confirmed/pending booking date ranges
 *                           for that room (3 months back, 12 months ahead),
 *                           so the front-end calendar can colour each day.
 *
 * All requests must be POST. CSRF is validated on every call.
 * Requires an active user session.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/auth-common.php';

bienmarko_start_session();
bienmarko_require_role('user');

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// ---- Helpers ----------------------------------------------------------------

function bk_fail(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function bk_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

// ---- Gate checks ------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bk_fail(405, 'Method not allowed.');
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!bienmarko_csrf_valid($input['csrf_token'] ?? null)) {
    bk_fail(403, 'Your session has expired. Please refresh the page and try again.');
}

// ---- Bootstrap the bookings table if needed --------------------------------

function bk_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // Unified bookings table — supports both registered users (guest_id set)
    // and walk-in guest bookings (guest_id NULL, guest_name/email/phone set).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `bookings` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference`        VARCHAR(20)  NOT NULL UNIQUE,
            `guest_id`         INT UNSIGNED NULL DEFAULT NULL,
            `accommodation_id` INT UNSIGNED NOT NULL,
            `check_in`         DATE         NOT NULL,
            `check_out`        DATE         NOT NULL,
            `guests_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `notes`            TEXT         NULL,
            `status`           ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'confirmed',
            `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `guest_name`       VARCHAR(200) NULL DEFAULT NULL,
            `email`            VARCHAR(200) NULL DEFAULT NULL,
            `phone`            VARCHAR(30)  NULL DEFAULT NULL,
            `address`          VARCHAR(255) NULL DEFAULT NULL,
            `payment_method`   ENUM('cash','gcash') NULL DEFAULT 'cash',
            `booking_type`     ENUM('registered','guest') NOT NULL DEFAULT 'registered',
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_guest`         (`guest_id`),
            KEY `idx_accommodation` (`accommodation_id`),
            KEY `idx_dates`         (`check_in`, `check_out`),
            KEY `idx_status`        (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // If the table already existed with the old schema (guest_id NOT NULL,
    // missing guest columns), run safe migrations to bring it up to date.
    try {
        // Allow guest_id to be NULL for walk-in guest bookings.
        $pdo->exec(
            "ALTER TABLE `bookings`
             MODIFY COLUMN `guest_id` INT UNSIGNED NULL DEFAULT NULL"
        );

        // Add guest-booking columns if missing.
        $pdo->exec(
            "ALTER TABLE `bookings`
                 ADD COLUMN IF NOT EXISTS `guest_name`     VARCHAR(200) NULL DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `email`          VARCHAR(200) NULL DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `phone`          VARCHAR(30)  NULL DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `address`        VARCHAR(255) NULL DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','gcash') NULL DEFAULT 'cash',
                 ADD COLUMN IF NOT EXISTS `booking_type`   ENUM('registered','guest') NOT NULL DEFAULT 'registered'"
        );

        // Companion / guest-mate names — currently surfaced in the UI only
        // for Swimming Pool bookings (per-person pricing means a single
        // booking often covers a group), stored as a JSON array of strings.
        $pdo->exec(
            "ALTER TABLE `bookings`
                 ADD COLUMN IF NOT EXISTS `companion_names` TEXT NULL DEFAULT NULL"
        );
    } catch (Throwable $e) {
        error_log('[bienmarko] bookings table migration warning: ' . $e->getMessage());
    }

    // Admin notifications table.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `admin_notifications` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `booking_id` INT UNSIGNED NULL DEFAULT NULL,
            `type`       VARCHAR(60)  NOT NULL DEFAULT 'booking',
            `message`    VARCHAR(400) NOT NULL,
            `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_booking` (`booking_id`),
            KEY `idx_read`    (`is_read`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Add missing columns to admin_notifications if created with older schema.
    try {
        $pdo->exec(
            "ALTER TABLE `admin_notifications`
                 ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `type`       VARCHAR(60) NOT NULL DEFAULT 'booking'"
        );
    } catch (Throwable $e) {
        error_log('[bienmarko] admin_notifications migration warning: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Inserts one row into admin_notifications so the admin dashboard
 * bell immediately shows a new unread alert for this booking.
 */
function bk_notify_admin(PDO $pdo, int $bookingId, string $reference, string $guestName, string $roomName, string $checkIn, string $checkOut): void
{
    try {
        $ci      = date('M j', strtotime($checkIn));
        $co      = date('M j, Y', strtotime($checkOut));
        $message = "New booking {$reference}: {$guestName} booked {$roomName} ({$ci} – {$co})";

        $stmt = $pdo->prepare(
            "INSERT INTO admin_notifications (booking_id, type, message, is_read)
             VALUES (:booking_id, 'booking', :message, 0)"
        );
        $stmt->execute([
            'booking_id' => $bookingId,
            'message'    => $message,
        ]);
    } catch (Throwable $e) {
        error_log('[bienmarko] admin notification insert failed: ' . $e->getMessage());
    }
}

/**
 * Generic admin_notifications insert used by actions other than "book"
 * (cancellation, modification) so the admin dashboard bell reflects
 * guest-initiated changes too, not just new bookings.
 */
function bk_notify_admin_event(PDO $pdo, int $bookingId, string $type, string $message): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO admin_notifications (booking_id, type, message, is_read)
             VALUES (:booking_id, :type, :message, 0)"
        );
        $stmt->execute([
            'booking_id' => $bookingId,
            'type'       => $type,
            'message'    => $message,
        ]);
    } catch (Throwable $e) {
        error_log('[bienmarko] admin notification insert failed: ' . $e->getMessage());
    }
}

// ---- Date helpers -----------------------------------------------------------

function bk_parse_date(mixed $raw): ?string
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

function bk_validate_dates(string $checkIn, string $checkOut): ?string
{
    $today = date('Y-m-d');

    if ($checkIn < $today) {
        return 'Check-in date cannot be in the past.';
    }
    if ($checkOut <= $checkIn) {
        return 'Check-out must be at least one night after check-in.';
    }
    $nights = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    if ($nights > 90) {
        return 'Maximum stay is 90 nights.';
    }

    return null;
}

/**
 * Registered users may modify or cancel their own bookings up to one (1)
 * week prior to the scheduled check-in date (see PURPOSE AND DESCRIPTION /
 * SCOPE AND DELIMITATIONS). Requests inside that window must be
 * coordinated directly with resort management instead of self-service.
 */
function bk_edit_window_ok(string $checkIn): bool
{
    $diffDays = (strtotime($checkIn) - strtotime(date('Y-m-d'))) / 86400;
    return $diffDays >= 7;
}

/**
 * Returns TRUE if a booking overlapping [checkIn, checkOut) already exists
 * for the given accommodation (half-open interval overlap).
 * Excludes cancelled bookings.
 */
function bk_has_conflict(
    PDO $pdo,
    int $accommodationId,
    string $checkIn,
    string $checkOut,
    ?int $excludeId = null
): bool {
    $sql = "SELECT COUNT(*) FROM bookings
            WHERE accommodation_id = :acc_id
              AND status NOT IN ('cancelled')
              AND check_in  < :check_out
              AND check_out > :check_in";

    $params = [
        'acc_id'    => $accommodationId,
        'check_in'  => $checkIn,
        'check_out' => $checkOut,
    ];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * True when an accommodation's category is "Swimming Pool" — the one
 * type billed per person (see bk_action_book()) rather than per night,
 * and shared by multiple concurrent bookings up to its `capacity`
 * instead of being exclusively booked by a single party.
 */
function bk_is_pool_type(string $type): bool
{
    return strcasecmp(trim($type), 'Swimming Pool') === 0;
}

/**
 * Total guests already booked (any non-cancelled status) for a
 * Swimming Pool accommodation across dates overlapping
 * [checkIn, checkOut). Used instead of bk_has_conflict() for pool-type
 * accommodations so multiple guest accounts/walk-ins can share the
 * pool at once, up to its capacity, rather than the first booking
 * blocking the entire date range for everyone else.
 */
function bk_pool_booked_guests(
    PDO $pdo,
    int $accommodationId,
    string $checkIn,
    string $checkOut,
    ?int $excludeId = null
): int {
    $sql = "SELECT COALESCE(SUM(guests_count), 0) FROM bookings
            WHERE accommodation_id = :acc_id
              AND status NOT IN ('cancelled')
              AND check_in  < :check_out
              AND check_out > :check_in";

    $params = [
        'acc_id'    => $accommodationId,
        'check_in'  => $checkIn,
        'check_out' => $checkOut,
    ];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Sanitises the companion-names list submitted alongside a booking
 * (surfaced in the UI for Swimming Pool bookings so a group can list
 * who's coming, but accepted generically here). Trims, drops blanks,
 * caps each name's length and the list size, and returns a JSON string
 * ready for the `companion_names` column — or NULL when there's
 * nothing worth storing.
 */
function bk_sanitize_companion_names($raw): ?string
{
    if (!is_array($raw)) {
        return null;
    }

    $names = [];
    foreach ($raw as $name) {
        if (!is_string($name)) {
            continue;
        }
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $names[] = mb_substr($name, 0, 100);
        if (count($names) >= 50) {
            break;
        }
    }

    return $names ? json_encode(array_values($names), JSON_UNESCAPED_UNICODE) : null;
}

// ---- Action: availability ---------------------------------------------------

/**
 * Defensively adds the `tour_filename` column if this deployment's DB
 * hasn't been migrated yet (e.g. no admin has opened admin/accommodations.php
 * since updating). Same information_schema-guarded pattern used by
 * admin/includes/accommodations-data.php's acc_ensure_table(), duplicated
 * here so this endpoint never hard-fails just because of load order.
 */
function bk_ensure_tour_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'accommodations' AND COLUMN_NAME = 'tour_filename'"
        );
        $stmt->execute(['db' => $dbName]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `accommodations` ADD COLUMN `tour_filename` VARCHAR(255) NULL AFTER `image_filename`");
        }
    } catch (Throwable $e) {
        // Best-effort — if this fails, the SELECT below simply won't find the column and callers handle a missing key.
    }
    $done = true;
}

/**
 * Defensively creates the `accommodation_photos` gallery table if this
 * deployment's DB hasn't been migrated yet (e.g. no admin has opened
 * admin/accommodations.php since the gallery feature was added). Mirrors
 * acc_ensure_photos_table() in admin/includes/accommodations-data.php —
 * duplicated here rather than shared, same reasoning as bk_is_pool_type()
 * vs gb_is_pool_type() elsewhere in this project.
 */
function bk_ensure_photos_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `accommodation_photos` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accommodation_id` INT UNSIGNED NOT NULL,
                `filename`         VARCHAR(255) NOT NULL,
                `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_accommodation` (`accommodation_id`),
                CONSTRAINT `fk_acc_photos_accommodation_bk` FOREIGN KEY (`accommodation_id`)
                    REFERENCES `accommodations` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Best-effort — if this fails (e.g. FK name collision on a table
        // that already exists under a different constraint name), the
        // photos query below simply returns nothing per room.
        error_log('[bienmarko] accommodation_photos ensure warning: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Bulk-fetches gallery photo filenames for a set of accommodation IDs,
 * grouped by accommodation_id and ordered the same way the admin gallery
 * displays them (sort_order, then id). Returns [] on any failure so a
 * missing/un-migrated table never breaks the availability response —
 * callers just fall back to the single cover photo.
 */
function bk_fetch_photos_by_accommodation(PDO $pdo, array $accommodationIds): array
{
    $photosByAcc = [];
    if (!$accommodationIds) {
        return $photosByAcc;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($accommodationIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT `accommodation_id`, `filename`
             FROM `accommodation_photos`
             WHERE `accommodation_id` IN ($placeholders)
             ORDER BY `sort_order` ASC, `id` ASC"
        );
        $stmt->execute($accommodationIds);
        foreach ($stmt->fetchAll() as $photoRow) {
            $photosByAcc[(int) $photoRow['accommodation_id']][] = $photoRow['filename'];
        }
    } catch (Throwable $e) {
        // Table missing or query failed — treat as "no gallery photos".
    }

    return $photosByAcc;
}

/**
 * Combines a room's single cover photo (`image_filename`) with its extra
 * gallery photos into one ordered, de-duplicated filename list — cover
 * first (matches what admins see as the "main" photo), then any gallery
 * photos not already equal to the cover (sharing a photo as both cover
 * and gallery entry, e.g. via admin's "set as cover", shouldn't show
 * twice in the booking-flow lightbox).
 */
function bk_combine_photos(?string $coverFilename, array $galleryFilenames): array
{
    $photos = [];
    if ($coverFilename) {
        $photos[] = $coverFilename;
    }
    foreach ($galleryFilenames as $filename) {
        if (!in_array($filename, $photos, true)) {
            $photos[] = $filename;
        }
    }
    return $photos;
}

function bk_action_availability(PDO $pdo, array $input): never
{
    $checkIn  = bk_parse_date($input['check_in']  ?? null);
    $checkOut = bk_parse_date($input['check_out'] ?? null);

    if (!$checkIn || !$checkOut) {
        bk_fail(400, 'Please provide both check-in and check-out dates.');
    }

    $dateError = bk_validate_dates($checkIn, $checkOut);
    if ($dateError) {
        bk_fail(400, $dateError);
    }

    // Guest count the visitor currently has selected (Step 1's stepper).
    // Only affects Swimming Pool accommodations, whose price/availability
    // are computed per person rather than per night — for every other
    // type the price/night and conflict check don't depend on it.
    $guestsCount = filter_var($input['guests_count'] ?? 1, FILTER_VALIDATE_INT);
    if ($guestsCount === false || $guestsCount < 1) {
        $guestsCount = 1;
    }
    if ($guestsCount > 50) {
        $guestsCount = 50;
    }

    bk_ensure_tour_column($pdo);
    bk_ensure_photos_table($pdo);

    $accStmt = $pdo->query(
        "SELECT id, name, type, capacity, price, status,
                description, amenities, image_filename, tour_filename
         FROM accommodations
         ORDER BY name ASC"
    );
    $accommodations = $accStmt->fetchAll();

    $accIds      = array_map(static function ($r) { return (int) $r['id']; }, $accommodations);
    $photosByAcc = bk_fetch_photos_by_accommodation($pdo, $accIds);

    $nights  = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    $results = [];

    foreach ($accommodations as $row) {
        $accId = (int) $row['id'];
        $isPool = bk_is_pool_type((string) $row['type']);

        $adminBlocked = in_array($row['status'], ['unavailable', 'maintenance'], true);

        $bookedGuests = 0;
        $remaining    = null;
        $hasConflict  = false;

        if ($isPool) {
            $bookedGuests = bk_pool_booked_guests($pdo, $accId, $checkIn, $checkOut);
            $remaining    = max(0, (int) $row['capacity'] - $bookedGuests);
        } else {
            $hasConflict = bk_has_conflict($pdo, $accId, $checkIn, $checkOut);
        }

        if ($adminBlocked) {
            $availability      = 'unavailable';
            $availabilityLabel = $row['status'] === 'maintenance' ? 'Under Maintenance' : 'Unavailable';
        } elseif ($isPool) {
            if ($remaining <= 0) {
                $availability      = 'occupied';
                $availabilityLabel = 'Fully booked for these dates';
            } elseif ($remaining < $guestsCount) {
                $availability      = 'occupied';
                $availabilityLabel = 'Only ' . $remaining . ' slot' . ($remaining === 1 ? '' : 's') . ' left for these dates';
            } else {
                $availability      = 'available';
                $availabilityLabel = $bookedGuests > 0
                    ? $remaining . ' slot' . ($remaining === 1 ? '' : 's') . ' left'
                    : 'Available';
            }
        } elseif ($hasConflict) {
            $availability      = 'occupied';
            $availabilityLabel = 'Occupied on selected dates';
        } else {
            $availability      = 'available';
            $availabilityLabel = 'Available';
        }

        $amenities = [];
        if ($row['amenities']) {
            $decoded = json_decode($row['amenities'], true);
            if (is_array($decoded)) {
                $amenities = $decoded;
            }
        }

        // Swimming Pool: priced per person (guests selected in Step 1),
        // independent of the number of nights. Every other type: priced
        // per night, independent of guest count, same as before.
        $total = $isPool
            ? (float) $row['price'] * $guestsCount
            : (float) $row['price'] * $nights;

        $results[] = [
            'id'                  => $accId,
            'name'                => $row['name'],
            'type'                => $row['type'],
            'capacity'            => (int) $row['capacity'],
            'price'               => (float) $row['price'],
            'price_formatted'     => '₱' . number_format((float) $row['price'], 2),
            'price_unit'          => $isPool ? 'person' : 'night',
            'total'               => $total,
            'total_formatted'     => '₱' . number_format($total, 2),
            'nights'              => $nights,
            'description'         => $row['description'] ?? '',
            'amenities'           => $amenities,
            'image_filename'      => $row['image_filename'],
            'tour_filename'       => $row['tour_filename'] ?? null,
            // Cover photo + gallery photos, ordered and de-duplicated —
            // lets the booking flow show a "View N photos" lightbox
            // instead of just the single cover image.
            'photos'              => bk_combine_photos($row['image_filename'], $photosByAcc[$accId] ?? []),
            'availability'        => $availability,
            'availability_label'  => $availabilityLabel,
            'is_pool'             => $isPool,
            'remaining_capacity'  => $isPool ? $remaining : null,
        ];
    }

    bk_ok([
        'check_in'     => $checkIn,
        'check_out'    => $checkOut,
        'nights'       => $nights,
        'guests_count' => $guestsCount,
        'rooms'        => $results,
    ]);
}

// ---- Action: room_calendar --------------------------------------------------

function bk_action_room_calendar(PDO $pdo, array $input): never
{
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$roomId || $roomId < 1) {
        bk_fail(400, 'Invalid room ID.');
    }

    $roomStmt = $pdo->prepare(
        "SELECT id, name, type, status
         FROM accommodations
         WHERE id = :id
         LIMIT 1"
    );
    $roomStmt->execute(['id' => $roomId]);
    $room = $roomStmt->fetch();

    if (!$room) {
        bk_fail(404, 'Room not found.');
    }

    $windowStart = date('Y-m-d', strtotime('-3 months'));
    $windowEnd   = date('Y-m-d', strtotime('+12 months'));

    $bkStmt = $pdo->prepare(
        "SELECT check_in, check_out
         FROM bookings
         WHERE accommodation_id = :room_id
           AND status NOT IN ('cancelled')
           AND check_in  < :window_end
           AND check_out > :window_start
         ORDER BY check_in ASC"
    );
    $bkStmt->execute([
        'room_id'      => $roomId,
        'window_start' => $windowStart,
        'window_end'   => $windowEnd,
    ]);
    $rows = $bkStmt->fetchAll();

    $bookings = [];
    foreach ($rows as $row) {
        $bookings[] = [
            'check_in'  => $row['check_in'],
            'check_out' => $row['check_out'],
        ];
    }

    bk_ok([
        'room_id'     => (int) $room['id'],
        'room_name'   => $room['name'],
        'room_type'   => $room['type'],
        'room_status' => $room['status'],
        'bookings'    => $bookings,
        'window'      => [
            'start' => $windowStart,
            'end'   => $windowEnd,
        ],
    ]);
}

// ---- Action: book -----------------------------------------------------------

function bk_generate_reference(PDO $pdo): string
{
    $prefix = 'BM-' . date('Ymd') . '-';
    do {
        $suffix = strtoupper(bin2hex(random_bytes(2)));
        $ref    = $prefix . $suffix;
        $check  = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :ref');
        $check->execute(['ref' => $ref]);
    } while ((int) $check->fetchColumn() > 0);

    return $ref;
}

function bk_action_book(PDO $pdo, array $input, int $guestId): never
{
    $checkIn         = bk_parse_date($input['check_in']  ?? null);
    $checkOut        = bk_parse_date($input['check_out'] ?? null);
    $accommodationId = filter_var($input['accommodation_id'] ?? null, FILTER_VALIDATE_INT);
    $guestsCount     = filter_var($input['guests_count'] ?? 1, FILTER_VALIDATE_INT);
    $notes           = trim((string) ($input['notes'] ?? ''));
    $companionNames  = bk_sanitize_companion_names($input['companion_names'] ?? null);

    if (!$checkIn || !$checkOut) {
        bk_fail(400, 'Please provide both check-in and check-out dates.');
    }

    $dateError = bk_validate_dates($checkIn, $checkOut);
    if ($dateError) {
        bk_fail(400, $dateError);
    }

    if (!$accommodationId || $accommodationId < 1) {
        bk_fail(400, 'Please select a room to book.');
    }

    if ($guestsCount === false || $guestsCount < 1 || $guestsCount > 50) {
        bk_fail(400, 'Guest count must be between 1 and 50.');
    }

    if (mb_strlen($notes) > 1000) {
        bk_fail(400, 'Notes are too long (max 1000 characters).');
    }

    $accStmt = $pdo->prepare(
        "SELECT id, name, type, price, status, capacity
         FROM accommodations
         WHERE id = :id
         LIMIT 1"
    );
    $accStmt->execute(['id' => $accommodationId]);
    $room = $accStmt->fetch();

    if (!$room) {
        bk_fail(404, 'The selected room no longer exists. Please refresh and try again.');
    }

    if (in_array($room['status'], ['unavailable', 'maintenance'], true)) {
        $label = $room['status'] === 'maintenance' ? 'under maintenance' : 'unavailable';
        bk_fail(409, "This room is currently {$label} and cannot be booked. Please choose another room.");
    }

    if ((int) $guestsCount > (int) $room['capacity']) {
        bk_fail(400, "This room holds up to {$room['capacity']} guest(s). Please reduce your guest count or choose a larger room.");
    }

    $isPool = bk_is_pool_type((string) $room['type']);

    // Conflict / capacity check inside a transaction (race-condition safe)
    $pdo->beginTransaction();

    try {
        if ($isPool) {
            // Swimming Pool: multiple bookings can share the pool at once,
            // so instead of blocking on ANY overlapping booking, only
            // block once the combined guest count for the requested
            // dates would exceed the pool's capacity.
            $bookedGuests = bk_pool_booked_guests($pdo, $accommodationId, $checkIn, $checkOut);
            if ($bookedGuests + (int) $guestsCount > (int) $room['capacity']) {
                $pdo->rollBack();
                $remaining = max(0, (int) $room['capacity'] - $bookedGuests);
                bk_fail(409, $remaining > 0
                    ? "Sorry — only {$remaining} pool slot" . ($remaining === 1 ? '' : 's') . " left for those dates. Please reduce your guest count or choose different dates."
                    : 'Sorry — the swimming pool is fully booked for those dates. Please choose different dates.');
            }
        } elseif (bk_has_conflict($pdo, $accommodationId, $checkIn, $checkOut)) {
            $pdo->rollBack();
            bk_fail(409, 'Sorry — this room just became unavailable for those dates. Please pick different dates or another room.');
        }

        $nights = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);

        // Swimming Pool is billed per person; every other accommodation
        // type is billed per night, same as before.
        $totalAmount = $isPool
            ? (float) $room['price'] * (int) $guestsCount
            : (float) $room['price'] * $nights;

        $reference   = bk_generate_reference($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO bookings
                (reference, guest_id, accommodation_id, check_in, check_out,
                 guests_count, notes, status, total_amount, booking_type, companion_names)
             VALUES
                (:reference, :guest_id, :accommodation_id, :check_in, :check_out,
                 :guests_count, :notes, 'confirmed', :total_amount, 'registered', :companion_names)"
        );

        $stmt->execute([
            'reference'        => $reference,
            'guest_id'         => $guestId,
            'accommodation_id' => $accommodationId,
            'check_in'         => $checkIn,
            'check_out'        => $checkOut,
            'guests_count'     => $guestsCount,
            'notes'            => $notes !== '' ? $notes : null,
            'total_amount'     => $totalAmount,
            'companion_names'  => $companionNames,
        ]);

        $bookingId = (int) $pdo->lastInsertId();
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[bienmarko] Booking insert failed: ' . $e->getMessage());
        bk_fail(500, 'Something went wrong while saving your booking. Please try again.');
    }

    // Admin notification (outside transaction — non-fatal)
    $guestName = 'Guest';
    try {
        $gStmt = $pdo->prepare(
            "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM guests WHERE id = :id LIMIT 1"
        );
        $gStmt->execute(['id' => $guestId]);
        $gRow = $gStmt->fetch();
        if ($gRow && !empty($gRow['full_name'])) {
            $guestName = trim($gRow['full_name']);
        }
    } catch (Throwable $e) {
        error_log('[bienmarko] Guest name lookup failed: ' . $e->getMessage());
    }

    bk_notify_admin($pdo, $bookingId, $reference, $guestName, $room['name'], $checkIn, $checkOut);

    bk_ok([
        'booking_id'      => $bookingId,
        'reference'       => $reference,
        'room_name'       => $room['name'],
        'check_in'        => $checkIn,
        'check_out'       => $checkOut,
        'nights'          => $nights,
        'guests_count'    => (int) $guestsCount,
        'total_amount'    => $totalAmount,
        'total_formatted' => '₱' . number_format($totalAmount, 2),
        'is_pool'         => $isPool,
    ]);
}

// ---- Action: update (self-service edit) --------------------------------------

/**
 * Lets a registered user modify the dates, guest count, notes, and
 * companion names of their own pending/confirmed booking. Blocked once
 * the booking is inside the 1-week-before-check-in policy window (see
 * bk_edit_window_ok()) — those changes must go through management.
 * The accommodation itself is not changed; only its dates/guests/notes.
 */
function bk_action_update(PDO $pdo, array $input, int $guestId): never
{
    $bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bookingId || $bookingId < 1) {
        bk_fail(400, 'Invalid booking.');
    }

    $stmt = $pdo->prepare(
        "SELECT b.id, b.reference, b.status, b.check_in AS old_check_in,
                b.accommodation_id, a.name AS room_name, a.type AS room_type,
                a.price, a.capacity, a.status AS room_status
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.id = :id AND b.guest_id = :guest_id
         LIMIT 1"
    );
    $stmt->execute(['id' => $bookingId, 'guest_id' => $guestId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        bk_fail(404, 'Booking not found.');
    }

    if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
        bk_fail(409, 'Only pending or confirmed bookings can be modified.');
    }

    if (!bk_edit_window_ok($booking['old_check_in'])) {
        bk_fail(403, 'Bookings can only be modified up to 1 week before check-in. Please contact the resort directly for changes within this period.');
    }

    $checkIn        = bk_parse_date($input['check_in']  ?? null);
    $checkOut       = bk_parse_date($input['check_out'] ?? null);
    $guestsCount    = filter_var($input['guests_count'] ?? 1, FILTER_VALIDATE_INT);
    $notes          = trim((string) ($input['notes'] ?? ''));
    $companionNames = bk_sanitize_companion_names($input['companion_names'] ?? null);

    if (!$checkIn || !$checkOut) {
        bk_fail(400, 'Please provide both check-in and check-out dates.');
    }

    $dateError = bk_validate_dates($checkIn, $checkOut);
    if ($dateError) {
        bk_fail(400, $dateError);
    }

    if ($guestsCount === false || $guestsCount < 1 || $guestsCount > 50) {
        bk_fail(400, 'Guest count must be between 1 and 50.');
    }

    if (mb_strlen($notes) > 1000) {
        bk_fail(400, 'Notes are too long (max 1000 characters).');
    }

    if (in_array($booking['room_status'], ['unavailable', 'maintenance'], true)) {
        $label = $booking['room_status'] === 'maintenance' ? 'under maintenance' : 'unavailable';
        bk_fail(409, "This room is currently {$label} and can no longer be modified online. Please contact the resort directly.");
    }

    if ((int) $guestsCount > (int) $booking['capacity']) {
        bk_fail(400, "This room holds up to {$booking['capacity']} guest(s). Please reduce your guest count.");
    }

    $accommodationId = (int) $booking['accommodation_id'];
    $isPool          = bk_is_pool_type((string) $booking['room_type']);

    $pdo->beginTransaction();

    try {
        if ($isPool) {
            // Exclude this booking's own current guests from the tally so
            // editing (e.g. just changing notes) doesn't double-count them.
            $bookedGuests = bk_pool_booked_guests($pdo, $accommodationId, $checkIn, $checkOut, $bookingId);
            if ($bookedGuests + (int) $guestsCount > (int) $booking['capacity']) {
                $pdo->rollBack();
                $remaining = max(0, (int) $booking['capacity'] - $bookedGuests);
                bk_fail(409, $remaining > 0
                    ? "Sorry — only {$remaining} pool slot" . ($remaining === 1 ? '' : 's') . " left for those dates. Please reduce your guest count or choose different dates."
                    : 'Sorry — the swimming pool is fully booked for those dates. Please choose different dates.');
            }
        } elseif (bk_has_conflict($pdo, $accommodationId, $checkIn, $checkOut, $bookingId)) {
            $pdo->rollBack();
            bk_fail(409, 'Sorry — this room is not available for those dates. Please pick different dates.');
        }

        $nights = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
        $totalAmount = $isPool
            ? (float) $booking['price'] * (int) $guestsCount
            : (float) $booking['price'] * $nights;

        $upd = $pdo->prepare(
            "UPDATE bookings SET
                check_in         = :check_in,
                check_out        = :check_out,
                guests_count     = :guests_count,
                notes            = :notes,
                total_amount     = :total_amount,
                companion_names  = :companion_names
             WHERE id = :id AND guest_id = :guest_id"
        );
        $upd->execute([
            'check_in'        => $checkIn,
            'check_out'       => $checkOut,
            'guests_count'    => $guestsCount,
            'notes'           => $notes !== '' ? $notes : null,
            'total_amount'    => $totalAmount,
            'companion_names' => $companionNames,
            'id'              => $bookingId,
            'guest_id'        => $guestId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[bienmarko] Booking update failed: ' . $e->getMessage());
        bk_fail(500, 'Something went wrong while saving your changes. Please try again.');
    }

    bk_notify_admin_event(
        $pdo,
        $bookingId,
        'modification',
        "Booking {$booking['reference']} for {$booking['room_name']} was modified by the guest."
    );

    bk_ok([
        'booking_id'      => $bookingId,
        'reference'       => $booking['reference'],
        'room_name'       => $booking['room_name'],
        'check_in'        => $checkIn,
        'check_out'       => $checkOut,
        'nights'          => $nights,
        'guests_count'    => (int) $guestsCount,
        'notes'           => $notes,
        'companion_names' => $companionNames ? json_decode($companionNames, true) : [],
        'total_amount'    => $totalAmount,
        'total_formatted' => '₱' . number_format($totalAmount, 2),
        'is_pool'         => $isPool,
        'can_modify'      => bk_edit_window_ok($checkIn),
    ]);
}

// ---- Action: cancel -----------------------------------------------------------

/**
 * Lets a registered user cancel their own pending/confirmed booking,
 * subject to the same 1-week-before-check-in policy window as
 * bk_action_update(). Cancellation just flips status — the row (and its
 * reference number) is kept for the guest's history and admin records.
 */
function bk_action_cancel(PDO $pdo, array $input, int $guestId): never
{
    $bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bookingId || $bookingId < 1) {
        bk_fail(400, 'Invalid booking.');
    }

    $stmt = $pdo->prepare(
        "SELECT b.id, b.reference, b.status, b.check_in, a.name AS room_name
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.id = :id AND b.guest_id = :guest_id
         LIMIT 1"
    );
    $stmt->execute(['id' => $bookingId, 'guest_id' => $guestId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        bk_fail(404, 'Booking not found.');
    }

    if ($booking['status'] === 'cancelled') {
        bk_fail(409, 'This booking is already cancelled.');
    }

    if ($booking['status'] === 'completed') {
        bk_fail(409, 'Completed bookings cannot be cancelled.');
    }

    if (!bk_edit_window_ok($booking['check_in'])) {
        bk_fail(403, 'Bookings can only be cancelled up to 1 week before check-in. Please contact the resort directly for changes within this period.');
    }

    $upd = $pdo->prepare(
        "UPDATE bookings SET status = 'cancelled' WHERE id = :id AND guest_id = :guest_id"
    );
    $upd->execute(['id' => $bookingId, 'guest_id' => $guestId]);

    bk_notify_admin_event(
        $pdo,
        $bookingId,
        'cancellation',
        "Booking {$booking['reference']} for {$booking['room_name']} was cancelled by the guest."
    );

    bk_ok([
        'booking_id' => $bookingId,
        'reference'  => $booking['reference'],
        'status'     => 'cancelled',
    ]);
}

// ---- Dispatch ---------------------------------------------------------------

$pdo = bienmarko_db();
bk_ensure_tables($pdo);

$action  = (string) ($input['action'] ?? '');
$guestId = (int) ($_SESSION['auth_user_id'] ?? 0);

switch ($action) {
    case 'availability':
        bk_action_availability($pdo, $input);

    case 'room_calendar':
        bk_action_room_calendar($pdo, $input);

    case 'book':
        bk_action_book($pdo, $input, $guestId);

    case 'update':
        bk_action_update($pdo, $input, $guestId);

    case 'cancel':
        bk_action_cancel($pdo, $input, $guestId);

    default:
        bk_fail(400, 'Unknown action.');
}
