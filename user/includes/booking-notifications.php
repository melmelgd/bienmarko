<?php
/**
 * user/includes/booking-notifications.php
 *
 * Puts booking notifications in the guest's bell icon (guest_notifications):
 *
 *   1. Booking confirmation  (type 'booking_confirmed')
 *        "Booking confirmed! Reference BM-20261105-A1B2 ..."
 *   2. 1 week before check-in (type 'booking_reminder_week')
 *   3. 1 night before check-in (type 'booking_reminder_night')
 *        -> shown from 6:00 PM the evening before (see BM_BN_NIGHT_BEFORE_HOUR)
 *
 * Every notification is created ONCE per booking (and once per check-in date,
 * so a guest who reschedules gets fresh reminders for the new date). Dedupe is
 * enforced by a UNIQUE index on (booking_id, type, remind_key), so two page
 * loads at the same moment can never create duplicates.
 *
 * How it is triggered:
 *   - Confirmation: api/bookings.php calls bm_bn_notify_confirmation() right
 *     after a booking is saved.
 *   - Reminders (and a safety-net confirmation for any booking that missed it):
 *     user/includes/bootstrap.php calls bm_bn_sync() on every guest page load,
 *     so a reminder appears the next time the guest opens the site after it
 *     becomes due.
 *
 * A reminder is skipped when the booking was made AFTER that reminder's moment
 * (e.g. booked 3 days ahead -> no "1 week" reminder; booked this morning for
 * tomorrow -> no "1 night" reminder), since the confirmation just covered it.
 *
 * Reminders for cancelled bookings, or for an old check-in date after the guest
 * rescheduled, are removed automatically if the guest hasn't read them yet.
 */

/* ------------------------------------------------------------------
   SETTINGS — edit these
------------------------------------------------------------------ */

// Timezone used to decide when a reminder is due.
if (!defined('BM_BN_TIMEZONE'))           define('BM_BN_TIMEZONE', 'Asia/Manila');

// "1 night before" shows up from this hour (24h clock) on the evening before check-in.
if (!defined('BM_BN_NIGHT_BEFORE_HOUR'))  define('BM_BN_NIGHT_BEFORE_HOUR', 18);

// "1 week before" = this many days before check-in.
if (!defined('BM_BN_WEEK_BEFORE_DAYS'))   define('BM_BN_WEEK_BEFORE_DAYS', 7);

/* ------------------------------------------------------------------
   Schema (additive + idempotent; cheap after the first run)
------------------------------------------------------------------ */
function bm_bn_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

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

    // remind_key = what this notification is about, e.g. 'confirmed',
    // 'week:2026-11-05', 'night:2026-11-05'. NULL for all older notification types.
    $hasCol = $pdo->query("SHOW COLUMNS FROM `guest_notifications` LIKE 'remind_key'")->fetch();
    if (!$hasCol) {
        $pdo->exec("ALTER TABLE `guest_notifications` ADD COLUMN `remind_key` VARCHAR(40) NULL DEFAULT NULL AFTER `type`");
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM `guest_notifications` WHERE Key_name = 'uq_booking_notice'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE `guest_notifications` ADD UNIQUE INDEX `uq_booking_notice` (`booking_id`, `type`, `remind_key`)");
    }
}

/* ------------------------------------------------------------------
   Helpers
------------------------------------------------------------------ */

/** "Nov 5 – Nov 7, 2026" */
function bm_bn_date_range(string $checkIn, string $checkOut): string
{
    $in  = strtotime($checkIn);
    $out = strtotime($checkOut);
    if (!$in || !$out) {
        return trim($checkIn . ' – ' . $checkOut);
    }
    return date('M j', $in) . ' – ' . date('M j, Y', $out);
}

/** Inserts one notification; returns true only if a NEW row was created. */
function bm_bn_insert(PDO $pdo, int $guestId, int $bookingId, string $type, string $key, string $message): bool
{
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO guest_notifications (guest_id, booking_id, type, remind_key, message)
         VALUES (:g, :b, :t, :k, :m)"
    );
    $stmt->execute([
        'g' => $guestId,
        'b' => $bookingId,
        't' => $type,
        'k' => $key,
        'm' => mb_substr($message, 0, 400),
    ]);
    return $stmt->rowCount() === 1;
}

function bm_bn_confirmation_message(string $reference, string $roomName, string $checkIn, string $checkOut): string
{
    return 'Booking confirmed! Reference no. ' . $reference . ' · ' . $roomName
         . ' · ' . bm_bn_date_range($checkIn, $checkOut) . '.';
}

/* ------------------------------------------------------------------
   1. Confirmation — called right after a booking is saved
------------------------------------------------------------------ */
function bm_bn_notify_confirmation(PDO $pdo, int $bookingId): bool
{
    bm_bn_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        "SELECT b.id, b.guest_id, b.reference, b.check_in, b.check_out, a.name AS room_name
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.id = :id AND b.guest_id IS NOT NULL AND b.status = 'confirmed'
         LIMIT 1"
    );
    $stmt->execute(['id' => $bookingId]);
    $b = $stmt->fetch();
    if (!$b) {
        return false;
    }

    return bm_bn_insert(
        $pdo,
        (int) $b['guest_id'],
        (int) $b['id'],
        'booking_confirmed',
        'confirmed',
        bm_bn_confirmation_message($b['reference'], $b['room_name'], $b['check_in'], $b['check_out'])
    );
}

