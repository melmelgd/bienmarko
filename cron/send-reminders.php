<?php
/**
 * cron/send-reminders.php
 * -----------------------------------------------------------------------
 * Sends TWO reminders for every confirmed booking:
 *
 *   1. "Coming up"   - N days before check-in (N = reminder_days_before in
 *                      includes/gmail-config.php, default 1 = the day before).
 *                      Kind: 'reminder'.
 *   2. "Check-in today" - on the morning of the check-in date itself
 *                      (turn off with 'reminder_day_of' => false).
 *                      Kind: 'reminder_today'.
 *
 * Each reminder goes out by EMAIL (Gmail), SMS (iProg, includes/sms-config.php)
 * and the registered guest's dashboard bell. SMS + bell work even when Gmail
 * is not connected.
 *
 * Every booking gets each reminder only ONCE per channel (failed attempts
 * retry, max 3), so it is safe - and recommended - to run this more than once
 * a day. A booking made AFTER the morning run is picked up by the next run.
 *
 * RECOMMENDED SCHEDULE (Manila time): twice a day
 *     08:00  - day-of reminders + "coming up" reminders
 *     18:00  - catches bookings made during the day for tomorrow
 *   crontab:   0 8,18 * * * php /path/to/Bookingsystem/cron/send-reminders.php
 *
 * The day-of reminder is only sent BEFORE 'reminder_day_of_until_hour'
 * (default 12:00 noon), so the 18:00 run never texts "check-in today" to a
 * guest who has already arrived. Add --force to ignore that cut-off.
 *
 *   Command line (XAMPP / most hosts):
 *     php /path/to/Bookingsystem/cron/send-reminders.php
 *     php /path/to/Bookingsystem/cron/send-reminders.php --days=2
 *     php /path/to/Bookingsystem/cron/send-reminders.php --dry-run
 *     php /path/to/Bookingsystem/cron/send-reminders.php --force
 *
 *   By URL (needs "cron_key" set in includes/gmail-config.php):
 *     https://YOUR-DOMAIN/cron/send-reminders.php?key=YOUR_CRON_KEY
 *     (add &dry=1 to preview without sending, &force=1 to ignore the cut-off)
 * -----------------------------------------------------------------------
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/../includes/gmail-mailer.php';
$cfg = bienmarko_gmail_config();

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $want = (string) $cfg['cron_key'];
    $got  = (string) ($_GET['key'] ?? '');
    if ($want === '' || $got === '' || !hash_equals($want, $got)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

// The pension house is in the Philippines — "tomorrow" must mean Manila's tomorrow.
date_default_timezone_set('Asia/Manila');

// Command-line runs have no HTTP_HOST; if includes/db.php picks local vs
// live credentials from the host name, give it the live host from site_url.
if ($isCli && empty($_SERVER['HTTP_HOST'])) {
    $siteHost = parse_url((string) $cfg['site_url'], PHP_URL_HOST);
    if (is_string($siteHost) && $siteHost !== '') {
        $_SERVER['HTTP_HOST']   = $siteHost;
        $_SERVER['SERVER_NAME'] = $siteHost;
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking-emails.php';
require_once __DIR__ . '/../includes/booking-sms.php';

// Options
$days   = max(0, (int) ($cfg['reminder_days_before'] ?? 1));
$dryRun = false;
$force  = false;
if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif ($arg === '--force') {
            $force = true;
        } elseif (preg_match('/^--days=(\d{1,2})$/', $arg, $m)) {
            $days = (int) $m[1];
        }
    }
} else {
    $dryRun = !empty($_GET['dry']);
    $force  = !empty($_GET['force']);
    if (isset($_GET['days']) && preg_match('/^\d{1,2}$/', (string) $_GET['days'])) {
        $days = (int) $_GET['days'];
    }
}

$say = static function (string $line): void {
    echo $line . "\n";
};

try {
    $pdo = bienmarko_db();
} catch (Throwable $e) {
    $say('Database connection failed: ' . $e->getMessage());
    $say('If this only fails from the command line, run the job by URL instead (set "cron_key" in includes/gmail-config.php).');
    exit(1);
}

/* ---- which reminders run this time ------------------------------------ */
$passes = [
    ['kind' => 'reminder', 'days' => $days, 'title' => $days === 0 ? 'Check-in today' : "Coming up ({$days} day" . ($days === 1 ? '' : 's') . ' before)'],
];

