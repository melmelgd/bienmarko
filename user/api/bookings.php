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

    bk_ensure_tour_column($pdo);

    $accStmt = $pdo->query(
        "SELECT id, name, type, capacity, price, status,
                description, amenities, image_filename, tour_filename
         FROM accommodations
         ORDER BY name ASC"
    );
    $accommodations = $accStmt->fetchAll();

    $nights  = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    $results = [];

    foreach ($accommodations as $row) {
        $accId = (int) $row['id'];

        $adminBlocked = in_array($row['status'], ['unavailable', 'maintenance'], true);
        $hasConflict  = bk_has_conflict($pdo, $accId, $checkIn, $checkOut);

        if ($adminBlocked) {
            $availability      = 'unavailable';
            $availabilityLabel = $row['status'] === 'maintenance' ? 'Under Maintenance' : 'Unavailable';
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

        $results[] = [
            'id'                 => $accId,
            'name'               => $row['name'],
            'type'               => $row['type'],
            'capacity'           => (int) $row['capacity'],
            'price'              => (float) $row['price'],
            'price_formatted'    => '₱' . number_format((float) $row['price'], 2),
            'total'              => (float) $row['price'] * $nights,
            'total_formatted'    => '₱' . number_format((float) $row['price'] * $nights, 2),
            'nights'             => $nights,
            'description'        => $row['description'] ?? '',
            'amenities'          => $amenities,
            'image_filename'     => $row['image_filename'],
            'tour_filename'      => $row['tour_filename'] ?? null,
            'availability'       => $availability,
            'availability_label' => $availabilityLabel,
        ];
    }

    bk_ok([
        'check_in'  => $checkIn,
        'check_out' => $checkOut,
        'nights'    => $nights,
        'rooms'     => $results,
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
        "SELECT id, name, price, status, capacity
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

    // Conflict check inside a transaction (race-condition safe)
    $pdo->beginTransaction();

    try {
        if (bk_has_conflict($pdo, $accommodationId, $checkIn, $checkOut)) {
            $pdo->rollBack();
            bk_fail(409, 'Sorry — this room just became unavailable for those dates. Please pick different dates or another room.');
        }

        $nights      = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
        $totalAmount = (float) $room['price'] * $nights;
        $reference   = bk_generate_reference($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO bookings
                (reference, guest_id, accommodation_id, check_in, check_out,
                 guests_count, notes, status, total_amount, booking_type)
             VALUES
                (:reference, :guest_id, :accommodation_id, :check_in, :check_out,
                 :guests_count, :notes, 'confirmed', :total_amount, 'registered')"
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

    default:
        bk_fail(400, 'Unknown action.');
}