/* ------------------------------------------------------------------
   2 + 3. Reminders (plus safety-net confirmations and cleanup)
   $guestId = null means "all guests" (handy for a cron job).
   Returns ['confirmed' => int, 'week' => int, 'night' => int, 'removed' => int]
------------------------------------------------------------------ */
function bm_bn_sync(PDO $pdo, ?int $guestId = null): array
{
    bm_bn_ensure_schema($pdo);

    $result = ['confirmed' => 0, 'week' => 0, 'night' => 0, 'removed' => 0];

    $tz      = new DateTimeZone(BM_BN_TIMEZONE);
    $now     = new DateTimeImmutable('now', $tz);
    $today   = $now->setTime(0, 0, 0);
    $nowTs   = $now->getTimestamp();
    $horizon = $today->modify('+' . (int) BM_BN_WEEK_BEFORE_DAYS . ' days');

    $gidSql    = $guestId !== null ? ' AND b.guest_id = :gid' : '';
    $gidParams = $guestId !== null ? ['gid' => $guestId] : [];

    /* ---- cleanup: unread reminders for cancelled bookings or an old check-in date */
    $clean = $pdo->prepare(
        "DELETE n FROM guest_notifications n
         JOIN bookings b ON b.id = n.booking_id
         WHERE n.type IN ('booking_reminder_week', 'booking_reminder_night')
           AND n.is_read = 0
           AND (b.status = 'cancelled'
                OR SUBSTRING_INDEX(n.remind_key, ':', -1) <> DATE_FORMAT(b.check_in, '%Y-%m-%d'))"
        . ($guestId !== null ? ' AND n.guest_id = :gid' : '')
    );
    $clean->execute($gidParams);
    $result['removed'] = $clean->rowCount();

    /* ---- safety net: confirmed upcoming bookings that never got a confirmation */
    $missing = $pdo->prepare(
        "SELECT b.id, b.guest_id, b.reference, b.check_in, b.check_out, a.name AS room_name
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         LEFT JOIN guest_notifications n ON n.booking_id = b.id AND n.type = 'booking_confirmed'
         WHERE b.guest_id IS NOT NULL
           AND b.status = 'confirmed'
           AND b.check_in >= :today
           AND n.id IS NULL" . $gidSql
    );
    $missing->execute(['today' => $today->format('Y-m-d')] + $gidParams);
    foreach ($missing->fetchAll() as $b) {
        if (bm_bn_insert(
            $pdo,
            (int) $b['guest_id'],
            (int) $b['id'],
            'booking_confirmed',
            'confirmed',
            bm_bn_confirmation_message($b['reference'], $b['room_name'], $b['check_in'], $b['check_out'])
        )) {
            $result['confirmed']++;
        }
    }

    /* ---- reminders: confirmed bookings checking in within the next 7 days */
    $stmt = $pdo->prepare(
        "SELECT b.id, b.guest_id, b.reference, b.check_in, b.check_out, b.created_at,
                NOW() AS db_now, a.name AS room_name
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         WHERE b.guest_id IS NOT NULL
           AND b.status = 'confirmed'
           AND b.check_in >= :today
           AND b.check_in <= :horizon" . $gidSql
    );
    $stmt->execute([
        'today'   => $today->format('Y-m-d'),
        'horizon' => $horizon->format('Y-m-d'),
    ] + $gidParams);

    foreach ($stmt->fetchAll() as $b) {
        $checkIn   = new DateTimeImmutable($b['check_in'] . ' 00:00:00', $tz);
        $daysUntil = (int) $today->diff($checkIn)->format('%r%a');
        $nice      = $checkIn->format('D, M j, Y');

        $weekDueTs  = $checkIn->modify('-' . (int) BM_BN_WEEK_BEFORE_DAYS . ' days')->getTimestamp();
        $nightDueTs = $checkIn->modify('-1 day')->setTime((int) BM_BN_NIGHT_BEFORE_HOUR, 0, 0)->getTimestamp();

        // When the booking was made, expressed on the PHP clock (independent of
        // the database server's timezone).
        $createdTs = $nowTs - (strtotime($b['db_now']) - strtotime($b['created_at']));

        $bid = (int) $b['id'];
        $gid = (int) $b['guest_id'];

        // 1 week before (only while there are still 2+ days to go; the night-before
        // reminder takes over after that)
        if ($daysUntil >= 2 && $nowTs >= $weekDueTs && $createdTs <= $weekDueTs) {
            $msg = 'Reminder: your stay at ' . $b['room_name'] . ' starts in ' . $daysUntil . ' days ('
                 . $nice . '). Reference no. ' . $b['reference'] . '.';
            if (bm_bn_insert($pdo, $gid, $bid, 'booking_reminder_week', 'week:' . $b['check_in'], $msg)) {
                $result['week']++;
            }
        }

        // 1 night before
        if ($nowTs >= $nightDueTs && $createdTs <= $nightDueTs) {
            $when = $daysUntil <= 0 ? 'today' : 'tomorrow';
            $msg  = 'Check-in ' . $when . '! ' . $b['room_name'] . ' · ' . $nice
                  . '. Reference no. ' . $b['reference'] . '. See you soon!';
            if (bm_bn_insert($pdo, $gid, $bid, 'booking_reminder_night', 'night:' . $b['check_in'], $msg)) {
                $result['night']++;
            }
        }
    }

    return $result;
}