$dayOfOn   = !empty($cfg['reminder_day_of']);
$cutoff    = (int) ($cfg['reminder_day_of_until_hour'] ?? 12);
$hourNow   = (int) date('G');
$dayOfNote = '';
if ($days === 0) {
    $dayOfNote = ''; // the single pass above already is the day-of reminder
} elseif (!$dayOfOn) {
    $dayOfNote = 'Day-of reminder is switched off (reminder_day_of = false).';
} elseif (!$force && $hourNow >= $cutoff) {
    $dayOfNote = "Day-of reminder skipped: it only goes out before {$cutoff}:00 (use --force to override).";
} else {
    $passes[] = ['kind' => 'reminder_today', 'days' => 0, 'title' => 'Check-in today'];
}

$say('[' . date('Y-m-d H:i:s') . '] Reminder run' . ($dryRun ? ' (dry run)' : ''));
if ($dayOfNote !== '') {
    $say($dayOfNote);
}

$status  = bienmarko_gmail_status($pdo);
$gmailOk = (bool) $status['connected'];
if (!$gmailOk) {
    $say('Gmail is not connected (' . $status['status'] . '). No emails will be sent. Connect it in Settings → Email (Gmail).');
}

$totalSent = 0;
$totalFailed = 0;
$smsFailed = 0;

foreach ($passes as $pass) {
    $kind   = $pass['kind'];
    $target = date('Y-m-d', strtotime('+' . (int) $pass['days'] . ' day'));
    $say('');
    $say("== {$pass['title']} — check-in date {$target} ({$kind}) ==");

    /* ---- SMS + dashboard bell (independent of Gmail) ------------------ */
    $smsIds = bienmarko_reminder_booking_ids($pdo, $target);
    $say(count($smsIds) . ' booking(s) checking in on ' . $target . ' (SMS / dashboard)'
       . (bienmarko_sms_is_ready() ? '.' : ' - SMS is not set up, dashboard notices only.'));
    foreach ($smsIds as $id) {
        if ($dryRun) {
            $say("  would text/notify: #{$id}");
            continue;
        }
        $r   = bienmarko_notify_booking($pdo, $id, $kind);
        $sms = $r['sms'];
        if (in_array($sms['status'], ['sent', 'duplicate', 'off'], true)) {
            $say("  #{$id}: SMS {$sms['status']}" . ($r['app'] ? ', dashboard notified' : ''));
        } else {
            if ($sms['status'] === 'failed') {
                $smsFailed++;
            }
            $say("  #{$id}: SMS {$sms['status']} - {$sms['error']}" . ($r['app'] ? ' (dashboard notified)' : ''));
        }
    }

    /* ---- Email --------------------------------------------------------- */
    if (!$gmailOk) {
        continue;
    }

    $ids = bienmarko_email_due_reminders($pdo, $target, $kind);
    $say(count($ids) . ' email(s) due.');

    foreach ($ids as $id) {
        $b = bienmarko_email_load_booking($pdo, $id);
        $label = $b ? ($b['reference'] . ' → ' . $b['recipient']) : "#{$id}";

        if ($dryRun) {
            $say("  would send: {$label}");
            continue;
        }

        $res = bienmarko_email_send_booking($pdo, $id, $kind);
        if ($res['ok']) {
            $totalSent++;
            $say("  sent:   {$label}");
        } else {
            $totalFailed++;
            $say("  FAILED: {$label} — {$res['error']}");
            // A broken connection won't fix itself mid-run; stop hammering Google.
            if (!bienmarko_gmail_status($pdo)['connected']) {
                $say('Gmail connection lost — no more emails this run.');
                $gmailOk = false;
                break;
            }
        }
    }
}

$say('');
$say($dryRun ? 'Dry run complete.' : "Done. Emails sent {$totalSent}, failed {$totalFailed}. SMS failed {$smsFailed}.");
exit(($totalFailed + $smsFailed) > 0 ? 2 : 0);
