<?php
/**
 * includes/booking-sms.php
 * -----------------------------------------------------------------------
 * Companion to includes/booking-emails.php. Whatever the system emails a
 * guest through Gmail, this file ALSO:
 *
 *   1. texts the guest through iProg SMS (same gateway as forgot-password.php)
 *   2. drops the same update into the registered guest's dashboard bell
 *      (guest_notifications - the table the user portal already reads)
 *
 *   kind          email sent by                          SMS   dashboard bell
 *   ------------  -------------------------------------  ----  ---------------------
 *   confirmation  booking made (guest / walk-in / admin)  yes   yes (registered only)
 *   modified      booking edited (guest or admin)         yes   yes
 *   cancelled     booking cancelled (guest or admin)      yes   yes
 *   receipt       booking fully paid                      yes   (already pushed by payments API)
 *   reminder      cron/send-reminders.php (N days before) yes   yes
 *   reminder_today cron/send-reminders.php (check-in day) yes   yes
 *   feedback      "How was your stay?" email              yes   (already created by feedback-reminders.php)
 *
 * Guests who booked WITHOUT an account have no dashboard, so they only get
 * the SMS (and the email, as before).
 *
 * Everything here is non-fatal: a failed text never breaks a booking, and
 * every text is logged in the `sms_log` table (created automatically) so the
 * same message is never sent twice for the same event.
 *
 * Main entry points:
 *   bienmarko_notify_booking($pdo, $bookingId, $kind)      SMS + dashboard bell
 *   bienmarko_sms_send_booking($pdo, $bookingId, $kind)    SMS only
 *   bienmarko_guest_notify_booking($pdo, $bookingId, $kind) dashboard bell only
 * -----------------------------------------------------------------------
 */

/* ------------------------------------------------------------------
   Config
------------------------------------------------------------------ */
function bienmarko_sms_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [
        'enabled'    => true,
        'api_token'  => (string) (getenv('IPROG_API_TOKEN') ?: ''),
        'api_url'    => 'https://sms.iprogtech.com/api/v1/sms_messages',
        'verify_ssl' => true,
        'timeout'    => 15,
        'site_name'  => '',
    ];
    $file = __DIR__ . '/sms-config.php';
    if (is_file($file)) {
        $user = include $file;
        if (is_array($user)) {
            $cfg = array_merge($cfg, $user);
        }
    }
    return $cfg;
}

/** True when SMS is switched on and an API token has been filled in. */
function bienmarko_sms_is_ready(): bool
{
    $cfg   = bienmarko_sms_config();
    $token = trim((string) $cfg['api_token']);
    return !empty($cfg['enabled'])
        && $token !== ''
        && $token !== 'PASTE_YOUR_IPROG_API_TOKEN_HERE'
        && function_exists('curl_init');
}

function bienmarko_sms_site_name(): string
{
    static $name = null;
    if ($name !== null) {
        return $name;
    }
    $name = '';
    try {
        $cfg = bienmarko_sms_config();
        if (trim((string) $cfg['site_name']) !== '') {
            $name = trim((string) $cfg['site_name']);
        } else {
            if (!function_exists('bienmarko_site_name') && is_file(__DIR__ . '/site-settings.php')) {
                require_once __DIR__ . '/site-settings.php';
            }
            if (function_exists('bienmarko_site_name')) {
                $name = trim((string) bienmarko_site_name());
            }
        }
    } catch (Throwable $e) {
        $name = '';
    }
    if ($name === '') {
        $name = "Bien Marko's Pension House";
    }
    return $name;
}

/* ------------------------------------------------------------------
   Phone + sending
------------------------------------------------------------------ */

/** Philippine mobile -> '+639XXXXXXXXX' (same rules as forgot-password.php), or null. */
function bienmarko_sms_normalize_phone(?string $raw): ?string
{
    $d = preg_replace('/\D/', '', (string) $raw);
    if (preg_match('/^63(9\d{9})$/', $d, $m)
        || preg_match('/^0(9\d{9})$/', $d, $m)
        || preg_match('/^(9\d{9})$/', $d, $m)) {
        return '+63' . $m[1];
    }
    return null;
}

