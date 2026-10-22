<?php
/**
 * user/includes/feedback-reminders.php
 *
 * Reminds guests to leave feedback for a COMPLETED booking, in three places:
 *   1. Dashboard banner            -> bm_fb_render_banner()
 *   2. Bell / dashboard notifs     -> guest_notifications row (type 'feedback_reminder')
 *   3. Email (Gmail etc.)          -> bm_fb_send_reminder_email()
 *
 * Every reminder is sent ONCE per booking (tracked in `feedback_reminders`)
 * and stops as soon as the guest submits feedback for that booking.
 *
 * Triggered two ways:
 *   - lazily, whenever the guest opens any user page (user/includes/bootstrap.php)
 *   - by cron for ALL guests, even ones who never log in
 *     (user/cron/send-feedback-reminders.php)
 */

/* ------------------------------------------------------------------
   SETTINGS — edit these
------------------------------------------------------------------ */

// Public URL of the guest area, no trailing slash. Needed for the button in
// the email when run from cron. Over the web it is auto-detected if left empty.
if (!defined('BM_FB_SITE_URL'))  define('BM_FB_SITE_URL', ''); // e.g. 'https://bienmarko.com/user'

// Only notify/email for stays that ended within this many days (stops old
// completed bookings from flooding guests the first time this goes live).
if (!defined('BM_FB_REMIND_WITHIN_DAYS')) define('BM_FB_REMIND_WITHIN_DAYS', 30);

// Gmail SMTP (needs PHPMailer in /vendor). Use a Gmail "App password",
// NOT your normal Gmail password: Google Account > Security > App passwords.
if (!defined('BM_FB_SMTP_HOST')) define('BM_FB_SMTP_HOST', 'smtp.gmail.com');
if (!defined('BM_FB_SMTP_PORT')) define('BM_FB_SMTP_PORT', 587);
if (!defined('BM_FB_SMTP_USER')) define('BM_FB_SMTP_USER', getenv('BM_SMTP_USER') ?: ''); // yourhotel@gmail.com
if (!defined('BM_FB_SMTP_PASS')) define('BM_FB_SMTP_PASS', getenv('BM_SMTP_PASS') ?: ''); // 16-char app password
if (!defined('BM_FB_FROM_NAME')) define('BM_FB_FROM_NAME', "Bien Marko's Pension House");

/* ------------------------------------------------------------------
   Schema
------------------------------------------------------------------ */
function bm_fb_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Same definition used by user/feedback.php and user/api/feedback.php.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `feedback` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `guest_id`     INT UNSIGNED NOT NULL,
            `rating`       TINYINT UNSIGNED NOT NULL,
            `category`     ENUM('general','room','service','cleanliness','other') NOT NULL DEFAULT 'general',
            `message`      TEXT NOT NULL,
            `is_read`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_guest`     (`guest_id`),
            KEY `idx_created`   (`created_at`),
            KEY `idx_read`      (`is_read`),
            KEY `idx_published` (`is_published`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `is_published` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_read`");
    $pdo->exec("ALTER TABLE `feedback` ADD COLUMN IF NOT EXISTS `booking_id` INT UNSIGNED NULL DEFAULT NULL AFTER `guest_id`");
    $pdo->exec("ALTER TABLE `feedback` ADD UNIQUE INDEX IF NOT EXISTS `uq_feedback_booking` (`booking_id`)");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `feedback_reminders` (
            `booking_id`     INT UNSIGNED NOT NULL,
            `notified_at`    DATETIME NULL DEFAULT NULL,
            `emailed_at`     DATETIME NULL DEFAULT NULL,
            `email_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`booking_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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
}

