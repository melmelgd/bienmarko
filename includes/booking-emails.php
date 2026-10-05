<?php
/**
 * includes/booking-emails.php
 * -----------------------------------------------------------------------
 * Guest-facing booking emails (confirmation, update, cancellation,
 * reminder) built on includes/gmail-mailer.php.
 *
 * Call sites use:
 *
 *   bienmarko_email_after_response(static function () use ($pdo, $bookingId) {
 *       bienmarko_email_send_booking($pdo, $bookingId, 'confirmation');
 *   });
 *
 * which sends AFTER the JSON response has gone back to the browser, so a
 * slow or failing Gmail call can never delay or break a booking.
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/gmail-mailer.php';

/* ======================================================================
   Small helpers
   ====================================================================== */

function bienmarko_email_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bienmarko_email_date(string $ymd): string
{
    $t = strtotime($ymd);
    return $t ? date('D, M j, Y', $t) : $ymd;
}

function bienmarko_email_money($n): string
{
    return '₱' . number_format((float) $n, 2);
}

/**
 * Run $job after the response has been sent to the browser.
 * Uses fastcgi/litespeed_finish_request when the host provides it
 * (Hostinger does); otherwise the job simply runs at shutdown.
 */
function bienmarko_email_after_response(callable $job): void
{
    ignore_user_abort(true);

    register_shutdown_function(static function () use ($job): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // don't hold the session lock while talking to Google
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        @set_time_limit(60);
        try {
            $job();
        } catch (Throwable $e) {
            error_log('[bienmarko] after-response email job failed: ' . $e->getMessage());
        }
    });
}

/* ======================================================================
   Data
   ====================================================================== */

/**
 * Property name / contact details from Settings, with safe fallbacks.
 *
 * @return array{name:string,email:string,phone:string,address:string}
 */
function bienmarko_email_site_info(PDO $pdo): array
{
    $info = ['name' => "Bien Marko's Pension House", 'email' => '', 'phone' => '', 'address' => ''];

    try {
        $row = $pdo->query(
            'SELECT `property_name`, `contact_email`, `contact_number`, `address` FROM `system_settings` WHERE `id` = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if (trim((string) $row['property_name']) !== '') {
                $info['name'] = trim((string) $row['property_name']);
            }
            $info['email']   = trim((string) $row['contact_email']);
            $info['phone']   = trim((string) $row['contact_number']);
            $info['address'] = trim((string) $row['address']);
        }
    } catch (Throwable $e) {
        // settings table not created yet — defaults are fine
    }

    return $info;
}

/**
 * Everything the templates need for one booking. Registered guests have
 * their name/email on the `guests` row; walk-in and guest bookings carry
 * them on the booking itself — this reads whichever is filled in.
 *
 * @return array<string,mixed>|null
 */