/** Keeps texts in plain GSM characters so they are not split into tiny 70-character parts. */
function bienmarko_sms_clean(string $s): string
{
    $s = strtr($s, [
        "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{00B7}" => '|', "\u{20B1}" => 'PHP ',
        "\u{2026}" => '...', "\u{00A0}" => ' ',
    ]);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Sends one SMS through iProg. Returns ['ok' => bool, 'error' => string].
 * Same request/response handling as fp_send_sms() in forgot-password.php.
 */
function bienmarko_sms_send(string $e164, string $message): array
{
    if (!bienmarko_sms_is_ready()) {
        return ['ok' => false, 'error' => 'SMS is not set up (add the iProg token in includes/sms-config.php).'];
    }
    $cfg = bienmarko_sms_config();

    $ch = curl_init((string) $cfg['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'api_token'    => (string) $cfg['api_token'],
            'phone_number' => $e164,
            'message'      => $message,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(5, (int) $cfg['timeout']),
        CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_ssl']),
        CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_ssl']) ? 2 : 0,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http < 200 || $http >= 300) {
        $why = 'iProg HTTP ' . $http . ($err !== '' ? ' | cURL: ' . $err : '') . ' | ' . substr((string) $response, 0, 150);
        error_log('[booking-sms] ' . $why);
        return ['ok' => false, 'error' => $why];
    }
    $data = json_decode((string) $response, true);
    if (is_array($data) && isset($data['status']) && !in_array($data['status'], ['success', 200, '200'], true)) {
        $why = 'iProg rejected the message: ' . substr((string) $response, 0, 150);
        error_log('[booking-sms] ' . $why);
        return ['ok' => false, 'error' => $why];
    }
    return ['ok' => true, 'error' => ''];
}

/* ------------------------------------------------------------------
   Log table (additive + idempotent)
------------------------------------------------------------------ */
function bienmarko_sms_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `sms_log` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `booking_id` INT UNSIGNED NULL DEFAULT NULL,
            `guest_id`   INT UNSIGNED NULL DEFAULT NULL,
            `kind`       VARCHAR(30)  NOT NULL,
            `dedupe_key` VARCHAR(60)  NOT NULL DEFAULT '',
            `phone`      VARCHAR(20)  NULL DEFAULT NULL,
            `status`     VARCHAR(10)  NOT NULL,
            `error`      VARCHAR(255) NULL DEFAULT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_booking_kind` (`booking_id`, `kind`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/* ------------------------------------------------------------------
   Booking data + wording
------------------------------------------------------------------ */
function bienmarko_sms_load_booking(PDO $pdo, int $bookingId): ?array
{
    $st = $pdo->prepare(
        "SELECT b.id, b.guest_id, b.reference, b.guest_name, b.phone AS booking_phone,
                b.check_in, b.check_out, b.guests_count, b.status,
                a.name AS room_name, g.first_name, g.phone AS guest_phone
         FROM bookings b
         JOIN accommodations a ON a.id = b.accommodation_id
         LEFT JOIN guests g    ON g.id = b.guest_id
         WHERE b.id = :id
         LIMIT 1"
    );
    $st->execute(['id' => $bookingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bienmarko_sms_first_name(array $b): string
{
    $first = trim((string) ($b['first_name'] ?? ''));
    if ($first === '') {
        $parts = preg_split('/\s+/', trim((string) ($b['guest_name'] ?? ''))) ?: [];
        $first = (string) ($parts[0] ?? '');
    }
    return $first !== '' ? $first : 'Guest';
}

/** "Nov 5 - Nov 7, 2026" (ASCII hyphen for SMS) or "Nov 5 – Nov 7, 2026" for the dashboard. */
function bienmarko_sms_date_range(string $checkIn, string $checkOut, string $dash = '-'): string
{
    $in  = strtotime($checkIn);
    $out = strtotime($checkOut);
    if (!$in || !$out) {
        return trim($checkIn . ' ' . $dash . ' ' . $checkOut);
    }
    return date('M j', $in) . ' ' . $dash . ' ' . date('M j, Y', $out);
}

/** Whole days from today (Manila) to check-in: 0 = today, 1 = tomorrow. */
function bienmarko_sms_days_until(string $checkIn): int
{
    $tz = new DateTimeZone('Asia/Manila');
    $today = new DateTimeImmutable('today', $tz);
    $in    = new DateTimeImmutable($checkIn . ' 00:00:00', $tz);
    return (int) $today->diff($in)->format('%r%a');
}

function bienmarko_sms_compose(PDO $pdo, array $b, string $kind, array $extra = []): ?string
{
    $site  = bienmarko_sms_site_name();
    $first = bienmarko_sms_first_name($b);
    $ref   = (string) $b['reference'];
    $room  = (string) $b['room_name'];
    $dates = bienmarko_sms_date_range((string) $b['check_in'], (string) $b['check_out']);

    switch ($kind) {
        case 'confirmation':
            return "{$site}: Hi {$first}, booking confirmed! Ref {$ref}. {$room}, {$dates}. Show this ref at check-in. Thank you!";

        case 'modified':
            $n = (int) $b['guests_count'];
            return "{$site}: Hi {$first}, booking {$ref} was updated: {$room}, {$dates}, {$n} guest" . ($n === 1 ? '' : 's')
                 . ". Contact us if this is wrong.";

        case 'cancelled':
            return "{$site}: Hi {$first}, booking {$ref} ({$room}, {$dates}) has been cancelled. We hope to see you another time.";

        case 'receipt':
            $st = $pdo->prepare('SELECT total_paid, payment_status FROM payments WHERE booking_id = :b LIMIT 1');
            $st->execute(['b' => (int) $b['id']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p || ($p['payment_status'] ?? '') !== 'paid') {
                return null; // the receipt email is only sent once fully paid
            }
            return "{$site}: Hi {$first}, payment received for booking {$ref}. Total paid: PHP "
                 . number_format((float) $p['total_paid'], 2) . ". Thank you!";

        case 'reminder':
            $d = bienmarko_sms_days_until((string) $b['check_in']);
            if ($d < 0) {
                return null;
            }
            $when = $d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : 'in ' . $d . ' days (' . date('D, M j', strtotime((string) $b['check_in'])) . ')');
            return "{$site}: Reminder - your stay starts {$when}. {$room}, {$dates}. Ref {$ref}. See you soon!";

        case 'reminder_today':
            if (bienmarko_sms_days_until((string) $b['check_in']) !== 0) {
                return null; // only ever sent on the morning of check-in
            }
            return "{$site}: Check-in TODAY! Your stay starts today. {$room}, {$dates}. Ref {$ref}. Please show this ref at check-in. See you soon!";

        case 'feedback':
            $link = trim((string) ($extra['link'] ?? ''));
            return "{$site}: Hi {$first}, thanks for staying with us! How was it? "
                 . ($link !== '' ? "Share your feedback: {$link}" : 'Log in to your account to share your feedback.');
    }
    return null;
}

/* ------------------------------------------------------------------
   1. SMS
   Returns ['ok' => bool, 'status' => sent|failed|skipped|duplicate|off, 'error' => string]
------------------------------------------------------------------ */
function bienmarko_sms_send_booking(PDO $pdo, int $bookingId, string $kind, array $extra = []): array
{
    if (!bienmarko_sms_is_ready()) {
        return ['ok' => false, 'status' => 'off', 'error' => 'SMS is not set up (includes/sms-config.php).'];
    }
    bienmarko_sms_ensure_table($pdo);

    $b = bienmarko_sms_load_booking($pdo, $bookingId);
    if (!$b) {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Booking not found.'];
    }
    $status = (string) $b['status'];
    if ($kind !== 'cancelled' && $status === 'cancelled') {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Booking is cancelled.'];
    }
    if (in_array($kind, ['reminder', 'reminder_today'], true) && $status !== 'confirmed') {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Booking is not confirmed.'];
    }

    // The booking's own contact number first, then the account's number.
    $phone = bienmarko_sms_normalize_phone($b['booking_phone'] ?? null)
          ?? bienmarko_sms_normalize_phone($b['guest_phone'] ?? null);

    // What makes this "the same message" - so it is never sent twice.
    $key = '';
    if ($kind === 'reminder' || $kind === 'reminder_today') {
        $key = (string) $b['check_in'];
    } elseif ($kind === 'modified') {
        $key = date('YmdHi'); // each edit may text again, but a double-click will not
    }

    $guestId = $b['guest_id'] !== null ? (int) $b['guest_id'] : null;
    $log = static function (string $st, ?string $error, ?string $ph) use ($pdo, $bookingId, $guestId, $kind, $key): int {
        $ins = $pdo->prepare(
            'INSERT INTO sms_log (booking_id, guest_id, kind, dedupe_key, phone, status, error)
             VALUES (:b, :g, :k, :d, :p, :s, :e)'
        );
        $ins->execute([
            'b' => $bookingId, 'g' => $guestId, 'k' => $kind, 'd' => $key,
            'p' => $ph, 's' => $st, 'e' => $error !== null ? mb_substr($error, 0, 250) : null,
        ]);
        return (int) $pdo->lastInsertId();
    };

    if ($phone === null) {
        $log('skipped', 'No valid Philippine mobile number on the booking or account.', null);
        return ['ok' => false, 'status' => 'skipped', 'error' => 'No valid mobile number.'];
    }

    $message = bienmarko_sms_compose($pdo, $b, $kind, $extra);
    if ($message === null) {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Nothing to send for this booking right now.'];
    }
    $message = bienmarko_sms_clean($message);

    // Already sent / being sent / failed 3 times for this exact event?
    $chk = $pdo->prepare(
        "SELECT status, COUNT(*) AS c FROM sms_log
         WHERE booking_id = :b AND kind = :k AND dedupe_key = :d
           AND (status = 'sent' OR status = 'failed'
                OR (status = 'sending' AND created_at > (NOW() - INTERVAL 5 MINUTE)))
         GROUP BY status"
    );
    $chk->execute(['b' => $bookingId, 'k' => $kind, 'd' => $key]);
    $seen = [];
    foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $seen[$r['status']] = (int) $r['c'];
    }
    if (!empty($seen['sent']) || !empty($seen['sending'])) {
        return ['ok' => true, 'status' => 'duplicate', 'error' => 'Already sent.'];
    }
    if (($seen['failed'] ?? 0) >= 3) {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Gave up after 3 failed attempts.'];
    }

    $rowId = $log('sending', null, $phone);
    $res   = bienmarko_sms_send($phone, $message);
    $pdo->prepare('UPDATE sms_log SET status = :s, error = :e WHERE id = :id')->execute([
        's'  => $res['ok'] ? 'sent' : 'failed',
        'e'  => $res['ok'] ? null : mb_substr($res['error'], 0, 250),
        'id' => $rowId,
    ]);

    return $res['ok']
        ? ['ok' => true,  'status' => 'sent',   'error' => '']
        : ['ok' => false, 'status' => 'failed', 'error' => $res['error']];
}

/* ------------------------------------------------------------------
   2. Dashboard bell (guest_notifications) - registered guests only
------------------------------------------------------------------ */
function bienmarko_guest_notify_ensure_schema(PDO $pdo): void
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
    if (!$pdo->query("SHOW COLUMNS FROM `guest_notifications` LIKE 'remind_key'")->fetch()) {
        $pdo->exec("ALTER TABLE `guest_notifications` ADD COLUMN `remind_key` VARCHAR(40) NULL DEFAULT NULL AFTER `type`");
    }
    if (!$pdo->query("SHOW INDEX FROM `guest_notifications` WHERE Key_name = 'uq_booking_notice'")->fetch()) {
        $pdo->exec("ALTER TABLE `guest_notifications` ADD UNIQUE INDEX `uq_booking_notice` (`booking_id`, `type`, `remind_key`)");
    }
}

/**
 * Creates the dashboard notification for a booking event. Returns true only
 * when a NEW notification was created. 'receipt' and 'feedback' return false
 * on purpose: the payments API and feedback-reminders.php already create those.
 */
function bienmarko_guest_notify_booking(PDO $pdo, int $bookingId, string $kind): bool
{
    if (!in_array($kind, ['confirmation', 'modified', 'cancelled', 'reminder', 'reminder_today'], true)) {
        return false;
    }
    bienmarko_guest_notify_ensure_schema($pdo);

    $b = bienmarko_sms_load_booking($pdo, $bookingId);
    if (!$b || $b['guest_id'] === null) {
        return false; // booked without an account - no dashboard to notify
    }
    $status = (string) $b['status'];
    $ref    = (string) $b['reference'];
    $room   = (string) $b['room_name'];
    $range  = bienmarko_sms_date_range((string) $b['check_in'], (string) $b['check_out'], "\u{2013}");

    switch ($kind) {
        case 'confirmation':
            if ($status !== 'confirmed') {
                return false;
            }
            // Same type/key/wording as user/includes/booking-notifications.php, so the two never duplicate.
            $type = 'booking_confirmed';
            $rkey = 'confirmed';
            $msg  = 'Booking confirmed! Reference no. ' . $ref . ' · ' . $room . ' · ' . $range . '.';
            break;

        case 'modified':
            if ($status === 'cancelled') {
                return false;
            }
            $n    = (int) $b['guests_count'];
            $type = 'booking_modified';
            $rkey = 'modified:' . date('YmdHis');
            $msg  = 'Your booking ' . $ref . ' was updated: ' . $room . ' · ' . $range . ' · ' . $n . ' guest' . ($n === 1 ? '' : 's') . '.';
            break;

        case 'cancelled':
            // Pull back any unread "your stay is coming up" notice for this booking.
            $pdo->prepare("DELETE FROM guest_notifications WHERE booking_id = :b AND is_read = 0
                           AND type IN ('booking_reminder','booking_reminder_week','booking_reminder_night','booking_reminder_today')")
                ->execute(['b' => $bookingId]);
            $type = 'booking_cancelled';
            $rkey = 'cancelled';
            $msg  = 'Booking ' . $ref . ' (' . $room . ' · ' . $range . ') has been cancelled.';
            break;

        case 'reminder':
            if ($status !== 'confirmed') {
                return false;
            }
            $d    = bienmarko_sms_days_until((string) $b['check_in']);
            if ($d < 0) {
                return false;
            }
            $nice = date('D, M j, Y', strtotime((string) $b['check_in']));
            if ($d <= 1) {
                // Same type/key/wording as the existing "1 night before" bell notice.
                $type = 'booking_reminder_night';
                $rkey = 'night:' . $b['check_in'];
                $msg  = 'Check-in ' . ($d === 0 ? 'today' : 'tomorrow') . '! ' . $room . ' · ' . $nice
                      . '. Reference no. ' . $ref . '. See you soon!';
            } else {
                $type = $d === (defined('BM_BN_WEEK_BEFORE_DAYS') ? (int) BM_BN_WEEK_BEFORE_DAYS : 7)
                    ? 'booking_reminder_week' : 'booking_reminder';
                $rkey = ($type === 'booking_reminder_week' ? 'week:' : 'email:') . $b['check_in'];
                $msg  = 'Reminder: your stay at ' . $room . ' starts in ' . $d . ' days (' . $nice
                      . '). Reference no. ' . $ref . '.';
            }
            break;

        case 'reminder_today':
            if ($status !== 'confirmed' || bienmarko_sms_days_until((string) $b['check_in']) !== 0) {
                return false;
            }
            // Its own type/key (not the night-before one) so it still appears
            // on check-in day even though "check-in tomorrow" was shown yesterday.
            $type = 'booking_reminder_today';
            $rkey = 'today:' . $b['check_in'];
            $msg  = 'Check-in today! ' . $room . ' · ' . date('D, M j, Y', strtotime((string) $b['check_in']))
                  . '. Reference no. ' . $ref . '. See you soon!';
            break;

        default:
            return false;
    }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO guest_notifications (guest_id, booking_id, type, remind_key, message)
         VALUES (:g, :b, :t, :k, :m)'
    );
    $ins->execute([
        'g' => (int) $b['guest_id'], 'b' => $bookingId, 't' => $type, 'k' => $rkey,
        'm' => mb_substr($msg, 0, 400),
    ]);
    return $ins->rowCount() === 1;
}

/* ------------------------------------------------------------------
   3. One call for both - this is what the API files use
------------------------------------------------------------------ */
function bienmarko_notify_booking(PDO $pdo, int $bookingId, string $kind, array $extra = []): array
{
    $out = ['app' => false, 'sms' => ['ok' => false, 'status' => 'skipped', 'error' => '']];

    try {
        $out['app'] = bienmarko_guest_notify_booking($pdo, $bookingId, $kind);
    } catch (Throwable $e) {
        error_log('[booking-sms] dashboard notification failed: ' . $e->getMessage());
    }
    try {
        $out['sms'] = bienmarko_sms_send_booking($pdo, $bookingId, $kind, $extra);
    } catch (Throwable $e) {
        error_log('[booking-sms] SMS failed: ' . $e->getMessage());
        $out['sms'] = ['ok' => false, 'status' => 'failed', 'error' => $e->getMessage()];
    }
    return $out;
}

/** Confirmed bookings checking in on $date (Y-m-d) - what the daily reminder run works through. */
function bienmarko_reminder_booking_ids(PDO $pdo, string $date): array
{
    $st = $pdo->prepare("SELECT id FROM bookings WHERE status = 'confirmed' AND check_in = :d ORDER BY id");
    $st->execute(['d' => $date]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