/* ------------------------------------------------------------------
   Query: completed bookings that still have no feedback
   $guestId = null means "all guests" (used by cron).
------------------------------------------------------------------ */
function bm_fb_unreviewed_stays(PDO $pdo, ?int $guestId = null, ?int $withinDays = null): array
{
    bm_fb_ensure_schema($pdo);
    require_once __DIR__ . '/booking-stay.php';

    $sql = "SELECT b.id, b.guest_id, b.reference, b.check_in, b.check_out,
                   a.name AS room_name, g.first_name, g.email AS guest_email
            FROM bookings b
            JOIN accommodations a ON a.id = b.accommodation_id
            JOIN guests g         ON g.id = b.guest_id
            LEFT JOIN feedback f  ON f.booking_id = b.id
            WHERE " . bk_completed_sql($pdo, 'b') . "
              AND f.id IS NULL";
    $params = [];

    if ($guestId !== null) {
        $sql .= ' AND b.guest_id = :gid';
        $params['gid'] = $guestId;
    }
    if ($withinDays !== null) {
        $sql .= ' AND b.check_out >= DATE_SUB(CURDATE(), INTERVAL ' . (int) $withinDays . ' DAY)';
    }
    $sql .= ' ORDER BY b.check_out DESC, b.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------------
   Email
------------------------------------------------------------------ */
function bm_fb_site_url(): string
{
    if (BM_FB_SITE_URL !== '') {
        return rtrim(BM_FB_SITE_URL, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return '';
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $folder = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return ($https ? 'https://' : 'http://') . $host . $folder;
}

/**
 * Sends one HTML email. Uses PHPMailer + Gmail SMTP when it is installed and
 * BM_FB_SMTP_USER / BM_FB_SMTP_PASS are filled in, otherwise falls back to
 * PHP's mail(). Returns true on success.
 */
function bm_fb_send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer') && BM_FB_SMTP_USER !== '' && BM_FB_SMTP_PASS !== '') {
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = BM_FB_SMTP_HOST;
            $m->SMTPAuth   = true;
            $m->Username   = BM_FB_SMTP_USER;
            $m->Password   = BM_FB_SMTP_PASS;
            $m->SMTPSecure = 'tls';
            $m->Port       = (int) BM_FB_SMTP_PORT;
            $m->Timeout    = 10;
            $m->CharSet    = 'UTF-8';
            $m->setFrom(BM_FB_SMTP_USER, BM_FB_FROM_NAME);
            $m->addAddress($toEmail, $toName);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = $text;
            return $m->send();
        } catch (\Throwable $e) {
            error_log('[feedback-reminder] SMTP failed: ' . $e->getMessage());
            return false;
        }
    }

    $from    = BM_FB_SMTP_USER !== '' ? BM_FB_SMTP_USER : 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . 'From: ' . mb_encode_mimeheader(BM_FB_FROM_NAME) . " <{$from}>\r\n";
    return @mail($toEmail, mb_encode_mimeheader($subject), $html, $headers);
}

function bm_fb_send_reminder_email(array $stay): bool
{
    $first  = trim((string) ($stay['first_name'] ?? '')) ?: 'Guest';
    $room   = (string) $stay['room_name'];
    $ref    = (string) $stay['reference'];
    $dates  = date('M j', strtotime($stay['check_in'])) . ' - ' . date('M j, Y', strtotime($stay['check_out']));
    $link   = bm_fb_site_url() . '/feedback.php?booking=' . (int) $stay['id'];
    $hotel  = BM_FB_FROM_NAME;

    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $subject = "How was your stay at {$hotel}?";
    $text    = "Hi {$first},\n\nThank you for staying with us ({$room}, {$dates}, booking {$ref}).\n"
             . "We'd love to hear how it went. It only takes a minute:\n{$link}\n\n- {$hotel}";
    $html    = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#222;line-height:1.5">'
             . '<h2 style="margin:0 0 12px">How was your stay, ' . $e($first) . '?</h2>'
             . '<p>Thank you for staying with us at <strong>' . $e($hotel) . '</strong>.</p>'
             . '<p style="background:#f6f1e7;border-radius:8px;padding:12px 14px;margin:16px 0">'
             . '<strong>' . $e($room) . '</strong><br>' . $e($dates) . '<br>Booking ' . $e($ref) . '</p>'
             . '<p>We&rsquo;d love to hear how it went &mdash; it only takes a minute.</p>'
             . '<p style="margin:24px 0"><a href="' . $e($link) . '" style="background:#e8a33d;color:#1b1b1b;text-decoration:none;'
             . 'font-weight:bold;padding:12px 22px;border-radius:8px;display:inline-block">Leave my feedback</a></p>'
             . '<p style="font-size:12px;color:#777">If the button does not work, copy this link:<br>' . $e($link) . '</p>'
             . '</div>';

    return bm_fb_send_mail((string) $stay['guest_email'], $first, $subject, $html, $text);
}