function bienmarko_email_load_booking(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT b.id, b.reference, b.guest_id, b.check_in, b.check_out, b.guests_count,
                b.status, b.total_amount, b.payment_method, b.notes,
                COALESCE(NULLIF(TRIM(b.guest_name), ''),
                         NULLIF(TRIM(CONCAT_WS(' ', g.first_name, g.last_name)), ''),
                         'Guest')                                          AS guest_name,
                COALESCE(NULLIF(TRIM(b.email), ''), NULLIF(TRIM(g.email), ''), '') AS recipient,
                a.name AS room_name, a.type AS room_type
         FROM `bookings` b
         JOIN `accommodations` a ON a.id = b.accommodation_id
         LEFT JOIN `guests` g ON g.id = b.guest_id
         WHERE b.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Confirmed bookings checking in on $checkInDate that have an email address
 * and have not already been sent a reminder (failed attempts are retried,
 * up to 3 times).
 *
 * @return int[]
 */
function bienmarko_email_due_reminders(PDO $pdo, string $checkInDate): array
{
    bienmarko_gmail_ensure_tables($pdo);

    $stmt = $pdo->prepare(
        "SELECT b.id
         FROM `bookings` b
         LEFT JOIN `guests` g ON g.id = b.guest_id
         WHERE b.check_in = :d
           AND b.status = 'confirmed'
           AND COALESCE(NULLIF(TRIM(b.email), ''), NULLIF(TRIM(g.email), ''), '') <> ''
           AND NOT EXISTS (SELECT 1 FROM `email_log` l
                           WHERE l.booking_id = b.id AND l.kind = 'reminder' AND l.status = 'sent')
           AND (SELECT COUNT(*) FROM `email_log` l2
                WHERE l2.booking_id = b.id AND l2.kind = 'reminder' AND l2.status = 'failed') < 3
         ORDER BY b.id"
    );
    $stmt->execute(['d' => $checkInDate]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/* ======================================================================
   Rendering
   ====================================================================== */

/**
 * @param  array<string,mixed> $b     row from bienmarko_email_load_booking()
 * @param  array<string,string> $site row from bienmarko_email_site_info()
 * @return array{subject:string,html:string,text:string}
 */
function bienmarko_email_render(string $kind, array $b, array $site): array
{
    $prop  = $site['name'];
    $ref   = (string) $b['reference'];
    $first = trim((string) strtok((string) $b['guest_name'], ' '));
    $first = $first !== '' ? $first : 'there';

    $ciRaw  = (string) $b['check_in'];
    $coRaw  = (string) $b['check_out'];
    $ci     = bienmarko_email_date($ciRaw);
    $co     = bienmarko_email_date($coRaw);
    $nights = max(1, (int) round((strtotime($coRaw) - strtotime($ciRaw)) / 86400));

    $registered = !empty($b['guest_id']);
    $base       = bienmarko_gmail_site_base();

    $payLabel = strtolower((string) $b['payment_method']) === 'gcash' ? 'GCash' : 'Cash';

    $rows = [
        ['Reference number', $ref, true],
        ['Accommodation',    (string) $b['room_name'], false],
        ['Check-in',         $ci, false],
        ['Check-out',        $co, false],
        ['Nights',           (string) $nights, false],
        ['Guests',           (string) (int) $b['guests_count'], false],
        ['Total',            bienmarko_email_money($b['total_amount']), false],
        ['Payment',          $payLabel . ' — paid at the resort', false],
    ];
    $notes = trim((string) ($b['notes'] ?? ''));

    $cta = null;
    switch ($kind) {
        case 'modified':
            $eyebrow = 'BOOKING UPDATE';
            $subject = "Your booking was updated — {$ref}";
            $heading = 'Your booking was updated';
            $intro   = "Hi {$first}, your booking {$ref} at {$prop} has been updated. Here are the current details.";
            break;

        case 'cancelled':
            $eyebrow = 'CANCELLATION NOTICE';
            $subject = "Your booking was cancelled — {$ref}";
            $heading = 'Your booking was cancelled';
            $intro   = "Hi {$first}, booking {$ref} at {$prop} has been cancelled. If this wasn't expected, or you'd like to book again, please get in touch.";
            $rows    = [
                ['Reference number', $ref, true],
                ['Accommodation',    (string) $b['room_name'], false],
                ['Check-in',         $ci, false],
                ['Check-out',        $co, false],
            ];
            $notes = '';
            break;

        case 'reminder':
            $eyebrow = 'ARRIVAL REMINDER';
            $subject = 'Reminder: your stay starts ' . date('D, M j', (int) strtotime($ciRaw)) . " — {$ref}";
            $heading = 'Your stay is coming up';
            $intro   = "Hi {$first}, this is a friendly reminder that your stay at {$prop} starts on {$ci}. We look forward to seeing you!";
            break;

        case 'confirmation':
        default:
            $eyebrow = 'BOOKING CONFIRMATION';
            $subject = "Your booking is confirmed — {$ref}";
            $heading = 'Your booking is confirmed';
            $intro   = "Hi {$first}, thank you for booking with {$prop}. Your reservation is confirmed — please keep your reference number handy.";
            break;
    }

    // What to do next: dashboard for registered guests, contact for everyone else.
    $contactBits = [];
    if ($site['phone'] !== '') {
        $contactBits[] = $site['phone'];
    }
    if ($site['email'] !== '') {
        $contactBits[] = $site['email'];
    }
    $contactLine = $contactBits ? implode(' · ', $contactBits) : '';

    if ($kind === 'cancelled') {
        $next = $contactLine !== '' ? "Questions? Contact us: {$contactLine}" : '';
    } elseif ($registered) {
        $next = 'You can view, change or cancel this booking from your dashboard.';
        if ($base !== '') {
            $cta = ['label' => 'View my booking', 'url' => $base . '/user/bookings.php'];
        }
    } else {
        $next = 'Need to change or cancel? Please contact the resort directly'
            . ($contactLine !== '' ? ": {$contactLine}" : '.');
    }

    /* ---------- plain text ---------- */
    $t   = [];
    $t[] = $heading;
    $t[] = '';
    $t[] = $intro;
    $t[] = '';
    foreach ($rows as $r) {
        $t[] = str_pad($r[0] . ':', 18) . $r[1];
    }
    if ($notes !== '') {
        $t[] = str_pad('Special requests:', 18) . $notes;
    }
    if ($next !== '') {
        $t[] = '';
        $t[] = $next;
    }
    if ($cta) {
        $t[] = $cta['url'];
    }
    $t[] = '';
    $t[] = '— ' . $prop;
    if ($site['address'] !== '') {
        $t[] = $site['address'];
    }
    $text = implode("\n", $t);

    /* ---------- HTML ---------- */
    $e = 'bienmarko_email_esc';
    $rowHtml = '';
    foreach ($rows as $r) {
        if ($r[2]) {
            $rowHtml .= '<tr><td colspan="2" style="padding:0 0 16px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td style="padding:14px 16px;background:#f5f2e9;border:1px solid #e9e2d2;border-radius:8px;">'
                . '<div style="font-size:11px;line-height:1.4;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#66736d;">'
                . $e($r[0]) . '</div>'
                . '<div style="margin-top:5px;font-family:Consolas,Menlo,monospace;font-size:18px;line-height:1.4;font-weight:700;letter-spacing:1px;color:#755b26;">'
                . $e($r[1]) . '</div>'
                . '</td></tr></table></td></tr>';
            continue;
        }

        $rowHtml .= '<tr>'
            . '<td style="width:38%;padding:12px 10px 12px 0;border-bottom:1px solid #e9eeeb;font-size:13px;line-height:1.45;color:#68756f;vertical-align:top;">' . $e($r[0]) . '</td>'
            . '<td style="padding:12px 0;border-bottom:1px solid #e9eeeb;font-size:14px;line-height:1.45;font-weight:600;color:#26342e;vertical-align:top;word-break:break-word;">' . $e($r[1]) . '</td>'
            . '</tr>';
    }
    if ($notes !== '') {
        $rowHtml .= '<tr>'
            . '<td colspan="2" style="padding:14px 0 0;">'
            . '<div style="margin-bottom:5px;font-size:12px;line-height:1.4;font-weight:700;color:#68756f;">Special requests</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#26342e;">' . nl2br($e($notes)) . '</div>'
            . '</td>'
            . '</tr>';
    }

    $ctaHtml = '';
    if ($cta) {
        $ctaHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">'
            . '<tr><td bgcolor="#c5a15a" style="border-radius:7px;">'
            . '<a href="' . $e($cta['url']) . '" style="display:inline-block;padding:13px 20px;border:1px solid #b28d49;border-radius:7px;'
            . 'background:#c5a15a;color:#1d2d27;text-decoration:none;font-size:14px;line-height:1.2;font-weight:700;">'
            . $e($cta['label']) . '</a>'
            . '</td></tr></table>';
    }
    $nextHtml = $next !== ''
        ? '<p style="margin:18px 0 0;font-size:14px;line-height:1.65;color:#52615a;">' . $e($next) . '</p>'
        : '';

    $footerBits = [$e($prop)];
    if ($site['address'] !== '') {
        $footerBits[] = $e($site['address']);
    }
    if ($contactLine !== '') {
        $footerBits[] = $e($contactLine);
    }

    $preheader = $e($intro);
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f2f5f2;">'
        . '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#f2f5f2;">'
        . $preheader . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f2f5f2;">'
        . '<tr><td align="center" style="padding:30px 14px 20px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;font-family:Arial,Helvetica,sans-serif;">'
        .   '<tr><td style="padding:28px 30px 26px;background:#183d35;border-top:4px solid #c5a15a;border-radius:12px 12px 0 0;">'
        .     '<div style="font-size:12px;line-height:1.5;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:#f5f4ed;">' . $e($prop) . '</div>'
        .     '<div style="margin-top:16px;font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#e0c47f;">' . $e($eyebrow) . '</div>'
        .     '<div style="margin-top:5px;font-size:25px;line-height:1.25;font-weight:700;color:#ffffff;">' . $e($heading) . '</div>'
        .   '</td></tr>'
        .   '<tr><td style="padding:28px 30px 30px;background:#ffffff;border:1px solid #e5eae6;border-top:0;border-radius:0 0 12px 12px;">'
        .     '<p style="margin:0;font-size:15px;line-height:1.7;color:#3f4c46;">' . $e($intro) . '</p>'
        .     '<p style="margin:26px 0 10px;font-size:11px;line-height:1.4;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#68756f;">Reservation details</p>'
        .     '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">' . $rowHtml . '</table>'
        .     $ctaHtml . $nextHtml
        .   '</td></tr>'
        .   '<tr><td style="padding:18px 12px 0;text-align:center;font-size:12px;line-height:1.7;color:#7c8982;">'
        .     implode('<br>', $footerBits)
        .   '</td></tr>'
        . '</table>'
        . '</td></tr></table></body></html>';

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/* ======================================================================
   Sending
   ====================================================================== */

/**
 * Load a booking, render the email for $kind, send it and log the result.
 * $kind: 'confirmation' | 'modified' | 'cancelled' | 'reminder'.
 * Never throws.
 *
 * @return array{ok:bool,skipped:bool,error:string}
 */
function bienmarko_email_send_booking(PDO $pdo, int $bookingId, string $kind): array
{
    try {
        $b = bienmarko_email_load_booking($pdo, $bookingId);
        if (!$b) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Booking not found.'];
        }

        $to   = (string) $b['recipient'];
        $site = bienmarko_email_site_info($pdo);
        $mail = bienmarko_email_render($kind, $b, $site);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            bienmarko_email_log($pdo, $bookingId, $kind, $to, $mail['subject'], 'skipped', null, 'No valid guest email address on this booking.');
            return ['ok' => false, 'skipped' => true, 'error' => 'No valid guest email address.'];
        }

        $status = bienmarko_gmail_status($pdo);
        if (!$status['connected']) {
            $why = $status['status'] === 'needs_reauth'
                ? 'Gmail connection needs to be re-authorized.'
                : 'Gmail is not connected.';
            bienmarko_email_log($pdo, $bookingId, $kind, $to, $mail['subject'], 'skipped', null, $why);
            return ['ok' => false, 'skipped' => true, 'error' => $why];
        }

        $res = bienmarko_gmail_send($pdo, [
            'to'        => $to,
            'to_name'   => (string) $b['guest_name'],
            'subject'   => $mail['subject'],
            'html'      => $mail['html'],
            'text'      => $mail['text'],
            'reply_to'  => $site['email'],
            'from_name' => $site['name'],
        ]);

        bienmarko_email_log(
            $pdo,
            $bookingId,
            $kind,
            $to,
            $mail['subject'],
            $res['ok'] ? 'sent' : 'failed',
            $res['ok'] ? $res['id'] : null,
            $res['ok'] ? null : $res['error']
        );

        return ['ok' => $res['ok'], 'skipped' => false, 'error' => $res['error']];
    } catch (Throwable $e) {
        error_log('[bienmarko] booking email failed (' . $kind . ' #' . $bookingId . '): ' . $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
    }
}