/* ------------------------------------------------------------------
   Sync: create the notification + send the email, once per booking
   Returns ['notified' => int, 'emailed' => int]
------------------------------------------------------------------ */
function bm_fb_sync_reminders(PDO $pdo, ?int $guestId = null, bool $sendEmails = true, bool $deferEmails = false): array
{
    $result = ['notified' => 0, 'emailed' => 0];

    $stays = bm_fb_unreviewed_stays($pdo, $guestId, (int) BM_FB_REMIND_WITHIN_DAYS);
    $emailJobs = [];

    foreach ($stays as $stay) {
        $bid = (int) $stay['id'];

        $pdo->prepare('INSERT IGNORE INTO feedback_reminders (booking_id) VALUES (:b)')->execute(['b' => $bid]);

        // ---- dashboard / bell notification (claimed atomically so it is created once)
        $claim = $pdo->prepare('UPDATE feedback_reminders SET notified_at = NOW() WHERE booking_id = :b AND notified_at IS NULL');
        $claim->execute(['b' => $bid]);
        if ($claim->rowCount() === 1) {
            $pdo->prepare(
                "INSERT INTO guest_notifications (guest_id, booking_id, type, message)
                 VALUES (:g, :b, 'feedback_reminder', :m)"
            )->execute([
                'g' => (int) $stay['guest_id'],
                'b' => $bid,
                'm' => mb_substr('How was your stay in ' . $stay['room_name'] . '? Tap to leave your feedback.', 0, 400),
            ]);
            $result['notified']++;
        }

        // ---- email (max 3 attempts, claimed atomically so it is sent once)
        if ($sendEmails && filter_var($stay['guest_email'], FILTER_VALIDATE_EMAIL)) {
            $claim = $pdo->prepare(
                'UPDATE feedback_reminders
                    SET emailed_at = NOW(), email_attempts = email_attempts + 1
                  WHERE booking_id = :b AND emailed_at IS NULL AND email_attempts < 3'
            );
            $claim->execute(['b' => $bid]);
            if ($claim->rowCount() === 1) {
                $emailJobs[] = $stay;
            }
        }
    }

    $run = static function () use ($pdo, $emailJobs, &$result): void {
        foreach ($emailJobs as $stay) {
            if (bm_fb_send_reminder_email($stay)) {
                $result['emailed']++;
            } else {
                // release the claim so the next run can retry (attempt count is kept)
                $pdo->prepare('UPDATE feedback_reminders SET emailed_at = NULL WHERE booking_id = :b')
                    ->execute(['b' => (int) $stay['id']]);
            }
        }
    };

    if ($emailJobs) {
        if ($deferEmails) {
            // Web request: send after the page has been delivered so SMTP never slows the page.
            register_shutdown_function(static function () use ($run): void {
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $run();
            });
        } else {
            $run();
        }
    }

    return $result;
}

/* ------------------------------------------------------------------
   Dashboard banner
------------------------------------------------------------------ */
function bm_fb_render_banner(array $stays): void
{
    if (!$stays) {
        return;
    }
    static $styled = false;
    $first = $stays[0];
    $more  = count($stays) - 1;
    $dates = date('M j', strtotime($first['check_in'])) . '&ndash;' . date('M j, Y', strtotime($first['check_out']));
    ?>
    <?php if (!$styled): $styled = true; ?>
    <style>
    .fb-reminder {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      border: 1px solid var(--ember); background: rgba(232, 163, 61, 0.12);
      border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 18px;
    }
    .fb-reminder__icon { width: 22px; height: 22px; flex: none; color: var(--ember); }
    .fb-reminder__text { flex: 1 1 220px; font-size: 14px; line-height: 1.45; color: var(--paper-dim); }
    .fb-reminder__text strong { color: var(--ember); }
    </style>
    <?php endif; ?>
    <div class="fb-reminder" role="status">
        <span class="fb-reminder__icon"><?php render_icon('message-square'); ?></span>
        <p class="fb-reminder__text">
            <strong>How was your stay?</strong>
            Your <?php echo htmlspecialchars($first['room_name']); ?> booking
            (<?php echo htmlspecialchars($first['reference']); ?>, <?php echo $dates; ?>) is complete &mdash;
            please share your feedback.
            <?php if ($more > 0): ?>
                You have <?php echo (int) $more; ?> more stay<?php echo $more === 1 ? '' : 's'; ?> to review.
            <?php endif; ?>
        </p>
        <a class="btn btn--primary" href="feedback.php?booking=<?php echo (int) $first['id']; ?>">Leave feedback</a>
    </div>
    <?php
}